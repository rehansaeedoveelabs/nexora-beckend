<?php

namespace Tests\Feature;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class DashboardApiTest extends TestCase
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

    public function test_dashboard_aggregates_live_orders_by_shop_local_day_and_location(): void
    {
        $location = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'type' => 'warehouse',
        ]);
        $today = CarbonImmutable::now('America/Toronto')->startOfDay()->addHours(10)->utc()->toIso8601String();

        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['orders' => [
                'nodes' => [
                    $this->order('1001', $today, 'UNFULFILLED', 'gid://shopify/Location/44'),
                    $this->order('1002', $today, 'FULFILLED', null),
                    $this->order('1004', $today, 'UNFULFILLED', 'gid://shopify/Location/99'),
                    $this->order('1003', $today, 'UNFULFILLED', null, '2026-10-29T12:00:00Z'),
                ],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('data.days', 7)
            ->assertJsonPath('data.timezone', 'America/Toronto')
            ->assertJsonPath('data.totals.orders', 3)
            ->assertJsonPath('data.totals.assigned', 2)
            ->assertJsonPath('data.totals.unassigned', 1)
            ->assertJsonPath('data.totals.fulfilled', 1)
            ->assertJsonPath('data.trend.6.orders', 3)
            ->assertJsonPath('data.trend.6.assigned', 2)
            ->assertJsonPath('data.distribution.0.id', (string) $location->id)
            ->assertJsonPath('data.distribution.0.type', 'warehouse')
            ->assertJsonPath('data.distribution.0.value', 1)
            ->assertJsonPath('data.byType.0.id', 'warehouse')
            ->assertJsonPath('data.byType.0.value', 1)
            ->assertJsonPath('data.recentOrders.0.id', '1001');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['query'], 'orders(first: 250')
                && ! str_contains($body['query'], 'lineItems')
                && ! str_contains($body['query'], 'requestStatus')
                && str_starts_with($body['variables']['search'], 'created_at:>=');
        });
    }

    public function test_dashboard_requires_order_and_assignment_permissions(): void
    {
        $shop = $this->installedShop('limited.myshopify.com', ['scopes' => 'read_locations']);

        $this->apiAs($shop)->getJson('/api/dashboard')
            ->assertForbidden()
            ->assertJsonPath('meta.missing_scopes.0', 'read_orders')
            ->assertJsonPath('meta.missing_scopes.1', 'read_third_party_fulfillment_orders');

        Http::assertNothingSent();
    }

    public function test_dashboard_explains_order_protected_data_approval_denial(): void
    {
        Http::fake([
            $this->graphqlUrl() => Http::response(['errors' => [[
                'message' => 'This app is not approved to access the Order object. See https://shopify.dev/docs/apps/launch/protected-customer-data for more details.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/dashboard?days=7')
            ->assertForbidden()
            ->assertJsonPath('message', 'Shopify has not approved Nexora to access the Order object. This is app-level Protected Customer Data approval, separate from store permissions. In the Shopify Partner Dashboard, open Nexora → API access requests → Protected customer data access, request the Order data access Nexora uses, and save. For development stores, selecting and saving the data access is sufficient; public-app production access requires Shopify review.');
    }

    public function test_dashboard_only_accepts_supported_date_ranges(): void
    {
        $this->apiAs($this->shop)->getJson('/api/dashboard?days=60')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['days']);

        Http::assertNothingSent();
    }

    public function test_dashboard_follows_shopify_cursor_when_period_exceeds_one_page(): void
    {
        $createdAt = CarbonImmutable::now('America/Toronto')->startOfDay()->addHours(10)->utc()->toIso8601String();
        Http::fake([
            $this->graphqlUrl() => Http::sequence()
                ->push(['data' => ['orders' => [
                    'nodes' => [$this->order('2001', $createdAt, 'UNFULFILLED', null)],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'dashboard-cursor'],
                ]]])
                ->push(['data' => ['orders' => [
                    'nodes' => [$this->order('2002', $createdAt, 'FULFILLED', null)],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => 'final-cursor'],
                ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('data.totals.orders', 2)
            ->assertJsonPath('data.totals.fulfilled', 1);

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => json_decode($request->body(), true)['variables']['cursor'] === 'dashboard-cursor');
    }

    public function test_dashboard_counts_split_assignments_and_fulfilled_orders_per_location(): void
    {
        $store = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Store',
            'type' => 'store',
        ]);
        $thirdParty = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => '3PL',
            'type' => 'third_party',
        ]);
        $createdAt = CarbonImmutable::now('America/Toronto')->startOfDay()->addHours(10)->utc()->toIso8601String();
        $order = $this->order('3001', $createdAt, 'FULFILLED', null);
        $order['fulfillmentOrders']['nodes'] = [
            ['status' => 'CLOSED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44', 'name' => 'Store']]],
            ['status' => 'CLOSED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/45', 'name' => '3PL']]],
            ['status' => 'CLOSED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/45', 'name' => '3PL']]],
        ];
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['orders' => [
                'nodes' => [$order],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/dashboard?days=7')
            ->assertOk()
            ->assertJsonPath('data.totals.assigned', 1)
            ->assertJsonPath('data.totals.split', 1)
            ->assertJsonPath('data.distribution.0.id', (string) $store->id)
            ->assertJsonPath('data.distribution.0.value', 1)
            ->assertJsonPath('data.distribution.0.fulfilled', 1)
            ->assertJsonPath('data.distribution.1.id', (string) $thirdParty->id)
            ->assertJsonPath('data.distribution.1.value', 1)
            ->assertJsonPath('data.distribution.1.fulfilled', 1)
            ->assertJsonPath('data.byType.0.value', 1)
            ->assertJsonPath('data.byType.1.value', 1);
    }

    private function order(string $id, string $createdAt, string $status, ?string $locationId, ?string $cancelledAt = null): array
    {
        return [
            'cancelledAt' => $cancelledAt,
            'id' => "gid://shopify/Order/{$id}",
            'name' => "#{$id}",
            'createdAt' => $createdAt,
            'displayFulfillmentStatus' => $status,
            'totalQuantity' => 1,
            'shippingAddress' => ['city' => 'Toronto', 'countryCodeV2' => 'CA'],
            'shippingLines' => ['nodes' => []],
            'lineItems' => ['nodes' => []],
            'fulfillmentOrders' => ['nodes' => $locationId ? [[
                'status' => 'OPEN',
                'assignedLocation' => ['location' => [
                    'id' => $locationId,
                    'name' => 'Warehouse',
                ]],
            ]] : []],
        ];
    }
}
