<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class CapacityApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->shop = $this->installedShop(attributes: [
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
            'timezone' => 'America/Toronto',
        ]);
    }

    public function test_capacity_uses_live_todays_orders_and_saved_location_limits(): void
    {
        $store = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Store',
            'type' => 'store',
            'daily_order_cap' => 10,
            'backlog_cap' => 20,
        ]);
        $warehouse = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Warehouse',
        ]);

        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['orders' => [
                'nodes' => [
                    ['cancelledAt' => null, 'fulfillmentOrders' => ['nodes' => [
                        ['assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44']]],
                        ['assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44']]],
                        ['assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/45']]],
                    ]]],
                    ['cancelledAt' => '2026-10-29T10:00:00Z', 'fulfillmentOrders' => ['nodes' => [
                        ['assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44']]],
                    ]]],
                ],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/capacity')
            ->assertOk()
            ->assertJsonPath('data.totals.ordersToday', 1)
            ->assertJsonPath('data.totals.utilization', 0.1)
            ->assertJsonPath('data.totals.unconfigured', 1)
            ->assertJsonPath('data.rows.0.id', $store->id)
            ->assertJsonPath('data.rows.0.ordersToday', 1)
            ->assertJsonPath('data.rows.0.remaining', 9)
            ->assertJsonPath('data.rows.0.forecast', 'ok')
            ->assertJsonPath('data.rows.1.id', $warehouse->id)
            ->assertJsonPath('data.rows.1.utilization', null)
            ->assertJsonPath('data.rows.1.forecast', 'unconfigured');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['query'], 'fulfillmentOrders(first: 10)')
                && str_starts_with($body['variables']['search'], 'created_at:>=');
        });
    }

    public function test_capacity_requires_order_and_assignment_scopes(): void
    {
        $shop = $this->installedShop('limited.myshopify.com', ['scopes' => 'read_locations']);

        $this->apiAs($shop)->getJson('/api/capacity')
            ->assertForbidden()
            ->assertJsonPath('meta.missing_scopes.0', 'read_orders')
            ->assertJsonPath('meta.missing_scopes.1', 'read_third_party_fulfillment_orders');

        Http::assertNothingSent();
    }

    public function test_capacity_follows_shopify_cursor_when_today_exceeds_one_page(): void
    {
        $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Store',
            'daily_order_cap' => 5,
        ]);
        Http::fake([
            $this->graphqlUrl() => Http::sequence()
                ->push(['data' => ['orders' => [
                    'nodes' => [$this->assignedOrder('gid://shopify/Location/44')],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'today-cursor'],
                ]]])
                ->push(['data' => ['orders' => [
                    'nodes' => [$this->assignedOrder('gid://shopify/Location/44')],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'final-cursor'],
                ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/capacity')
            ->assertOk()
            ->assertJsonPath('data.totals.ordersToday', 2)
            ->assertJsonPath('data.rows.0.ordersToday', 2);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => json_decode($request->body(), true)['variables']['cursor'] === 'today-cursor');
    }

    private function assignedOrder(string $locationId): array
    {
        return [
            'cancelledAt' => null,
            'fulfillmentOrders' => ['nodes' => [
                ['assignedLocation' => ['location' => ['id' => $locationId]]],
            ]],
        ];
    }
}
