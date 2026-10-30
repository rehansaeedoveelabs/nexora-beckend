<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use Carbon\CarbonImmutable;

class ShopifyCapacityReader
{
    public function __construct(private readonly AdminGraphql $graphql) {}

    /**
     * Count today's non-cancelled orders against their currently assigned locations.
     * The query is limited to the shop's local day and only requests fulfillment location IDs.
     */
    public function ordersByLocationToday(Shop $shop): array
    {
        $since = CarbonImmutable::now($shop->timezone ?: 'UTC')
            ->startOfDay()
            ->utc()
            ->toIso8601String();
        $query = <<<'GQL'
            query CapacityOrders($cursor: String, $search: String!) {
                orders(first: 250, after: $cursor, sortKey: CREATED_AT, reverse: true, query: $search) {
                    nodes {
                        cancelledAt
                        fulfillmentOrders(first: 10) {
                            nodes { assignedLocation { location { id } } }
                        }
                    }
                    pageInfo { hasNextPage endCursor }
                }
            }
        GQL;
        $counts = [];
        $total = 0;
        $cursor = null;
        $search = "created_at:>='{$since}'";

        do {
            $orders = $this->graphql->query($shop, $query, [
                'cursor' => $cursor,
                'search' => $search,
            ])['orders'] ?? [];

            foreach ($orders['nodes'] ?? [] as $order) {
                if (($order['cancelledAt'] ?? null) !== null) {
                    continue;
                }

                $locationIds = collect($order['fulfillmentOrders']['nodes'] ?? [])
                    ->map(fn (array $fulfillmentOrder) => $fulfillmentOrder['assignedLocation']['location']['id'] ?? null)
                    ->filter()
                    ->unique();

                if ($locationIds->isNotEmpty()) {
                    $total++;
                }

                foreach ($locationIds as $locationId) {
                    $counts[$locationId] = ($counts[$locationId] ?? 0) + 1;
                }
            }

            $pageInfo = $orders['pageInfo'] ?? [];
            $cursor = $pageInfo['endCursor'] ?? null;
        } while (($pageInfo['hasNextPage'] ?? false) && $cursor !== null);

        return [
            'by_location' => $counts,
            'total' => $total,
        ];
    }
}
