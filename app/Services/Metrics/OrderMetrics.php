<?php

namespace App\Services\Metrics;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The single source of truth for order metric definitions shared by the Home
 * dashboard and the Insights page.
 *
 * This is a pure accumulator: it has no Shopify or database I/O. The caller fetches
 * orders (the imperative shell) and feeds each one in via add(); result() returns the
 * canonical totals, rates, trend and per-location distribution. Keeping the
 * definitions here — rather than inside the GraphQL pagination loop or in the
 * frontend — is what lets every definition be unit-tested with plain arrays and
 * guarantees Home and Insights compute identical numbers.
 *
 * Definitions:
 *  - An order counts when it is NOT cancelled and its created date falls in the window.
 *  - "assigned": the order has at least one fulfillment order with an assigned location.
 *  - "unassigned": counted orders minus assigned orders.
 *  - "split": an assigned order whose items span more than one distinct location.
 *  - "fulfilled": the order's current Shopify fulfillment status is fulfilled.
 *  - distribution/fulfilled-by-location: +1 per distinct assigned location per order.
 */
class OrderMetrics
{
    /** @var array<string, int> date key (Y-m-d) => order count */
    private array $counts = [];

    /** @var array<string, int> date key (Y-m-d) => assigned-order count */
    private array $assignedCounts = [];

    /** @var array<string, int> internal location id => assigned count */
    private array $distribution = [];

    /** @var array<string, int> internal location id => fulfilled count */
    private array $fulfilledByLocation = [];

    /** @var array<int, array<string, mixed>> the newest orders, for the recent list */
    private array $recentOrders = [];

    private int $orderCount = 0;

    private int $assignedCount = 0;

    private int $fulfilledCount = 0;

    private int $splitCount = 0;

    /**
     * @param  array<string, string>  $locationIdsByShopifyId  Shopify location GID => internal location id
     * @param  array<int, array{id: int|string, name: string, type: ?string}>  $locationRows  ordered locations for the distribution table
     */
    public function __construct(
        private readonly array $locationIdsByShopifyId,
        private readonly array $locationRows,
        private readonly int $days,
        private readonly CarbonImmutable $from,
        private readonly string $timezone,
        private readonly int $recentLimit = 6,
    ) {
        for ($offset = 0; $offset < $this->days; $offset++) {
            $key = $this->from->addDays($offset)->format('Y-m-d');
            $this->counts[$key] = 0;
            $this->assignedCounts[$key] = 0;
        }
    }

    /**
     * Fold one order into the metrics. Orders outside the window or cancelled are ignored.
     *
     * @param  array<string, mixed>  $formatted  the formatted order (needs `date`, `fulfillmentStatus`)
     * @param  array<int, string>  $assignedShopifyIds  distinct Shopify location GIDs this order is assigned to
     */
    public function add(array $formatted, bool $cancelled, array $assignedShopifyIds): void
    {
        if ($cancelled) {
            return;
        }

        $dateKey = CarbonImmutable::parse($formatted['date'])->setTimezone($this->timezone)->format('Y-m-d');
        if (! array_key_exists($dateKey, $this->counts)) {
            return;
        }

        $this->orderCount++;
        $this->counts[$dateKey]++;

        $assignedShopifyIds = array_values(array_unique($assignedShopifyIds));
        $isFulfilled = ($formatted['fulfillmentStatus'] ?? null) === 'fulfilled';

        if ($assignedShopifyIds !== []) {
            $this->assignedCount++;
            $this->assignedCounts[$dateKey]++;
            if (count($assignedShopifyIds) > 1) {
                $this->splitCount++;
            }
            foreach ($assignedShopifyIds as $shopifyId) {
                $locationId = $this->locationIdsByShopifyId[$shopifyId] ?? null;
                if ($locationId === null) {
                    continue;
                }
                $this->distribution[(string) $locationId] = ($this->distribution[(string) $locationId] ?? 0) + 1;
                if ($isFulfilled) {
                    $this->fulfilledByLocation[(string) $locationId] = ($this->fulfilledByLocation[(string) $locationId] ?? 0) + 1;
                }
            }
        }

        if ($isFulfilled) {
            $this->fulfilledCount++;
        }

        if (count($this->recentOrders) < $this->recentLimit) {
            $this->recentOrders[] = $formatted;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function result(): array
    {
        $distributionRows = array_map(fn (array $location) => [
            'id' => (string) $location['id'],
            'name' => $location['name'],
            'type' => $location['type'] ?? null,
            'value' => $this->distribution[(string) $location['id']] ?? 0,
            'fulfilled' => $this->fulfilledByLocation[(string) $location['id']] ?? 0,
        ], $this->locationRows);

        $byType = (new Collection($distributionRows))
            ->groupBy(fn (array $row) => $row['type'] ?? 'unclassified')
            ->map(fn (Collection $rows, string $type) => [
                'id' => $type,
                'name' => match ($type) {
                    'store' => 'Store',
                    'warehouse' => 'Warehouse',
                    'third_party' => 'Third-party',
                    default => 'Unclassified',
                },
                'value' => $rows->sum('value'),
            ])
            ->values()
            ->all();

        return [
            'days' => $this->days,
            'timezone' => $this->timezone,
            'totals' => [
                'orders' => $this->orderCount,
                'assigned' => $this->assignedCount,
                'unassigned' => $this->orderCount - $this->assignedCount,
                'fulfilled' => $this->fulfilledCount,
                'split' => $this->splitCount,
                // Derived rates, defined once here so Home and Insights never disagree.
                // camelCase to match the rest of this data payload (byType, recentOrders, ...).
                'assignedRate' => $this->orderCount > 0 ? $this->assignedCount / $this->orderCount : 0,
                'splitRate' => $this->assignedCount > 0 ? $this->splitCount / $this->assignedCount : 0,
            ],
            'trend' => array_map(fn (string $key) => [
                'label' => CarbonImmutable::parse($key, $this->timezone)->format('M j'),
                'orders' => $this->counts[$key],
                'assigned' => $this->assignedCounts[$key],
            ], array_keys($this->counts)),
            'distribution' => $distributionRows,
            'byType' => $byType,
            'recentOrders' => $this->recentOrders,
        ];
    }
}
