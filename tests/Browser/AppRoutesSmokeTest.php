<?php

namespace Tests\Browser;

use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\DrivesPolaris;
use Tests\DuskTestCase;

/**
 * Smoke test for the full OMS route map: every key route renders its heading, no uncaught JS error
 * is recorded, and the "Something went wrong" boundary never appears. The dashboard, location detail
 * and placeholder pages run on mock data; /setup uses the real backend.
 */
class AppRoutesSmokeTest extends DuskTestCase
{
    use DatabaseMigrations, DrivesPolaris;

    protected function setUp(): void
    {
        parent::setUp();

        // An installed shop so /setup's /session + /locations calls succeed.
        Shop::create([
            'domain' => self::SHOP,
            'name' => 'oveelab',
            'access_token' => 'shpat_e2e',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_e2e',
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            'locations_synced_at' => now(),
        ]);
    }

    public function test_dashboard_renders_with_kpis_and_charts(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/');
            $this->waitForText($browser, 'Fulfillment overview');
            $this->assertSees($browser, [
                'Orders routed',
                'Estimated shipping savings',
                'On-time fulfillment',
                'Shipping savings trend',
                'Location capacity',
            ]);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_location_detail_renders_from_mock_data(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/locations/karachi-wh');
            $this->waitForText($browser, 'Karachi Warehouse');
            $this->assertSees($browser, ['Capacity', 'Fulfillment', 'Recent routed orders']);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_orders_list_renders_with_filters(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/orders');
            $this->waitForText($browser, 'Routed orders');
            $this->assertSees($browser, ['#1001', 'Ship cost', 'Routing', 'Fulfillment']);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_order_detail_explains_the_decision(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/orders/ord-1001');
            $this->waitForText($browser, 'Why this location?');
            $this->assertSees($browser, ['Order summary', 'Candidate locations', 'Items', 'Fulfillment timeline']);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_placeholder_routes_render(): void
    {
        $this->browse(function (Browser $browser) {
            foreach (['/savings' => 'Shipping cost savings', '/capacity' => 'Capacity & predictions', '/settings' => 'Settings'] as $path => $heading) {
                $browser->visit($path);
                $this->waitForText($browser, $heading);
                $this->assertSees($browser, ['Arriving in this build']);
                $this->assertNoPageErrors($browser);
            }
        });
    }

    /** No uncaught error, rejection or console.error was recorded by the App Bridge stub. */
    private function assertNoPageErrors(Browser $browser): void
    {
        $errors = $browser->script('return window.__errors || [];')[0];
        $this->assertSame([], $errors, 'Page recorded JS errors: '.json_encode($errors));
        $browser->assertDontSee('Something went wrong');
    }
}
