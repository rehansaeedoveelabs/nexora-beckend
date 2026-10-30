<?php

namespace App\Services\Shopify;

use App\Models\Shop;
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
        $locations = $shop->locations()->get(['id', 'shopify_id', 'name', 'type'])->keyBy('shopify_id');
        $counts = [];
        $assignedCounts = [];
        $distribution = [];
        $fulfilledByLocation = [];
        $splitCount = 0;
        $recentOrders = [];
        $orderCount = 0;
        $assignedCount = 0;
        $fulfilledCount = 0;
        $cursor = null;

        for ($offset = 0; $offset < $days; $offset++) {
            $date = $from->addDays($offset);
            $key = $date->format('Y-m-d');
            $counts[$key] = 0;
            $assignedCounts[$key] = 0;
        }

        do {
            $result = $this->graphql->query($shop, $query, [
                'cursor' => $cursor,
                'search' => $search,
            ])['orders'] ?? [];

            foreach ($result['nodes'] ?? [] as $order) {
                if (($order['cancelledAt'] ?? null) !== null) {
                    continue;
                }

                $formatted = $this->format($shop, $order, $locationData);
                $dateKey = CarbonImmutable::parse($formatted['date'])->setTimezone($timezone)->format('Y-m-d');
                if (! array_key_exists($dateKey, $counts)) {
                    continue;
                }

                $orderCount++;
                $counts[$dateKey]++;
                $assignedShopifyIds = collect($order['fulfillmentOrders']['nodes'] ?? [])
                    ->map(fn (array $fulfillmentOrder) => $fulfillmentOrder['assignedLocation']['location']['id'] ?? null)
                    ->filter()
                    ->unique();

                if ($assignedShopifyIds->isNotEmpty()) {
                    $assignedCount++;
                    $assignedCounts[$dateKey]++;
                    if ($assignedShopifyIds->count() > 1) {
                        $splitCount++;
                    }
                    foreach ($assignedShopifyIds as $shopifyId) {
                        $locationId = $locationIds[$shopifyId] ?? null;
                        if ($locationId !== null) {
                            $distribution[(string) $locationId] = ($distribution[(string) $locationId] ?? 0) + 1;
                            if ($formatted['fulfillmentStatus'] === 'fulfilled') {
                                $fulfilledByLocation[(string) $locationId] = ($fulfilledByLocation[(string) $locationId] ?? 0) + 1;
                            }
                        }
                    }
                }
                if ($formatted['fulfillmentStatus'] === 'fulfilled') {
                    $fulfilledCount++;
                }
                if (count($recentOrders) < 6) {
                    $recentOrders[] = $formatted;
                }
            }

            $pageInfo = $result['pageInfo'] ?? [];
            $cursor = $pageInfo['endCursor'] ?? null;
        } while (($pageInfo['hasNextPage'] ?? false) && $cursor !== null);

        $distributionRows = $locations->map(fn ($location) => [
            'id' => (string) $location->id,
            'name' => $location->name,
            'type' => $location->type,
            'value' => $distribution[(string) $location->id] ?? 0,
            'fulfilled' => $fulfilledByLocation[(string) $location->id] ?? 0,
        ])->values();
        $byType = $distributionRows
            ->groupBy(fn (array $row) => $row['type'] ?? 'unclassified')
            ->map(fn ($rows, string $type) => [
                'id' => $type,
                'name' => match ($type) {
                    'store' => 'Store',
                    'warehouse' => 'Warehouse',
                    'third_party' => 'Third-party',
                    default => 'Unclassified',
                },
                'value' => $rows->sum('value'),
            ])
            ->values();

        return [
            'days' => $days,
            'timezone' => $timezone,
            'totals' => [
                'orders' => $orderCount,
                'assigned' => $assignedCount,
                'unassigned' => $orderCount - $assignedCount,
                'fulfilled' => $fulfilledCount,
                'split' => $splitCount,
            ],
            'trend' => array_map(fn (string $key) => [
                'label' => CarbonImmutable::parse($key, $timezone)->format('M j'),
                'orders' => $counts[$key],
                'assigned' => $assignedCounts[$key],
            ], array_keys($counts)),
            'distribution' => $distributionRows,
            'byType' => $byType,
            'recentOrders' => $recentOrders,
            'asOf' => CarbonImmutable::now($timezone)->toIso8601String(),
        ];
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
        $reassignmentReason = $this->reassignmentBlockReason(
            $order,
            $fulfillmentOrders,
            $order['fulfillmentOrders']['pageInfo']['hasNextPage'] ?? false,
            $locationsByShopifyId,
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
            'routingStatus' => 'pending',
            'fulfillmentStatus' => $this->fulfillmentStatus($order['displayFulfillmentStatus'] ?? null),
            'split' => count($shipments) > 1,
            'shipments' => $shipments,
            'candidates' => [],
            'decisionReason' => 'Automatic routing outcomes are recorded in Activity; detailed routing decisions are not stored on this order.',
            'reassignment' => [
                'eligible' => $reassignmentReason === null,
                'reason' => $reassignmentReason,
            ],
            'source' => 'shopify',
        ];
    }

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
        if (($fulfillmentOrder['status'] ?? null) !== 'OPEN') {
            return 'Shopify has already started this fulfillment, so it cannot be reassigned here.';
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
