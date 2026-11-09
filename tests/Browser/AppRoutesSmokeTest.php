<?php

namespace Tests\Browser;

use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\DrivesPolaris;
use Tests\DuskTestCase;

/**
 * Smoke test for current live-data routes and truthful integration-status pages. Shopify API access
 * is not stubbed, so data-backed routes are checked for a recoverable error state rather than demo data.
 */
class AppRoutesSmokeTest extends DuskTestCase
{
    use DatabaseMigrations, DrivesPolaris;

    protected function setUp(): void
    {
        parent::setUp();

        // An installed shop so /setup's /session + /locations calls succeed.
        Shop::firstOrCreate(['domain' => self::SHOP], [
            'name' => 'oveelab',
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
            'access_token' => 'shpat_e2e',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_e2e',
            'refresh_token_expires_at' => now()->addDays(90),

            'installed_at' => now(),
            'locations_synced_at' => now(),
        ]);
    }

    public function test_dashboard_shows_a_recoverable_live_data_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/');
            $this->waitForText($browser, 'Fulfillment overview');
            $this->waitForText($browser, 'We couldn’t load this page');
            $this->assertSees($browser, ['Shopify']);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_orders_page_shows_a_recoverable_live_data_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/orders');
            $this->waitForText($browser, 'Orders');
            $this->waitForText($browser, 'We couldn’t load this page');
            $this->assertSees($browser, ['Shopify']);
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_order_detail_shows_a_recoverable_live_data_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/orders/1001');
            $this->waitForText($browser, 'Order');
            $this->waitForText($browser, 'We couldn’t load this page');
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_analytics_page_shows_a_recoverable_live_data_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/analytics');
            $this->waitForText($browser, 'Fulfillment analytics');
            $this->waitForText($browser, 'We couldn’t load this page');
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_automation_gallery_distinguishes_available_and_unavailable_templates(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/automations');
            $this->waitForText($browser, 'Templates');
            $this->assertSees($browser, [
                'Automatically route eligible new orders',
                'Implemented',
                'Not available yet',
                'Automatic routing',
            ]);

            $this->typeInto($browser, 's-search-field', 'Search automation templates', 'capacity');
            $this->waitForText($browser, 'Get an alert when a location reaches capacity');
            $this->assertDoesNotSee($browser, 'Automatically route eligible new orders');
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_settings_shows_shop_data_and_navigates_between_supported_sections(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/settings');
            $this->waitForText($browser, 'Store information');
            $this->assertSees($browser, [
                'Configure your Nexora preferences and store information.',
                self::SHOP,
                'Nexora configuration',
                'Managed by Shopify',
            ]);

            $this->clickButton($browser, 'Fulfillment');
            $this->waitForText($browser, 'Fulfillment configuration');
            $this->assertSees($browser, ['Manage locations', 'Open routing rules']);

            $this->clickButton($browser, 'Routing');
            $this->waitForText($browser, 'Routing overview');
            $this->assertSees($browser, ['Nexora defaults · not saved', 'Manage routing rules']);

            $this->clickButton($browser, 'Advanced');
            $this->waitForText($browser, 'Operational diagnostics');
            $this->assertSees($browser, ['Checkout rule sync', 'Managed by Nexora', 'not merchant-configurable']);

            $this->clickButton($browser, 'Notifications');
            $this->waitForText($browser, 'Notification delivery isn’t connected');
            $this->assertNoPageErrors($browser);
        });
    }

    public function test_integration_status_routes_explain_unavailable_features(): void
    {
        $this->browse(function (Browser $browser) {
            foreach ([
                '/savings' => ['Shipping savings', 'Savings are not yet measurable', 'Live operational data'],
                '/settings/notifications' => ['Settings', 'Notification delivery isn’t connected'],
                '/settings/billing' => ['Plan & usage', 'Shopify Billing isn’t connected'],
            ] as $path => [$heading, $status]) {
                $browser->visit($path);
                $this->waitForText($browser, $heading);
                $this->assertSees($browser, [$status]);
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
