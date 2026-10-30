<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class RoutingApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    private Location $warehouse;

    private Location $store;

    private Location $untyped;

    private Location $notShipping;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $this->shop = $this->installedShop();
        $make = fn (string $name, ?string $type, array $extra = []) => $this->shop->locations()->create(array_merge([
            'shopify_id' => 'gid://shopify/Location/'.crc32($name),
            'name' => $name,
            'type' => $type,
            'is_active' => true,
            'ships' => true,
            'country_code' => 'US',
            'province_code' => 'MN',
        ], $extra));

        $this->store = $make('Shop location', Location::TYPE_STORE);
        $this->warehouse = $make('Lahore warehouse', Location::TYPE_WAREHOUSE, ['country_code' => 'PK', 'province_code' => 'PB']);
        $this->untyped = $make('My Custom Location', null, ['country_code' => 'CA', 'province_code' => 'ON']);
        $this->notShipping = $make('Snow City Warehouse', Location::TYPE_THIRD_PARTY, ['ships' => false]);
    }

    private function settings(array $overrides = []): array
    {
        return array_merge([
            'priority' => [$this->store->id, $this->warehouse->id, $this->untyped->id],
            'prefer_local' => null,
            'no_split' => false,
            'rules' => [],
        ], $overrides);
    }

    private function fragileRule(array $overrides = []): array
    {
        return array_merge([
            'id' => 'rule-1',
            'enabled' => true,
            'tags' => ['fragile'],
            'action' => 'only_from',
            'location_types' => ['warehouse'],
            'location_ids' => [],
        ], $overrides);
    }

    public function test_defaults_rank_warehouses_first_and_exclude_non_shipping_locations(): void
    {
        $this->apiAs($this->shop)->getJson('/api/routing')
            ->assertOk()
            ->assertJsonPath('data.settings.priority', [$this->warehouse->id, $this->store->id, $this->untyped->id])
            ->assertJsonPath('data.settings.no_split', false)
            ->assertJsonPath('data.not_shipping', ['Snow City Warehouse'])
            ->assertJsonPath('data.plan.is_plus', false)
            ->assertJsonCount(3, 'data.locations')
            ->assertJsonPath('data.updated_at', null);
    }

    public function test_save_persists_settings_and_compiles_to_shopify_ids(): void
    {
        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings([
            'no_split' => true,
            'prefer_local' => 'country',
            'rules' => [
                $this->fragileRule(['tags' => [' Fragile ']]),
                $this->fragileRule(['id' => 'rule-2', 'tags' => ['bulky'], 'action' => 'never_from', 'location_types' => [], 'location_ids' => [$this->store->id]]),
                $this->fragileRule(['id' => 'rule-3', 'enabled' => false, 'tags' => ['ignored']]),
            ],
        ]))->assertOk()->assertJsonPath('data.settings.rules.0.tags', ['Fragile']);

        $compiled = $this->shop->routingConfig()->first()->compiled;

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'routing_config_updated',
            'subject' => 'Routing configuration',
        ]);

        $this->assertSame(1, $compiled['version']);
        $this->assertSame([$this->store->shopify_id, $this->warehouse->shopify_id, $this->untyped->shopify_id], $compiled['ranking']);
        $this->assertTrue($compiled['no_split']);
        $this->assertSame('country', $compiled['prefer_local']);
        $this->assertSame(['fragile', 'bulky'], $compiled['tags']); // disabled rule's tags excluded

        $this->assertSame([
            ['rule_id' => 'rule-1', 'tags_any' => ['fragile'], 'allowed_location_ids' => [$this->warehouse->shopify_id]],
            ['rule_id' => 'rule-2', 'tags_any' => ['bulky'], 'allowed_location_ids' => [$this->warehouse->shopify_id, $this->untyped->shopify_id]],
        ], $compiled['constraints']);
    }

    public function test_new_shipping_locations_are_appended_to_the_ranking(): void
    {
        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings(['priority' => [$this->warehouse->id]]))->assertOk();

        $ranking = $this->shop->routingConfig()->first()->compiled['ranking'];
        $this->assertSame($this->warehouse->shopify_id, $ranking[0]);
        $this->assertCount(3, $ranking);
        $this->assertNotContains($this->notShipping->shopify_id, $ranking);
    }

    public function test_validation_rejects_bad_settings(): void
    {
        $other = $this->installedShop('other.myshopify.com');
        $foreign = $other->locations()->create(['shopify_id' => 'gid://shopify/Location/999', 'name' => 'Theirs']);

        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings([
            'priority' => [$foreign->id],
            'prefer_local' => 'planet',
            'rules' => [$this->fragileRule(['tags' => [], 'action' => 'maybe_from'])],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'priority.0', 'prefer_local', 'rules.0.tags', 'rules.0.action',
        ]);

        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings([
            'rules' => [$this->fragileRule(['location_types' => [], 'location_ids' => []])],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['rules.0.location_types']);

        $this->assertDatabaseCount('routing_configs', 0);
    }

    public function test_warnings_flag_rules_that_would_break_checkout(): void
    {
        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings([
            'no_split' => true,
            'rules' => [
                $this->fragileRule(),
                $this->fragileRule(['id' => 'rule-2', 'tags' => ['frozen'], 'location_types' => ['third_party']]),
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('data.warnings.0.code', 'rule_blocks_all')
            ->assertJsonPath('data.warnings.0.rule_id', 'rule-2')
            ->assertJsonPath('data.warnings.1.code', 'untyped_locations')
            ->assertJsonPath('data.warnings.2.code', 'no_split_with_rules');
    }

    public function test_simulator_explains_the_outcome_with_a_draft_rule(): void
    {
        $response = $this->apiAs($this->shop)->postJson('/api/routing/simulate', [
            'settings' => $this->settings(['rules' => [$this->fragileRule()]]),
            'destination' => ['country_code' => 'us', 'province_code' => 'mn'],
            'lines' => [[
                'title' => 'Blue vase',
                'quantity' => 2,
                'tags' => ['fragile'],
                'stock' => [$this->store->id => 10, $this->warehouse->id => 5],
            ]],
        ])->assertOk();

        $response
            ->assertJsonPath('data.outcome', 'single')
            ->assertJsonPath('data.shipments.0.location_name', 'Lahore warehouse')
            ->assertJsonPath('data.shipments.0.location_id', $this->warehouse->id)
            ->assertJsonPath('data.shipments.0.line_titles', ['Blue vase'])
            ->assertJsonPath('data.reasons.0.message', 'Lahore warehouse can ship the whole order, and it’s the highest-ranked location that can.')
            ->assertJsonPath('data.locations.0.name', 'Shop location')
            ->assertJsonPath('data.locations.0.location_id', $this->store->id)
            ->assertJsonPath('data.locations.0.reasons.0.message',
                'Shop location can’t ship Blue vase because of your rule: products tagged “fragile” only ship from warehouses.')
            ->assertJsonPath('data.ranking_enforced', false);

        // The draft was simulated, not saved.
        $this->assertDatabaseCount('routing_configs', 0);
    }

    public function test_simulator_uses_saved_settings_and_reports_blocked_orders(): void
    {
        $this->apiAs($this->shop)->putJson('/api/routing', $this->settings(['no_split' => true]))->assertOk();

        $this->apiAs($this->shop)->postJson('/api/routing/simulate', [
            'destination' => ['country_code' => 'US'],
            'lines' => [
                ['title' => 'Mug', 'quantity' => 1, 'stock' => [$this->store->id => 1]],
                ['title' => 'Plate', 'quantity' => 1, 'stock' => [$this->warehouse->id => 1]],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'blocked')
            ->assertJsonPath('data.reasons.0.code', 'no_split_unfulfillable');
    }

    public function test_simulator_ignores_stock_for_other_shops_locations(): void
    {
        $other = $this->installedShop('other.myshopify.com');
        $foreign = $other->locations()->create(['shopify_id' => 'gid://shopify/Location/777', 'name' => 'Theirs', 'ships' => true]);

        $this->apiAs($this->shop)->postJson('/api/routing/simulate', [
            'destination' => ['country_code' => 'US'],
            'lines' => [['title' => 'Mug', 'quantity' => 1, 'stock' => [$foreign->id => 50]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.outcome', 'blocked')
            ->assertJsonMissing(['name' => 'Theirs']);
    }

    public function test_prefer_local_uses_province_codes_from_sync(): void
    {
        $this->apiAs($this->shop)->postJson('/api/routing/simulate', [
            'settings' => $this->settings(['priority' => [$this->untyped->id, $this->warehouse->id, $this->store->id], 'prefer_local' => 'province']),
            'destination' => ['country_code' => 'US', 'province_code' => 'MN'],
            'lines' => [['title' => 'Mug', 'quantity' => 1, 'stock' => [
                $this->untyped->id => 1, $this->warehouse->id => 1, $this->store->id => 1,
            ]]],
        ])
            ->assertOk()
            ->assertJsonPath('data.shipments.0.location_name', 'Shop location')
            ->assertJsonPath('data.locations.0.reasons.0.code', 'local_match');
    }
}
