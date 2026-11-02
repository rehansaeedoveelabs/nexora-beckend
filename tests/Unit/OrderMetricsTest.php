<?php

namespace Tests\Unit;

use App\Services\Metrics\OrderMetrics;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class OrderMetricsTest extends TestCase
{
    private const STORE = 'gid://shopify/Location/101';

    private const WAREHOUSE = 'gid://shopify/Location/102';

    /** Window is 3 Chicago days: 2026-01-10, 2026-01-11, 2026-01-12. */
    private function metrics(int $days = 3, int $recentLimit = 6): OrderMetrics
    {
        return new OrderMetrics(
            [self::STORE => '1', self::WAREHOUSE => '2'],
            [
                ['id' => 1, 'name' => 'Store', 'type' => 'store'],
                ['id' => 2, 'name' => 'Warehouse', 'type' => 'warehouse'],
            ],
            $days,
            CarbonImmutable::parse('2026-01-10', 'America/Chicago')->startOfDay(),
            'America/Chicago',
            $recentLimit,
        );
    }

    /** A formatted order dated mid-window unless overridden. */
    private function order(string $status = 'unfulfilled', string $date = '2026-01-11T12:00:00Z'): array
    {
        return ['date' => $date, 'fulfillmentStatus' => $status, 'number' => '#1001'];
    }

    public function test_cancelled_orders_are_excluded(): void
    {
        $m = $this->metrics();
        $m->add($this->order(), true, [self::STORE]);

        $this->assertSame(0, $m->result()['totals']['orders']);
    }

    public function test_orders_outside_the_window_are_excluded(): void
    {
        $m = $this->metrics();
        $m->add($this->order(date: '2026-01-01T12:00:00Z'), false, [self::STORE]);
        $m->add($this->order(date: '2026-02-01T12:00:00Z'), false, [self::STORE]);

        $this->assertSame(0, $m->result()['totals']['orders']);
    }

    public function test_assigned_and_unassigned_are_counted(): void
    {
        $m = $this->metrics();
        $m->add($this->order(), false, []);
        $m->add($this->order(), false, [self::STORE]);

        $totals = $m->result()['totals'];
        $this->assertSame(2, $totals['orders']);
        $this->assertSame(1, $totals['assigned']);
        $this->assertSame(1, $totals['unassigned']);
    }

    public function test_split_is_an_order_across_more_than_one_location(): void
    {
        $m = $this->metrics();
        $m->add($this->order(), false, [self::STORE, self::WAREHOUSE]);
        $m->add($this->order(), false, [self::STORE]);
        // A duplicated location id is still one location, not a split.
        $m->add($this->order(), false, [self::WAREHOUSE, self::WAREHOUSE]);

        $totals = $m->result()['totals'];
        $this->assertSame(3, $totals['assigned']);
        $this->assertSame(1, $totals['split']);
    }

    public function test_fulfilled_count_follows_fulfillment_status(): void
    {
        $m = $this->metrics();
        $m->add($this->order('fulfilled'), false, [self::STORE]);
        $m->add($this->order('unfulfilled'), false, [self::STORE]);

        $this->assertSame(1, $m->result()['totals']['fulfilled']);
    }

    public function test_distribution_and_by_type_aggregate_per_location(): void
    {
        $m = $this->metrics();
        $m->add($this->order('fulfilled'), false, [self::STORE]);
        $m->add($this->order('unfulfilled'), false, [self::WAREHOUSE]);
        $m->add($this->order('unfulfilled'), false, [self::STORE, self::WAREHOUSE]);

        $result = $m->result();
        $this->assertSame(
            ['id' => '1', 'name' => 'Store', 'type' => 'store', 'value' => 2, 'fulfilled' => 1],
            $result['distribution'][0],
        );
        $this->assertSame(
            ['id' => '2', 'name' => 'Warehouse', 'type' => 'warehouse', 'value' => 2, 'fulfilled' => 0],
            $result['distribution'][1],
        );
        $this->assertSame('store', $result['byType'][0]['id']);
        $this->assertSame(2, $result['byType'][0]['value']);
        $this->assertSame(2, $result['byType'][1]['value']);
    }

    public function test_trend_buckets_orders_into_the_shop_local_day(): void
    {
        $m = $this->metrics();
        // 05:00 UTC on Jan 11 is 23:00 Chicago on Jan 10 -> the earlier bucket.
        $m->add($this->order(date: '2026-01-11T05:00:00Z'), false, []);

        $trend = $m->result()['trend'];
        $this->assertSame('Jan 10', $trend[0]['label']);
        $this->assertSame(1, $trend[0]['orders']);
        $this->assertSame('Jan 11', $trend[1]['label']);
        $this->assertSame(0, $trend[1]['orders']);
    }

    public function test_rates_are_defined_with_zero_guards(): void
    {
        $empty = $this->metrics()->result()['totals'];
        $this->assertSame(0, $empty['assignedRate']);
        $this->assertSame(0, $empty['splitRate']);

        $m = $this->metrics();
        $m->add($this->order(), false, []);
        $m->add($this->order(), false, [self::STORE, self::WAREHOUSE]);
        $m->add($this->order(), false, [self::STORE]);

        $totals = $m->result()['totals'];
        // 2 assigned of 3 orders; 1 split of 2 assigned.
        $this->assertSame(2 / 3, $totals['assignedRate']);
        $this->assertSame(0.5, $totals['splitRate']);
    }

    public function test_recent_orders_are_capped_at_the_limit(): void
    {
        $m = $this->metrics(recentLimit: 2);
        $m->add(['number' => '#1'] + $this->order(), false, []);
        $m->add(['number' => '#2'] + $this->order(), false, []);
        $m->add(['number' => '#3'] + $this->order(), false, []);

        $recent = $m->result()['recentOrders'];
        // Keeps the first N fed (newest, given the shell's reverse-chronological fetch).
        $this->assertCount(2, $recent);
        $this->assertSame(['#1', '#2'], array_column($recent, 'number'));
    }
}
