<?php

namespace Tests\Feature;

use App\Jobs\RouteNewOrder;
use App\Jobs\SyncLocation;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_rejects_invalid_hmac(): void
    {
        $this->installedShop();

        $this->sendWebhook('app/uninstalled', ['id' => 1], hmac: base64_encode('forged'))
            ->assertUnauthorized();

        $this->assertNotNull(Shop::first()->refresh_token);
        $this->assertDatabaseCount('webhook_receipts', 0);
    }

    public function test_rejects_body_tampered_after_signing(): void
    {
        $this->installedShop();
        $signedFor = base64_encode(hash_hmac('sha256', json_encode(['id' => 1]), config('shopify.api_secret'), true));

        $this->sendWebhook('shop/redact', ['id' => 2], hmac: $signedFor)->assertUnauthorized();

        $this->assertDatabaseCount('shops', 1);
    }

    public function test_rejects_non_shopify_shop_domain(): void
    {
        $this->sendWebhook('app/uninstalled', [], shop: 'evil.example.com')->assertStatus(400);
    }

    public function test_app_uninstalled_revokes_tokens(): void
    {
        $this->installedShop();

        $this->sendWebhook('app/uninstalled', ['id' => 1])->assertOk();

        $shop = Shop::first();
        $this->assertNotNull($shop->uninstalled_at);
        $this->assertNull($shop->access_token);
        $this->assertNull($shop->refresh_token);
        $this->assertFalse($shop->isInstalled());
    }

    public function test_duplicate_delivery_is_processed_once(): void
    {
        $shop = $this->installedShop();
        $payload = ['id' => 7, 'admin_graphql_api_id' => 'gid://shopify/Location/7'];

        $this->sendWebhook('locations/update', $payload, webhookId: 'same-id')->assertOk()->assertJson(['status' => 'ok']);
        $this->sendWebhook('locations/update', $payload, webhookId: 'same-id')->assertOk()->assertJson(['status' => 'duplicate']);

        Queue::assertPushed(SyncLocation::class, 1);
        Queue::assertPushed(SyncLocation::class, fn ($job) => $job->shopId === $shop->id
            && $job->gid === 'gid://shopify/Location/7' && $job->deleted === false);
    }

    public function test_location_delete_queues_removal_from_numeric_id(): void
    {
        $this->installedShop();

        $this->sendWebhook('locations/delete', ['id' => 99])->assertOk();

        Queue::assertPushed(SyncLocation::class, fn ($job) => $job->gid === 'gid://shopify/Location/99' && $job->deleted);
    }

    public function test_location_webhook_for_uninstalled_shop_is_ignored(): void
    {
        $this->installedShop(attributes: ['uninstalled_at' => now(), 'refresh_token' => null]);

        $this->sendWebhook('locations/create', ['id' => 5])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_order_webhooks_store_only_the_order_reference_and_generic_event_summary(): void
    {
        $shop = $this->installedShop();
        $payload = [
            'id' => 12345,
            'name' => '#1042',
            'email' => 'customer@example.com',
            'customer' => ['first_name' => 'Private', 'last_name' => 'Customer'],
            'shipping_address' => ['address1' => '1 Private Street'],
        ];

        $this->sendWebhook('orders/create', $payload, webhookId: 'order-created')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
        $this->sendWebhook('orders/create', $payload, webhookId: 'order-created')
            ->assertOk()
            ->assertJson(['status' => 'duplicate']);
        $this->sendWebhook('fulfillments/update', [
            'order_id' => 12345,
            'order_name' => '#1042',
            'tracking_number' => 'private-tracking-value',
            'tracking_company' => 'Private Carrier',
        ], webhookId: 'fulfillment-updated')->assertOk();

        $this->assertDatabaseCount('activity_events', 2);
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'type' => 'shopify_order_created',
            'subject' => '#1042',
            'summary' => 'Shopify reported a new order.',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $shop->id,
            'type' => 'shopify_fulfillment_updated',
            'subject' => '#1042',
            'summary' => 'Shopify reported a fulfillment update.',
        ]);
        $stored = \DB::table('activity_events')->get()->toJson();
        $this->assertStringNotContainsString('customer@example.com', $stored);
        $this->assertStringNotContainsString('Private Customer', $stored);
        $this->assertStringNotContainsString('1 Private Street', $stored);
        $this->assertStringNotContainsString('private-tracking-value', $stored);
        Queue::assertPushed(RouteNewOrder::class, 1);
        Queue::assertPushed(RouteNewOrder::class, fn ($job) => $job->shopId === $shop->id
            && $job->orderId === 12345 && $job->connection === 'database');
    }

    public function test_shop_redact_deletes_all_shop_data(): void
    {
        $shop = $this->installedShop();
        $shop->locations()->create(['shopify_id' => 'gid://shopify/Location/1', 'name' => 'Lahore warehouse']);
        $other = $this->installedShop('other.myshopify.com');

        $this->sendWebhook('shop/redact', ['shop_domain' => 'demo.myshopify.com'])->assertOk();

        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
        $this->assertDatabaseCount('locations', 0);
        $this->assertDatabaseHas('shops', ['id' => $other->id]);
    }

    public function test_customer_compliance_webhooks_are_acknowledged(): void
    {
        $this->installedShop();

        $this->sendWebhook('customers/data_request', ['customer' => ['id' => 1]])->assertOk();
        $this->sendWebhook('customers/redact', ['customer' => ['id' => 1]])->assertOk();

        $this->assertDatabaseCount('webhook_receipts', 2);
        $this->assertSame(0, \DB::table('webhook_receipts')->whereNull('processed_at')->count());
    }

    public function test_scopes_update_is_recorded(): void
    {
        $this->installedShop();

        $this->sendWebhook('app/scopes_update', ['current' => ['read_locations', 'read_orders']])->assertOk();

        $this->assertSame('read_locations,read_orders', Shop::first()->scopes);
    }
}
