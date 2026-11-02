<?php

namespace Tests\Feature;

use App\Models\Shop;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SalesTargetApiTest extends TestCase
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

    public function test_default_is_no_target_and_no_progress(): void
    {
        $this->apiAs($this->shop)->getJson('/api/sales-target')
            ->assertOk()
            ->assertJsonPath('data.monthly_order_target', null)
            ->assertJsonPath('data.progress', null);

        Http::assertNothingSent();
    }

    public function test_setting_a_target_persists_it_and_reports_month_to_date_progress(): void
    {
        // Date orders at the start of the month so they fall in the month-to-date
        // window on any day the test runs (avoids a month-boundary flake).
        $createdAt = CarbonImmutable::now('America/Toronto')->startOfMonth()->addHours(10)->utc()->toIso8601String();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['orders' => [
                'nodes' => [
                    $this->orderNode('5001', $createdAt),
                    $this->orderNode('5002', $createdAt),
                    $this->orderNode('5003', $createdAt),
                ],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]),
        ]);

        $this->apiAs($this->shop)->putJson('/api/sales-target', ['monthly_order_target' => 10])
            ->assertOk()
            ->assertJsonPath('data.monthly_order_target', 10)
            ->assertJsonPath('data.progress.current', 3)
            ->assertJsonPath('data.progress.target', 10)
            ->assertJsonPath('data.progress.rate', 0.3);

        $this->assertDatabaseHas('sales_targets', [
            'shop_id' => $this->shop->id,
            'monthly_order_target' => 10,
        ]);
    }

    public function test_clearing_the_target_deletes_it(): void
    {
        $this->shop->salesTarget()->create(['monthly_order_target' => 50]);

        $this->apiAs($this->shop)->putJson('/api/sales-target', ['monthly_order_target' => null])
            ->assertOk()
            ->assertJsonPath('data.monthly_order_target', null)
            ->assertJsonPath('data.progress', null);

        $this->assertDatabaseMissing('sales_targets', ['shop_id' => $this->shop->id]);
        Http::assertNothingSent();
    }

    public function test_validation_rejects_non_positive_or_non_integer_targets(): void
    {
        $this->apiAs($this->shop)->putJson('/api/sales-target', ['monthly_order_target' => 0])
            ->assertStatus(422);
        $this->apiAs($this->shop)->putJson('/api/sales-target', ['monthly_order_target' => -5])
            ->assertStatus(422);
        $this->apiAs($this->shop)->putJson('/api/sales-target', ['monthly_order_target' => 'lots'])
            ->assertStatus(422);
        // `present` means the key must be supplied (null clears, absent is a 422).
        $this->apiAs($this->shop)->putJson('/api/sales-target', [])
            ->assertStatus(422);
    }

    public function test_targets_are_scoped_to_the_shop(): void
    {
        $this->shop->salesTarget()->create(['monthly_order_target' => 25]);
        $other = $this->installedShop('other.myshopify.com', [
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ]);

        $this->apiAs($other)->getJson('/api/sales-target')
            ->assertOk()
            ->assertJsonPath('data.monthly_order_target', null);

        Http::assertNothingSent();
    }

    public function test_progress_is_unavailable_when_shopify_denies_the_order_read(): void
    {
        $this->shop->salesTarget()->create(['monthly_order_target' => 20]);
        Http::fake([
            $this->graphqlUrl() => Http::response(['errors' => [[
                'message' => 'This app is not approved to access the Order object.',
                'extensions' => ['code' => 'ACCESS_DENIED'],
            ]]]),
        ]);

        // The denial must not break the endpoint: the target comes back, progress does not.
        $this->apiAs($this->shop)->getJson('/api/sales-target')
            ->assertOk()
            ->assertJsonPath('data.monthly_order_target', 20)
            ->assertJsonPath('data.progress.current', null)
            ->assertJsonPath('data.progress.target', 20)
            ->assertJsonPath('data.progress.unavailable', true);
    }

    public function test_progress_reports_missing_scopes_without_calling_shopify(): void
    {
        $this->shop->forceFill(['scopes' => 'read_orders'])->save();
        $this->shop->salesTarget()->create(['monthly_order_target' => 10]);

        $this->apiAs($this->shop)->getJson('/api/sales-target')
            ->assertOk()
            ->assertJsonPath('data.progress.current', null)
            ->assertJsonPath('data.progress.target', 10)
            ->assertJsonPath('data.progress.missing_scopes.0', 'read_third_party_fulfillment_orders');

        Http::assertNothingSent();
    }

    private function orderNode(string $id, string $createdAt): array
    {
        return [
            'cancelledAt' => null,
            'id' => "gid://shopify/Order/{$id}",
            'name' => "#{$id}",
            'createdAt' => $createdAt,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'shippingAddress' => ['city' => 'Toronto', 'countryCodeV2' => 'CA'],
            'shippingLines' => ['nodes' => []],
            'lineItems' => ['nodes' => []],
            'fulfillmentOrders' => ['nodes' => []],
        ];
    }
}
