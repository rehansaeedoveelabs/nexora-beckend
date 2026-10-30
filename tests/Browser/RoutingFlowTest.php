<?php

namespace Tests\Browser;

use App\Models\Location;
use App\Models\RoutingConfig;
use App\Models\Shop;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\DrivesPolaris;
use Tests\DuskTestCase;

/**
 * End-to-end: Routing page + order simulator, driven through Chrome against the real API.
 */
class RoutingFlowTest extends DuskTestCase
{
    use DatabaseMigrations, DrivesPolaris;

    private Location $store;
    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $shop = Shop::create([
            'domain' => self::SHOP,
            'name' => 'oveelab',
            'plan_name' => 'development',
            'access_token' => 'shpat_e2e',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_e2e',
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            'locations_synced_at' => now(),
        ]);

        $make = fn (string $name, ?string $type, array $extra = []) => $shop->locations()->create(array_merge([
            'shopify_id' => 'gid://shopify/Location/'.crc32($name),
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'ships' => true,
        ], $extra));

        $this->store = $make('Shop location', Location::TYPE_STORE, ['country_code' => 'US', 'province_code' => 'MN']);
        $this->warehouse = $make('Lahore warehouse', Location::TYPE_WAREHOUSE, ['country_code' => 'PK', 'province_code' => 'PB']);
        $make('My Custom Location', Location::TYPE_STORE, ['country_code' => 'CA', 'province_code' => 'ON']);
        $make('Snow City Warehouse', Location::TYPE_THIRD_PARTY, ['ships' => false]);
    }

    public function test_page_explains_priority_plan_limits_and_excluded_locations(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');

            $this->waitForText($browser, 'Location priority');
            $this->assertSees($browser, [
                'Rules are saved and testable now',
                'Lahore warehouse',
                'Shop location',
                'Shopify only lets apps enforce this order at checkout on Shopify Plus',
                'Not used for online orders: Snow City Warehouse',
                'No product rules yet',
                'Test an order',
            ]);
        });
    }

    public function test_merchant_reorders_locations_and_saves(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');
            $this->waitForText($browser, 'Location priority');

            // Default order: warehouse, then stores by name (My Custom Location, Shop location).
            // Two moves bring Shop location to the top.
            $this->clickButtonLabelled($browser, 'Move Shop location up');
            $this->clickButtonLabelled($browser, 'Move Shop location up');

            // A third click while already at the top must be ignored, not corrupt the list.
            $this->clickButtonLabelled($browser, 'Move Shop location up');

            $this->clickButton($browser, 'Save');
            $this->waitForText($browser, 'Routing saved');
        });

        $priority = RoutingConfig::first()->settings['priority'];
        $this->assertSame($this->store->id, $priority[0]);
        $this->assertCount(3, $priority);
    }

    public function test_merchant_tests_a_rule_in_the_simulator_before_saving_it(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');
            $this->waitForText($browser, 'Location priority');

            $this->clickButtonLabelled($browser, 'Move Shop location up');
            $this->clickButtonLabelled($browser, 'Move Shop location up'); // store now ranks first
            $this->clickButton($browser, 'Add a rule');
            $this->waitForText($browser, 'Rule 1');
            $this->typeInto($browser, 's-text-field', 'When an order contains products tagged', 'fragile');
            $this->waitForText($browser, 'Products tagged “fragile” will only ship from Lahore warehouse.');

            // Simulate a fragile product with the unsaved rule.
            $this->typeInto($browser, 's-text-field', 'Product tags', 'fragile');
            $this->clickButton($browser, 'Run test');

            $this->waitForText($browser, 'Ships from Lahore warehouse in one package');
            $this->assertSees($browser, [
                'Shop location can’t ship Sample product because of your rule: products tagged “fragile” only ship from warehouses.',
                'On your plan, Shopify may pick a different location',
            ]);
        });

        $this->assertSame(0, RoutingConfig::count(), 'Simulating must not save');

        $this->browse(function (Browser $browser) {
            $this->clickButton($browser, 'Save');
            $this->waitForText($browser, 'Routing saved');
        });

        $config = RoutingConfig::first();
        $this->assertSame(['fragile'], $config->settings['rules'][0]['tags']);
        $this->assertSame([$this->warehouse->shopify_id], $config->compiled['constraints'][0]['allowed_location_ids']);
    }

    public function test_keep_together_blocks_an_order_no_single_location_can_ship(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');
            $this->waitForText($browser, 'Test an order');

            $this->setToggle($browser, 'Ship every order from a single location', true);

            // Product 1 only in the store, product 2 only in the warehouse.
            $this->clickButton($browser, 'Add product');
            $this->waitForText($browser, 'Product 2');
            $this->typeInto($browser, 's-number-field', 'Lahore warehouse', '0', 0);
            $this->typeInto($browser, 's-number-field', 'My Custom Location', '0', 0);
            $this->typeInto($browser, 's-number-field', 'Shop location', '0', 1);
            $this->typeInto($browser, 's-number-field', 'My Custom Location', '0', 1);

            $this->clickButton($browser, 'Run test');
            $this->waitForText($browser, 'This order couldn’t be placed');
            $this->assertSees($browser, ['“Keep orders together” is on, and no single location can ship everything.']);

            $this->setToggle($browser, 'Ship every order from a single location', false);
            $this->clickButton($browser, 'Run test');
            $this->waitForText($browser, 'Ships in 2 packages');
        });
    }

    public function test_saving_a_rule_that_leaves_no_location_shows_a_checkout_warning(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');
            $this->waitForText($browser, 'Location priority');

            $this->clickButton($browser, 'Add a rule');
            $this->waitForText($browser, 'Rule 1');
            $this->typeInto($browser, 's-text-field', 'When an order contains products tagged', 'frozen');
            $this->setToggle($browser, 'All warehouses', false);
            $this->setToggle($browser, 'All third-party', true); // the only third-party location doesn't ship

            $this->clickButton($browser, 'Save');
            $this->waitForText($browser, 'Routing saved');
            $this->waitForText($browser, 'leaves no location able to ship them');
        });
    }

    public function test_full_page_builder_creates_and_persists_a_rule(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing/new');
            $this->waitForText($browser, 'Create routing rule');

            // Default target is "All warehouses", so the summary resolves to the one warehouse.
            $this->typeInto($browser, 's-text-field', 'When an order contains products tagged', 'fragile');
            $this->waitForText($browser, 'Products tagged “fragile” will only ship from Lahore warehouse.');

            $this->clickButton($browser, 'Create rule');
            // Redirects back to the routing page on success.
            $this->waitForText($browser, 'Location priority');
        });

        $config = RoutingConfig::first();
        $this->assertNotNull($config, 'The builder should persist the rule through /api/routing');
        $this->assertSame(['fragile'], $config->settings['rules'][0]['tags']);
        $this->assertContains('warehouse', $config->settings['rules'][0]['location_types']);
    }

    public function test_full_page_builder_requires_a_tag(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing/new');
            $this->waitForText($browser, 'Create routing rule');
            $this->clickButton($browser, 'Create rule');
            $this->waitForText($browser, 'Add at least one product tag.');
        });

        $this->assertSame(0, RoutingConfig::count());
    }

    public function test_rule_without_tags_shows_the_error_on_the_field(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/routing');
            $this->waitForText($browser, 'Location priority');

            $this->clickButton($browser, 'Add a rule');
            $this->waitForText($browser, 'Rule 1');
            $this->clickButton($browser, 'Save');

            $this->waitForText($browser, 'Fix the highlighted fields, then save again.');
            $this->waitForText($browser, 'Add at least one product tag.');
        });

        $this->assertSame(0, RoutingConfig::count());
    }
}
