<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Services\Metrics\OrderMetrics;
use Carbon\CarbonImmutable;

class ShopifyOrderReader
{
    private const SUMMARY_ORDER_FIELDS = '
        id
        name
        createdAt
        displayFulfillmentStatus
        shippingAddress { city countryCodeV2 }
        fulfillmentOrders(first: 10) {
            nodes {
                id
                status
                requestStatus
                assignedLocation { location { id name } }
            }
            pageInfo { hasNextPage }
        }
    ';

    private const ORDER_FIELDS = '
        id
        name
        createdAt
        cancelledAt
        displayFulfillmentStatus
        shippingAddress { city countryCodeV2 }
        shippingLines(first: 5) {
            nodes {
                discountedPriceSet { shopMoney { amount currencyCode } }
            }
        }
        lineItems(first: 20) {
            nodes { title sku quantity variant { product { tags } } }
        }
        fulfillmentOrders(first: 10) {
            nodes {
                id
                status
                requestStatus
                assignedLocation { location { id name } }
            }
            pageInfo { hasNextPage }
        }
    ';

    public function __construct(private readonly AdminGraphql $graphql) {}

    public function list(Shop $shop, int $first, ?string $after = null): array
    {
        $result = $this->graphql->query($shop, 'query Orders($first: Int!, $after: String) {
            orders(first: $first, after: $after, sortKey: CREATED_AT, reverse: true) {
                nodes { '.self::ORDER_FIELDS.' }
                pageInfo { hasNextPage endCursor }
            }
        }', [
            'first' => $first,
            'after' => $after,
        ])['orders'];

        $locations = $this->locationData($shop);

        return [
            'orders' => array_map(fn (array $order) => $this->format($shop, $order, $locations), $result['nodes'] ?? []),
            'page_info' => [
                'has_next_page' => (bool) ($result['pageInfo']['hasNextPage'] ?? false),
                'end_cursor' => $result['pageInfo']['endCursor'] ?? null,
            ],
        ];
    }

    public function find(Shop $shop, int $id): ?array
    {
        $order = $this->graphql->query($shop, 'query Order($id: ID!) {
            node(id: $id) {
                ... on Order { '.self::ORDER_FIELDS.' }
            }
        }', [
            'id' => "gid://shopify/Order/{$id}",
        ])['node'] ?? null;

        if ($order === null) {
            return null;
        }

        return $this->format($shop, $order, $this->locationData($shop));
    }

    public function overview(Shop $shop, int $days): array
    {
        $timezone = $shop->timezone ?: 'UTC';
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $from = $today->subDays($days - 1);
        $search = "created_at:>='{$from->utc()->toIso8601String()}'";
        $query = 'query DashboardOrders($cursor: String, $search: String!) {
            orders(first: 250, after: $cursor, sortKey: CREATED_AT, reverse: true, query: $search) {
                nodes { cancelledAt '.self::SUMMARY_ORDER_FIELDS.' }
                pageInfo { hasNextPage endCursor }
            }
        }';
        $locationData = $this->locationData($shop);
        $locationIds = array_map(fn (array $location) => $location['id'], $locationData);
        $locationRows = $shop->locations()->get(['id', 'name', 'type'])
            ->map(fn ($location) => ['id' => $location->id, 'name' => $location->name, 'type' => $location->type])
            ->all();

        // Fetch is the imperative shell; OrderMetrics owns the definitions (see that class).
        $metrics = new OrderMetrics($locationIds, $locationRows, $days, $from, $timezone);
        $cursor = null;

        do {
            $result = $this->graphql->query($shop, $query, [
                'cursor' => $cursor,
                'search' => $search,
            ])['orders'] ?? [];

            foreach ($result['nodes'] ?? [] as $order) {
                $assignedShopifyIds = collect($order['fulfillmentOrders']['nodes'] ?? [])
                    ->map(fn (array $fulfillmentOrder) => $fulfillmentOrder['assignedLocation']['location']['id'] ?? null)
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                $metrics->add(
                    $this->format($shop, $order, $locationData),
                    ($order['cancelledAt'] ?? null) !== null,
                    $assignedShopifyIds,
                );
            }

            $pageInfo = $result['pageInfo'] ?? [];
            $cursor = $pageInfo['endCursor'] ?? null;
        } while (($pageInfo['hasNextPage'] ?? false) && $cursor !== null);

        return array_merge($metrics->result(), [
            'asOf' => CarbonImmutable::now($timezone)->toIso8601String(),
        ]);
    }

    private function locationData(Shop $shop): array
    {
        return $shop->locations()
            ->get(['id', 'shopify_id', 'managed_by_app'])
            ->keyBy('shopify_id')
            ->map(fn ($location) => [
                'id' => (string) $location->id,
                'managed_by_app' => $location->managed_by_app,
            ])
            ->all();
    }

    private function format(Shop $shop, array $order, array $locationsByShopifyId): array
    {
        $fulfillmentOrders = $order['fulfillmentOrders']['nodes'] ?? [];
        $locations = collect($fulfillmentOrders)
            ->map(fn (array $fulfillmentOrder) => $fulfillmentOrder['assignedLocation']['location'] ?? null)
            ->filter(fn (?array $location) => $location !== null)
            ->unique('id')
            ->values();

        $shipments = $locations->map(fn (array $location) => [
            'locationId' => (string) ($locationsByShopifyId[$location['id']]['id'] ?? ''),
            'locationName' => $location['name'],
            'items' => [],
        ])->all();

        $shippingLines = $order['shippingLines']['nodes'] ?? [];
        $lineItems = $order['lineItems']['nodes'] ?? [];
        $shippingAmount = array_sum(array_map(
            fn (array $line) => (float) ($line['discountedPriceSet']['shopMoney']['amount'] ?? 0),
            $shippingLines,
        ));
        $currency = $shippingLines[0]['discountedPriceSet']['shopMoney']['currencyCode'] ?? $shop->currency ?? 'USD';
        $hasMoreFulfillmentOrders = (bool) ($order['fulfillmentOrders']['pageInfo']['hasNextPage'] ?? false);
        $reassignmentReason = $this->reassignmentBlockReason(
            $order,
            $fulfillmentOrders,
            $hasMoreFulfillmentOrders,
            $locationsByShopifyId,
        );
        $formattedFulfillmentOrders = array_map(
            function (array $fulfillmentOrder) use ($locationsByShopifyId) {
                $location = $fulfillmentOrder['assignedLocation']['location'] ?? null;
                $shopifyLocationId = $location['id'] ?? null;

                return [
                    'id' => isset($fulfillmentOrder['id'])
                        ? substr($fulfillmentOrder['id'], strrpos($fulfillmentOrder['id'], '/') + 1)
                        : null,
                    'status' => $fulfillmentOrder['status'] ?? null,
                    'requestStatus' => $fulfillmentOrder['requestStatus'] ?? null,
                    'assignedLocationId' => $shopifyLocationId !== null
                        && isset($locationsByShopifyId[$shopifyLocationId]['id'])
                        && $locationsByShopifyId[$shopifyLocationId]['id'] !== ''
                            ? (string) $locationsByShopifyId[$shopifyLocationId]['id']
                            : null,
                    'assignedLocationName' => $location['name'] ?? null,
                ];
            },
            $fulfillmentOrders,
        );

        return [
            'id' => substr($order['id'], strrpos($order['id'], '/') + 1),
            'number' => $order['name'],
            'date' => $order['createdAt'],
            'destination' => [
                'city' => $order['shippingAddress']['city'] ?? '',
                'country' => $order['shippingAddress']['countryCodeV2'] ?? '',
            ],
            'itemCount' => array_sum(array_map(
                fn (array $item) => (int) ($item['quantity'] ?? 0),
                $lineItems,
            )),
            'items' => array_map(fn (array $item) => [
                'title' => $item['title'],
                'sku' => $item['sku'] ?? '',
                'qty' => (int) $item['quantity'],
                'tag' => $item['variant']['product']['tags'][0] ?? null,
            ], $lineItems),
            'assignedLocationId' => isset($shipments[0]['locationId']) && $shipments[0]['locationId'] !== ''
                ? $shipments[0]['locationId']
                : null,
            'assignedLocationName' => $shipments[0]['locationName'] ?? null,
            'shippingEstimate' => $shippingLines === [] ? null : $shippingAmount,
            'currency' => $currency,
            // Move outcomes live in Activity; this field must not imply an in-flight routing job.
            'routingStatus' => 'not_recorded',
            'fulfillmentStatus' => $this->fulfillmentStatus($order['displayFulfillmentStatus'] ?? null),
            'split' => count($shipments) > 1,
            'shipments' => $shipments,
            'fulfillmentOrders' => $formattedFulfillmentOrders,
            'candidates' => [],
            'decisionReason' => 'Automatic routing outcomes are recorded in Activity; detailed routing decisions are not stored on this order.',
            'reassignment' => [
                'eligible' => $reassignmentReason === null,
                'reason' => $reassignmentReason,
            ],
            'source' => 'shopify',
        ];
    }

    /**
     * Keep eligibility identical to ShopifyFulfillmentMover preflight so the UI never offers a move
     * the mover would immediately block.
     */
    private function reassignmentBlockReason(
        array $order,
        array $fulfillmentOrders,
        bool $hasMoreFulfillmentOrders,
        array $locationsByShopifyId,
    ): ?string {
        if (($order['cancelledAt'] ?? null) !== null || ($order['displayFulfillmentStatus'] ?? null) !== 'UNFULFILLED') {
            return 'Only uncancelled, unfulfilled orders can be reassigned.';
        }
        if (count($fulfillmentOrders) !== 1 || $hasMoreFulfillmentOrders) {
            return 'Only orders with one fulfillment order can be reassigned here.';
        }

        $fulfillmentOrder = $fulfillmentOrders[0];
        if (($fulfillmentOrder['status'] ?? null) !== 'OPEN'
            || ! in_array($fulfillmentOrder['requestStatus'] ?? null, [null, 'UNSUBMITTED'], true)) {
            return 'Shopify has already started or requested this fulfillment, so it cannot be reassigned here.';
        }

        $sourceShopifyId = $fulfillmentOrder['assignedLocation']['location']['id'] ?? null;
        if ($sourceShopifyId === null
            || ! array_key_exists($sourceShopifyId, $locationsByShopifyId)
            || $locationsByShopifyId[$sourceShopifyId]['managed_by_app'] !== null) {
            return 'This fulfillment is not assigned to a supported merchant-managed location.';
        }

        return null;
    }

    private function fulfillmentStatus(?string $status): string
    {
        return match ($status) {
            'FULFILLED' => 'fulfilled',
            'CANCELED' => 'cancelled',
            'UNFULFILLED', null => 'unfulfilled',
            default => 'pending',
        };
    }
}
