<?php

namespace Tests\Feature;

use App\Jobs\BootstrapShop;
use App\Models\Location;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class LocationApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();

        $this->shop = $this->installedShop();
        $this->seedLocations($this->shop);
    }

    private function seedLocations(Shop $shop): void
    {
        $rows = [
            ['Lahore warehouse', 'Johar Town, Lahore, Pakistan', true, null],
            ['My Custom Location', '123 Main St, Toronto, Canada', true, null],
            ['Shop location', 'Gaage Ln, Hugo MN, United States', true, Location::TYPE_STORE],
            ['Snow City Warehouse', 'United States', false, Location::TYPE_THIRD_PARTY],
        ];

        foreach ($rows as $i => [$name, $address, $active, $type]) {
            $shop->locations()->create([
                'shopify_id' => "gid://shopify/Location/{$shop->id}{$i}",
                'name' => $name,
                'formatted_address' => $address,
                'is_active' => $active,
                'type' => $type,
            ]);
        }
    }

    public function test_index_returns_locations_with_counts(): void
    {
        $this->apiAs($this->shop)->getJson('/api/locations')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('meta.counts', [
                'all' => 4, 'active' => 3, 'inactive' => 1, 'untyped' => 2,
                'store' => 1, 'warehouse' => 0, 'third_party' => 1,
            ])
            ->assertJsonPath('data.0.needs_attention', true)
            ->assertJsonPath('data.3.name', 'Snow City Warehouse'); // inactive sorted last
    }

    public function test_tabs_and_search_filter(): void
    {
        $this->apiAs($this->shop)->getJson('/api/locations?tab=untyped')
            ->assertJsonCount(2, 'data');

        $this->apiAs($this->shop)->getJson('/api/locations?tab=store')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type_label', 'Store');

        $this->apiAs($this->shop)->getJson('/api/locations?q=toronto')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'My Custom Location');

        // LIKE wildcards are escaped, not interpreted.
        $this->apiAs($this->shop)->getJson('/api/locations?q=%25')->assertJsonCount(0, 'data');

        $this->apiAs($this->shop)->getJson('/api/locations?tab=bogus')->assertUnprocessable();
    }

    public function test_update_sets_merchant_configuration(): void
    {
        $id = $this->shop->locations()->where('name', 'Lahore warehouse')->value('id');

        $this->apiAs($this->shop)->patchJson("/api/locations/{$id}", [
            'type' => 'warehouse',
            'tags' => [' fragile ', 'north'],
            'daily_order_cap' => 120,
        ])
            ->assertOk()
            ->assertJsonPath('data.type', 'warehouse')
            ->assertJsonPath('data.type_label', 'Warehouse')
            ->assertJsonPath('data.tags', ['fragile', 'north'])
            ->assertJsonPath('data.daily_order_cap', 120)
            ->assertJsonPath('data.needs_attention', false)
            ->assertJsonPath('meta.counts.untyped', 1);
    }

    public function test_update_can_clear_a_cap(): void
    {
        $location = $this->shop->locations()->first();
        $location->update(['daily_order_cap' => 10]);

        $this->apiAs($this->shop)->patchJson("/api/locations/{$location->id}", ['daily_order_cap' => null])
            ->assertOk()
            ->assertJsonPath('data.daily_order_cap', null);
    }

    public function test_update_validates_input(): void
    {
        $id = $this->shop->locations()->value('id');

        $this->apiAs($this->shop)->patchJson("/api/locations/{$id}", [
            'type' => 'castle',
            'daily_order_cap' => 0,
            'tags' => ['dup', 'DUP'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'daily_order_cap', 'tags.0']);
    }

    public function test_shop_cannot_read_or_modify_another_shops_locations(): void
    {
        $other = $this->installedShop('other.myshopify.com');
        $this->seedLocations($other);
        $foreignId = $other->locations()->value('id');

        $this->apiAs($this->shop)->getJson('/api/locations')
            ->assertJsonCount(4, 'data')
            ->assertJsonMissing(['id' => $foreignId]);

        $this->apiAs($this->shop)->patchJson("/api/locations/{$foreignId}", ['type' => 'store'])->assertNotFound();

        $this->apiAs($this->shop)->patchJson('/api/locations/bulk', ['ids' => [$foreignId], 'type' => 'store'])
            ->assertOk()
            ->assertJsonPath('data.updated', 0);

        $this->assertNull(Location::find($foreignId)->type);
    }

    public function test_client_cannot_choose_the_shop(): void
    {
        $other = $this->installedShop('other.myshopify.com');

        // A `shop` parameter is ignored; the verified token decides.
        $this->apiAs($this->shop)->getJson('/api/session?shop=other.myshopify.com')
            ->assertJsonPath('data.shop.domain', 'demo.myshopify.com');
    }

    public function test_bulk_update_sets_type_for_selected_locations(): void
    {
        $ids = $this->shop->locations()->untyped()->pluck('id')->all();

        $this->apiAs($this->shop)->patchJson('/api/locations/bulk', ['ids' => $ids, 'type' => 'warehouse'])
            ->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('meta.counts.untyped', 0)
            ->assertJsonPath('meta.counts.warehouse', 2);
    }

    public function test_setup_guide_is_derived_from_location_data(): void
    {
        $this->apiAs($this->shop)->getJson('/api/setup')
            ->assertOk()
            ->assertJsonPath('data.steps.0.status', 'complete')
            ->assertJsonPath('data.steps.1.status', 'incomplete')
            ->assertJsonPath('data.steps.1.detail', '2 locations still need a type')
            ->assertJsonPath('data.steps.2.status', 'upcoming')
            ->assertJsonPath('data.steps.3.status', 'incomplete')
            ->assertJsonPath('data.completed', 1)
            ->assertJsonPath('data.total', 3);

        $this->shop->locations()->untyped()->update(['type' => 'store']);

        $this->apiAs($this->shop)->getJson('/api/setup')
            ->assertJsonPath('data.steps.1.status', 'complete')
            ->assertJsonPath('data.is_complete', false);

        // Saving routing completes the guide.
        $this->apiAs($this->shop)->putJson('/api/routing', [
            'priority' => [], 'prefer_local' => null, 'no_split' => false, 'rules' => [],
        ])->assertOk();

        $this->apiAs($this->shop)->getJson('/api/setup')
            ->assertJsonPath('data.steps.3.status', 'complete')
            ->assertJsonPath('data.completed', 3)
            ->assertJsonPath('data.is_complete', true);
    }

    public function test_manual_sync_queues_bootstrap(): void
    {
        $this->apiAs($this->shop)->postJson('/api/locations/sync')->assertStatus(202);

        Queue::assertPushed(BootstrapShop::class, fn ($job) => $job->shopId === $this->shop->id);
    }

    public function test_cors_allows_only_the_frontend_origin(): void
    {
        $this->call('OPTIONS', '/api/locations', [], [], [], [
            'HTTP_ORIGIN' => 'https://nexora-frontend.test',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'PATCH',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertHeader('Access-Control-Allow-Origin', 'https://nexora-frontend.test');

        // Every verb the frontend uses must pass preflight (PUT /api/routing once didn't).
        foreach (['GET', 'POST', 'PUT', 'PATCH'] as $method) {
            $allowed = $this->call('OPTIONS', '/api/routing', [], [], [], [
                'HTTP_ORIGIN' => 'https://nexora-frontend.test',
                'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => $method,
                'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
            ])->headers->get('Access-Control-Allow-Methods');
            $this->assertStringContainsString($method, (string) $allowed, "{$method} must pass CORS preflight");
        }

        $evil = $this->call('OPTIONS', '/api/locations', [], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example.com',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $this->assertNotSame('https://evil.example.com', $evil->headers->get('Access-Control-Allow-Origin'));
    }
}
