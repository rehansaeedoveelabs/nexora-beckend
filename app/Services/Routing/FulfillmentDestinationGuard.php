<?php

namespace App\Services\Routing;

use App\Exceptions\ShopifyApiException;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;
use App\Services\Shopify\ShopifyCapacityReader;
use Illuminate\Support\Collection;

/**
 * Shared preflight for manual and automatic fulfillment moves.
 *
 * Enforces the same inventory, capacity and product-rule constraints as RoutingEngine,
 * fails closed when inventory levels are missing/truncated, and never calls Shopify mutations.
 */
class FulfillmentDestinationGuard
{
    private const MAX_LINE_ITEMS = 20;

    private const MAX_INVENTORY_LEVELS = 25;

    public function __construct(
        private readonly AdminGraphql $graphql,
        private readonly RoutingConfigCompiler $compiler,
        private readonly RoutingEngine $engine,
        private readonly ShopifyCapacityReader $capacity,
    ) {}

    /**
     * @return array{
     *   status: 'ok'|'not_found'|'blocked',
     *   message?: string,
     *   order?: array,
     *   fulfillment_order?: array
     * }
     */
    public function evaluate(Shop $shop, int $orderId, Location $destination): array
    {
        if (! $destination->is_active || ! $destination->ships || $destination->managed_by_app !== null) {
            return [
                'status' => 'blocked',
                'message' => 'Choose an active, merchant-managed location that fulfills online orders.',
            ];
        }

        try {
            $order = $this->loadOrder($shop, $orderId);
        } catch (ShopifyApiException $exception) {
            if ($exception->status === 403) {
                return [
                    'status' => 'blocked',
                    'message' => 'Shopify denied access needed to verify inventory and fulfillment before moving this order.',
                ];
            }

            throw $exception;
        }

        if ($order === null) {
            return ['status' => 'not_found'];
        }

        if (($order['cancelledAt'] ?? null) !== null || ($order['displayFulfillmentStatus'] ?? null) !== 'UNFULFILLED') {
            return [
                'status' => 'blocked',
                'message' => 'Only uncancelled, unfulfilled orders can be reassigned.',
            ];
        }

        $fulfillmentOrders = $order['fulfillmentOrders']['nodes'] ?? [];
        if (($order['fulfillmentOrders']['pageInfo']['hasNextPage'] ?? false) || count($fulfillmentOrders) !== 1) {
            return [
                'status' => 'blocked',
                'message' => 'Only orders with one fulfillment order can be reassigned here.',
            ];
        }

        $fulfillmentOrder = $fulfillmentOrders[0];
        if (($fulfillmentOrder['status'] ?? null) !== 'OPEN'
            || ! in_array($fulfillmentOrder['requestStatus'] ?? null, [null, 'UNSUBMITTED'], true)) {
            return [
                'status' => 'blocked',
                'message' => 'Shopify has already started or requested this fulfillment, so it cannot be reassigned here.',
            ];
        }

        $sourceShopifyId = $fulfillmentOrder['assignedLocation']['location']['id'] ?? null;
        $source = $sourceShopifyId === null
            ? null
            : $shop->locations()->where('shopify_id', $sourceShopifyId)->first(['id', 'managed_by_app']);
        if ($source === null
            || $sourceShopifyId === $destination->shopify_id
            || $source->managed_by_app !== null) {
            return [
                'status' => 'blocked',
                'message' => 'This fulfillment is not assigned to a supported merchant-managed location.',
            ];
        }

        $locations = $this->compiler->shippingLocations($shop)
            ->whereNull('managed_by_app')
            ->values();
        if ($locations->firstWhere('id', $destination->id) === null) {
            return [
                'status' => 'blocked',
                'message' => 'Choose an active, merchant-managed location that fulfills online orders.',
            ];
        }

        $lineItems = $order['lineItems']['nodes'] ?? [];
        if ($lineItems === [] || ($order['lineItems']['pageInfo']['hasNextPage'] ?? false)) {
            return [
                'status' => 'blocked',
                'message' => 'Order line items could not be fully verified; no fulfillment changes were made.',
            ];
        }

        $built = $this->buildStockScenario($lineItems, $order['shippingAddress'] ?? [], $locations);
        if ($built === null) {
            return [
                'status' => 'blocked',
                'message' => 'Product inventory could not be fully verified; no fulfillment changes were made.',
            ];
        }

        [$scenario, $verifiedStock] = $built;

        if (! $this->destinationLevelsVerified($destination->shopify_id, $scenario['lines'], $verifiedStock)) {
            return [
                'status' => 'blocked',
                'message' => "Inventory at {$destination->name} could not be verified for every item (missing inventory level).",
            ];
        }

        $dailyCounts = $this->dailyCounts($shop, $locations, $destination);
        if ($dailyCounts === null) {
            return [
                'status' => 'blocked',
                'message' => 'Shopify capacity data could not be verified; no fulfillment changes were made.',
            ];
        }

        foreach ($scenario['locations'] as &$location) {
            $model = $locations->firstWhere('shopify_id', $location['id']);
            $limit = $model?->daily_order_cap;
            $location['at_capacity'] = $limit !== null
                && ($dailyCounts[$location['id']] ?? 0) >= $limit;
        }
        unset($location);

        if ($destination->daily_order_cap !== null
            && ($dailyCounts[$destination->shopify_id] ?? 0) >= $destination->daily_order_cap) {
            return [
                'status' => 'blocked',
                'message' => "{$destination->name} is at its daily order capacity, so this order cannot be moved there.",
            ];
        }

        $compiled = $this->compiler->compile(
            $shop,
            $shop->routingConfig?->settings ?? $this->compiler->defaults($shop),
        );
        $decision = $this->engine->route($compiled, $scenario);
        $destinationResult = collect($decision['locations'] ?? [])
            ->firstWhere('id', $destination->shopify_id);

        if ($destinationResult === null) {
            return [
                'status' => 'blocked',
                'message' => "{$destination->name} is not an eligible shipping location for this order under the current routing rules.",
            ];
        }

        if (! empty($destinationResult['at_capacity']) || $this->hasReason($destinationResult, 'at_capacity')) {
            return [
                'status' => 'blocked',
                'message' => "{$destination->name} is at its daily order capacity, so this order cannot be moved there.",
            ];
        }

        if ($this->hasReason($destinationResult, 'rule_excludes')) {
            return [
                'status' => 'blocked',
                'message' => "Saved routing rules prevent {$destination->name} from fulfilling one or more items on this order.",
            ];
        }

        if ($this->hasReason($destinationResult, 'insufficient_stock') || ! ($destinationResult['ships_everything'] ?? false)) {
            return [
                'status' => 'blocked',
                'message' => "{$destination->name} does not have enough available inventory (after safety stock) to fulfill every item on this order.",
            ];
        }

        return [
            'status' => 'ok',
            'order' => $order,
            'fulfillment_order' => $fulfillmentOrder,
        ];
    }

    private function loadOrder(Shop $shop, int $orderId): ?array
    {
        return $this->graphql->query($shop, <<<'GQL'
            query OrderForFulfillmentMove($id: ID!, $lineItemCount: Int!, $inventoryLevelCount: Int!) {
                node(id: $id) {
                    ... on Order {
                        id
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
                                                quantities(names: ["available", "safety_stock"]) {
                                                    name
                                                    quantity
                                                }
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
    }

    /**
     * @return array{0: array, 1: array<string, array<string, true>>}|null
     */
    private function buildStockScenario(array $lineItems, array $destination, Collection $locations): ?array
    {
        $lines = [];
        $stock = [];
        $verified = [];

        foreach ($lineItems as $item) {
            $variant = $item['variant'] ?? null;
            $inventoryLevels = $variant['inventoryItem']['inventoryLevels'] ?? null;
            if ($variant === null || $inventoryLevels === null || ($inventoryLevels['pageInfo']['hasNextPage'] ?? false)) {
                return null;
            }

            $lineId = $item['id'] ?? null;
            if (! is_string($lineId) || $lineId === '') {
                return null;
            }

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

                $quantities = collect($level['quantities'] ?? []);
                $available = $quantities->firstWhere('name', 'available');
                // Fail closed when Shopify omits the sellable quantity on a level we did find.
                if ($available === null || ! array_key_exists('quantity', $available)) {
                    return null;
                }

                $stock[$locationId][$lineId] = (int) $available['quantity'];
                $verified[$locationId][$lineId] = true;
            }
        }

        return [[
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
        ], $verified];
    }

    /**
     * @param  array<int, array{id: string}>  $lines
     * @param  array<string, array<string, true>>  $verified
     */
    private function destinationLevelsVerified(string $destinationGid, array $lines, array $verified): bool
    {
        foreach ($lines as $line) {
            if (! isset($verified[$destinationGid][$line['id']])) {
                return false;
            }
        }

        return $lines !== [];
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @return array<string, int>|null null when capacity was required but could not be verified
     */
    private function dailyCounts(Shop $shop, Collection $locations, Location $destination): ?array
    {
        $needsCapacity = $destination->daily_order_cap !== null
            || $locations->contains(fn (Location $location) => $location->daily_order_cap !== null);

        if (! $needsCapacity) {
            return [];
        }

        try {
            return $this->capacity->ordersByLocationToday($shop)['by_location'] ?? [];
        } catch (ShopifyApiException $exception) {
            if ($exception->status === 403) {
                return null;
            }

            throw $exception;
        }
    }

    private function hasReason(array $locationResult, string $code): bool
    {
        return collect($locationResult['reasons'] ?? [])
            ->contains(fn (array $reason) => ($reason['code'] ?? null) === $code);
    }
}
