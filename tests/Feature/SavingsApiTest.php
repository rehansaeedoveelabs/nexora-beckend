<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SavingsApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shop = $this->installedShop();
    }

    public function test_savings_reports_live_operations_without_claiming_measurable_savings(): void
    {
        $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/1',
            'name' => 'Lahore warehouse',
            'is_active' => true,
            'ships' => true,
            'type' => Location::TYPE_WAREHOUSE,
        ]);
        $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/2',
            'name' => 'Pickup only',
            'is_active' => true,
            'ships' => false,
            'type' => Location::TYPE_STORE,
        ]);
        $this->shop->activityEvents()->create([
            'type' => 'order_automatically_routed',
            'subject' => '#1038',
            'summary' => 'Routed to Lahore warehouse',
            'occurred_at' => now()->subHour(),
        ]);
        $this->shop->activityEvents()->create([
            'type' => 'order_fulfillment_reassigned',
            'subject' => '#1037',
            'summary' => 'Reassigned manually',
            'occurred_at' => now()->subMinutes(30),
        ]);

        $this->apiAs($this->shop)->getJson('/api/savings')
            ->assertOk()
            ->assertJsonPath('data.measurable', false)
            ->assertJsonPath('data.reporting.status', 'unavailable')
            ->assertJsonPath('data.operations.shipping_locations.shipping', 1)
            ->assertJsonPath('data.operations.shipping_locations.typed_shipping', 1)
            ->assertJsonPath('data.operations.routing_decisions.total', 2)
            ->assertJsonPath('data.operations.routing_decisions.automatic', 1)
            ->assertJsonPath('data.operations.routing_decisions.manual', 1)
            ->assertJsonPath('data.operations.carrier_quotes.collected', false)
            ->assertJsonPath('data.operations.carrier_charges.reconciled', false)
            ->assertJsonPath('data.operations.customer_shipping.usable_for_savings', false)
            ->assertJsonPath('data.requirements.0.status', 'missing')
            ->assertJsonPath('data.requirements.1.status', 'missing')
            ->assertJsonPath('data.requirements.2.status', 'missing');
    }

    public function test_savings_is_scoped_to_the_verified_shop(): void
    {
        $other = $this->installedShop('other.myshopify.com');
        $other->locations()->create([
            'shopify_id' => 'gid://shopify/Location/9',
            'name' => 'Other warehouse',
            'is_active' => true,
            'ships' => true,
            'type' => Location::TYPE_WAREHOUSE,
        ]);
        $other->activityEvents()->create([
            'type' => 'order_automatically_routed',
            'subject' => '#1',
            'summary' => 'Other shop decision',
            'occurred_at' => now(),
        ]);

        $this->apiAs($this->shop)->getJson('/api/savings')
            ->assertOk()
            ->assertJsonPath('data.operations.shipping_locations.shipping', 0)
            ->assertJsonPath('data.operations.routing_decisions.total', 0);
    }
}
