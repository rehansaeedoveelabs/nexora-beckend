<?php

namespace Tests\Feature;

use App\Jobs\BootstrapShop;
use App\Jobs\SyncLocation;
use App\Models\Location;
use App\Services\Locations\LocationSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class LocationSyncTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Sleep::fake();
    }

    private function page(array $nodes, ?string $next = null): array
    {
        return ['data' => ['locations' => [
            'pageInfo' => ['hasNextPage' => $next !== null, 'endCursor' => $next],
            'nodes' => $nodes,
        ]]];
    }

    public function test_full_sync_paginates_maps_fields_and_removes_stale_locations(): void
    {
        $shop = $this->installedShop(attributes: ['locations_synced_at' => null]);
        $shop->locations()->create(['shopify_id' => 'gid://shopify/Location/999', 'name' => 'Closed store']);

        Http::fakeSequence($this->graphqlUrl())
            ->push($this->page([$this->locationNode(1, 'My Custom Location')], 'cursor-1'))
            ->push($this->page([
                $this->locationNode(2, 'Shop location', ['localPickupSettingsV2' => ['pickupTime' => 'ONE_HOUR']]),
                $this->locationNode(3, 'Snow City Warehouse', [
                    'fulfillsOnlineOrders' => false,
                    'isActive' => false,
                    'fulfillmentService' => ['serviceName' => 'Snow City 3PL'],
                ]),
            ]));

        $count = app(LocationSync::class)->syncAll($shop);

        $this->assertSame(3, $count);
        $this->assertDatabaseMissing('locations', ['shopify_id' => 'gid://shopify/Location/999']);
        $this->assertNotNull($shop->fresh()->locations_synced_at);

        $first = Location::where('shopify_id', 'gid://shopify/Location/1')->first();
        $this->assertSame('123 Main St, Toronto ON A1A 1A1, Canada', $first->formatted_address);
        $this->assertSame('CA', $first->country_code);
        $this->assertTrue($first->ships);
        $this->assertFalse($first->pickup);
        $this->assertNull($first->type);

        $this->assertTrue(Location::where('name', 'Shop location')->value('pickup'));

        $thirdParty = Location::where('name', 'Snow City Warehouse')->first();
        $this->assertSame('Snow City 3PL', $thirdParty->managed_by_app);
        $this->assertSame(Location::TYPE_THIRD_PARTY, $thirdParty->type);
        $this->assertFalse($thirdParty->is_active);

        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Shopify-Access-Token', 'shpat_test_access')
            && (((array) $r['variables'])['cursor'] ?? null) === 'cursor-1');
    }

    public function test_resync_never_overwrites_merchant_configuration(): void
    {
        $shop = $this->installedShop();
        $shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/1',
            'name' => 'Old name',
            'type' => Location::TYPE_STORE,
            'tags' => ['flagship'],
            'daily_order_cap' => 40,
        ]);

        Http::fake([$this->graphqlUrl() => Http::response($this->page([$this->locationNode(1, 'New name')]))]);

        app(LocationSync::class)->syncAll($shop);

        $location = Location::first();
        $this->assertSame('New name', $location->name);
        $this->assertSame(Location::TYPE_STORE, $location->type);
        $this->assertSame(['flagship'], $location->tags);
        $this->assertSame(40, $location->daily_order_cap);
    }

    public function test_sync_one_removes_location_shopify_no_longer_has(): void
    {
        $shop = $this->installedShop();
        $shop->locations()->create(['shopify_id' => 'gid://shopify/Location/5', 'name' => 'Gone']);

        Http::fake([$this->graphqlUrl() => Http::response(['data' => ['location' => null]])]);

        (new SyncLocation($shop->id, 'gid://shopify/Location/5'))->handle(app(LocationSync::class));

        $this->assertDatabaseCount('locations', 0);
    }

    public function test_throttled_request_is_retried(): void
    {
        $shop = $this->installedShop();

        Http::fakeSequence($this->graphqlUrl())
            ->push(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]])
            ->push(['data' => ['location' => $this->locationNode(8, 'Retried')]]);

        app(LocationSync::class)->syncOne($shop, 'gid://shopify/Location/8');

        $this->assertDatabaseHas('locations', ['name' => 'Retried']);
        Http::assertSentCount(2);
    }

    public function test_bootstrap_job_syncs_shop_details_and_locations(): void
    {
        $shop = $this->installedShop(attributes: ['locations_synced_at' => null]);

        Http::fakeSequence($this->graphqlUrl())
            ->push(['data' => ['shop' => [
                'name' => 'oveelab',
                'currencyCode' => 'USD',
                'ianaTimezone' => 'Asia/Karachi',
                'plan' => ['shopifyPlus' => false, 'partnerDevelopment' => true],
            ]]])
            ->push($this->page([$this->locationNode(1, 'Lahore warehouse')]));

        app()->call([new BootstrapShop($shop->id), 'handle']);

        $shop->refresh();
        $this->assertSame('oveelab', $shop->name);
        $this->assertSame('development', $shop->plan_name);
        $this->assertSame('Asia/Karachi', $shop->timezone);
        $this->assertDatabaseHas('locations', ['name' => 'Lahore warehouse']);
    }

    public function test_bootstrap_job_skips_uninstalled_shop(): void
    {
        $shop = $this->installedShop(attributes: ['uninstalled_at' => now()]);

        app()->call([new BootstrapShop($shop->id), 'handle']);

        Http::assertNothingSent();
    }
}
