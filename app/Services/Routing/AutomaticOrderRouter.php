<?php

namespace App\Services\Routing;

use App\Exceptions\ShopifyApiException;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Activity\ActivityLogger;
use App\Services\Shopify\AdminGraphql;
use App\Services\Shopify\ShopifyCapacityReader;
use App\Services\Shopify\ShopifyFulfillmentMover;
use Illuminate\Support\Collection;

class AutomaticOrderRouter
{
    private const MAX_LINE_ITEMS = 20;

    private const MAX_INVENTORY_LEVELS = 20;

    private const REQUIRED_SCOPES = [
        'read_orders',
        'read_third_party_fulfillment_orders',
        'write_merchant_managed_fulfillment_orders',
        'read_inventory',
    ];

    public function __construct(
        private readonly AdminGraphql $graphql,
        private readonly RoutingConfigCompiler $compiler,
        private readonly RoutingEngine $engine,
        private readonly ShopifyCapacityReader $capacity,
        private readonly ShopifyFulfillmentMover $fulfillmentMover,
        private readonly ActivityLogger $activity,
    ) {}

    public function route(Shop $shop, int $orderId): void
    {
        $missingScopes = array_values(array_diff(
            self::REQUIRED_SCOPES,
            array_filter(explode(',', $shop->scopes ?? '')),
        ));
        if ($missingScopes !== []) {
            $this->skip($shop, 'Shopify order', 'Automatic routing is waiting for Shopify permissions: '.implode(', ', $missingScopes).'.');

            return;
        }

        $order = $this->loadOrder($shop, $orderId);
        if ($order === null) {
            return;
        }

        $subject = $this->subject($order['name'] ?? null);
        if (($order['cancelledAt'] ?? null) !== null || ($order['displayFulfillmentStatus'] ?? null) !== 'UNFULFILLED') {
            $this->skip($shop, $subject, 'Shopify reports this order is not eligible for automatic reassignment.');

            return;
        }

        $fulfillmentOrders = $order['fulfillmentOrders']['nodes'] ?? [];
        if (($order['fulfillmentOrders']['pageInfo']['hasNextPage'] ?? false) || count($fulfillmentOrders) !== 1) {
            $this->skip($shop, $subject, 'Automatic reassignment currently supports orders with one fulfillment order.');

            return;
        }

        $fulfillmentOrder = $fulfillmentOrders[0];
        if (($fulfillmentOrder['status'] ?? null) !== 'OPEN'
            || ! in_array($fulfillmentOrder['requestStatus'] ?? null, [null, 'UNSUBMITTED'], true)) {
            $this->skip($shop, $subject, 'Shopify has already started or requested this fulfillment.');

            return;
        }

        $locations = $this->compiler->shippingLocations($shop)
            ->whereNull('managed_by_app')
            ->values();
        if ($locations->isEmpty()) {
            $this->skip($shop, $subject, 'No active merchant-managed location is configured to fulfill online orders.');

            return;
        }

        $lineItems = $order['lineItems']['nodes'] ?? [];
        if ($lineItems === [] || ($order['lineItems']['pageInfo']['hasNextPage'] ?? false)) {
            $this->skip($shop, $subject, 'Order line items could not be fully verified; no fulfillment changes were made.');

            return;
        }

        $scenario = $this->scenario($lineItems, $order['shippingAddress'] ?? [], $locations);
        if ($scenario === null) {
            $this->skip($shop, $subject, 'Product inventory could not be fully verified; no fulfillment changes were made.');

            return;
        }

        $compiled = $this->compiler->compile(
            $shop,
            $shop->routingConfig?->settings ?? $this->compiler->defaults($shop),
        );
        $dailyCounts = [];
        if ($locations->contains(fn (Location $location) => $location->daily_order_cap !== null)) {
            try {
                $dailyCounts = $this->capacity->ordersByLocationToday($shop)['by_location'] ?? [];
            } catch (ShopifyApiException $exception) {
                if ($exception->status !== 403) {
                    throw $exception;
                }

                $this->skip($shop, $subject, 'Shopify capacity data could not be verified; no fulfillment changes were made.');

                return;
            }
        }
        foreach ($scenario['locations'] as &$location) {
            $model = $locations->firstWhere('shopify_id', $location['id']);
            $limit = $model?->daily_order_cap;
            $location['at_capacity'] = $limit !== null
                && ($dailyCounts[$location['id']] ?? 0) >= $limit;
        }
        unset($location);

        $decision = $this->engine->route($compiled, $scenario);
        if ($decision['outcome'] !== RoutingEngine::OUTCOME_SINGLE) {
            $this->skip($shop, $subject, $decision['outcome'] === RoutingEngine::OUTCOME_SPLIT
                ? 'The eligible routing outcome requires multiple fulfillment locations; no automatic move was made.'
                : 'No configured location can fulfill the entire order with verified inventory and capacity.');

            return;
        }

        $destinationGid = $decision['shipments'][0]['location'];
        $sourceGid = $fulfillmentOrder['assignedLocation']['location']['id'] ?? null;
        $destination = $locations->firstWhere('shopify_id', $destinationGid);
        if (! $destination instanceof Location) {
            $this->skip($shop, $subject, 'The selected destination is no longer an active merchant-managed shipping location.');

            return;
        }

        if ($sourceGid === $destinationGid) {
            $this->activity->record($shop, 'order_automatic_routing_unchanged', $subject, 'Order is already assigned to its highest-ranked eligible location.');

            return;
        }

        $source = $sourceGid === null
            ? null
            : $shop->locations()->where('shopify_id', $sourceGid)->first(['id', 'managed_by_app']);
        if ($source === null || $source->managed_by_app !== null) {
            $this->skip($shop, $subject, 'The current fulfillment is not assigned to a supported merchant-managed location.');

            return;
        }

        try {
            $result = $this->fulfillmentMover->move($shop, $orderId, $destination);
        } catch (ShopifyApiException $exception) {
            if (! in_array($exception->status, [403, 422], true)) {
                throw $exception;
            }

            $this->skip($shop, $subject, 'Shopify rejected the automatic move; the order was left for manual review.');

            return;
        }

        if ($result['status'] === 'blocked' || $result['status'] === 'not_found') {
            $this->skip($shop, $subject, $result['message'] ?? 'Shopify no longer considers this order eligible for reassignment.');

            return;
        }

        $this->activity->record(
            $shop,
            $result['partial'] ? 'order_automatic_routing_partial' : 'order_automatically_routed',
            $subject,
            $result['partial']
                ? "Shopify only partially moved the order to {$destination->name}; review the remaining fulfillment in Shopify."
                : "Automatically routed the order to {$destination->name} using the saved routing rules.",
        );
    }

    private function loadOrder(Shop $shop, int $orderId): ?array
    {
        try {
            return $this->graphql->query($shop, <<<'GQL'
                query AutomaticRoutingOrder($id: ID!, $lineItemCount: Int!, $inventoryLevelCount: Int!) {
                    node(id: $id) {
                        ... on Order {
                            name
                            cancelledAt
                            displayFulfillmentStatus
                            shippingAddress { countryCodeV2 provinceCode }
                            lineItems(first: $lineItemCount) {
                                nodes {
                                    id
                                    quantity
                                    variant {
                                        product { tags }
                                        inventoryItem {
                                            inventoryLevels(first: $inventoryLevelCount) {
                                                nodes {
                                                    location { id }
                                                    quantities(names: ["available"]) { name quantity }
                                                }
                                                pageInfo { hasNextPage }
                                            }
                                        }
                                    }
                                }
                                pageInfo { hasNextPage }
                            }
                            fulfillmentOrders(first: 2) {
                                nodes {
                                    id
                                    status
                                    requestStatus
                                    assignedLocation { location { id } }
                                }
                                pageInfo { hasNextPage }
                            }
                        }
                    }
                }
            GQL, [
                'id' => "gid://shopify/Order/{$orderId}",
                'lineItemCount' => self::MAX_LINE_ITEMS,
                'inventoryLevelCount' => self::MAX_INVENTORY_LEVELS,
            ])['node'] ?? null;
        } catch (ShopifyApiException $exception) {
            if ($exception->status !== 403) {
                throw $exception;
            }

            $this->skip($shop, 'Shopify order', 'Shopify denied access needed for automatic routing; no fulfillment changes were made.');

            return null;
        }
    }

    private function scenario(array $lineItems, array $destination, Collection $locations): ?array
    {
        $lines = [];
        $stock = [];

        foreach ($lineItems as $item) {
            $variant = $item['variant'] ?? null;
            $inventoryLevels = $variant['inventoryItem']['inventoryLevels'] ?? null;
            if ($variant === null || $inventoryLevels === null || ($inventoryLevels['pageInfo']['hasNextPage'] ?? false)) {
                return null;
            }

            $lineId = $item['id'];
            $lines[] = [
                'id' => $lineId,
                'title' => 'Order item',
                'quantity' => (int) $item['quantity'],
                'tags' => $variant['product']['tags'] ?? [],
            ];

            foreach ($inventoryLevels['nodes'] ?? [] as $level) {
                $locationId = $level['location']['id'] ?? null;
                if ($locationId === null) {
                    continue;
                }

                $available = collect($level['quantities'] ?? [])
                    ->firstWhere('name', 'available')['quantity'] ?? 0;
                $stock[$locationId][$lineId] = (int) $available;
            }
        }

        return [
            'lines' => $lines,
            'destination' => [
                'country_code' => strtoupper($destination['countryCodeV2'] ?? ''),
                'province_code' => strtoupper($destination['provinceCode'] ?? ''),
            ],
            'locations' => $locations->map(fn (Location $location) => [
                'id' => $location->shopify_id,
                'name' => $location->name,
                'country_code' => $location->country_code,
                'province_code' => $location->province_code,
                'at_capacity' => false,
            ])->all(),
            'stock' => $stock,
        ];
    }

    private function skip(Shop $shop, string $subject, string $reason): void
    {
        $this->activity->record($shop, 'order_automatic_routing_skipped', $subject, $reason);
    }

    private function subject(mixed $name): string
    {
        return is_string($name) && preg_match('/^#[A-Za-z0-9-]{1,40}$/', $name) === 1
            ? $name
            : 'Shopify order';
    }
}
