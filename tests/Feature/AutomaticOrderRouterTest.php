<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Shop;
use App\Services\Routing\AutomaticOrderRouter;
use App\Services\Routing\RoutingConfigCompiler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class AutomaticOrderRouterTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    private Location $store;

    private Location $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->shop = $this->installedShop(attributes: [
            'scopes' => 'read_orders,read_inventory,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ]);
        $this->store = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/101',
            'name' => 'Store',
            'type' => Location::TYPE_STORE,
            'is_active' => true,
            'ships' => true,
            'country_code' => 'US',
            'province_code' => 'MN',
        ]);
        $this->warehouse = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/102',
            'name' => 'Warehouse',
            'type' => Location::TYPE_WAREHOUSE,
            'is_active' => true,
            'ships' => true,
            'country_code' => 'US',
            'province_code' => 'MN',
        ]);
        $settings = [
            'priority' => [$this->warehouse->id, $this->store->id],
            'prefer_local' => null,
            'no_split' => false,
            'rules' => [],
        ];
        $this->shop->routingConfig()->create([
            'settings' => $settings,
            'compiled' => app(RoutingConfigCompiler::class)->compile($this->shop, $settings),
            'schema_version' => RoutingConfigCompiler::SCHEMA_VERSION,
        ]);
    }

    public function test_new_order_moves_only_to_a_fully_stocked_routing_destination(): void
    {
        Http::fake([
            $this->graphqlUrl() => function (Request $request) {
                $query = json_decode($request->body(), true)['query'];

                if (str_contains($query, 'AutomaticRoutingOrder')) {
                    return Http::response(['data' => ['node' => $this->orderNode([
                        $this->store->shopify_id => 8,
                        $this->warehouse->shopify_id => 5,
                    ])]]);
                }

                if (str_contains($query, 'CapacityOrders')) {
                    return Http::response(['data' => ['orders' => [
                        'nodes' => [],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ]]]);
                }

                if (str_contains($query, 'OrderFulfillmentForMove')) {
                    return Http::response(['data' => ['node' => [
                        'name' => '#1001',
                        'cancelledAt' => null,
                        'displayFulfillmentStatus' => 'UNFULFILLED',
                        'fulfillmentOrders' => ['nodes' => [[
                            'id' => 'gid://shopify/FulfillmentOrder/1',
                            'status' => 'OPEN',
                            'requestStatus' => 'UNSUBMITTED',
                            'assignedLocation' => ['location' => ['id' => $this->store->shopify_id]],
                        ]]],
                    ]]]);
                }

                if (str_contains($query, 'MoveFulfillmentOrder')) {
                    return Http::response(['data' => ['fulfillmentOrderMove' => [
                        'movedFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/1',
                            'assignedLocation' => ['location' => ['id' => $this->warehouse->shopify_id]],
                        ],
                        'originalFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/1',
                            'assignedLocation' => ['location' => ['id' => $this->warehouse->shopify_id]],
                        ],
                    ]]]);
                }

                return Http::response(['errors' => [['message' => 'Unexpected query.']]]);
            },
        ]);

        app(AutomaticOrderRouter::class)->route($this->shop, 1001);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_automatically_routed',
            'subject' => '#1001',
            'summary' => 'Automatically routed the order to Warehouse using the saved routing rules.',
        ]);
        Http::assertSent(fn (Request $request) => str_contains(
            json_decode($request->body(), true)['query'],
            'fulfillmentOrderMove',
        ));
    }

    public function test_it_leaves_orders_untouched_when_inventory_cannot_be_verified(): void
    {
        Http::fake([
            $this->graphqlUrl() => function (Request $request) {
                $query = json_decode($request->body(), true)['query'];
                if (str_contains($query, 'AutomaticRoutingOrder')) {
                    $order = $this->orderNode([]);
                    $order['lineItems']['nodes'][0]['variant']['inventoryItem']['inventoryLevels']['pageInfo']['hasNextPage'] = true;

                    return Http::response(['data' => ['node' => $order]]);
                }
                if (str_contains($query, 'CapacityOrders')) {
                    return Http::response(['data' => ['orders' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]]]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        app(AutomaticOrderRouter::class)->route($this->shop, 1001);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_automatic_routing_skipped',
            'subject' => '#1001',
            'summary' => 'Product inventory could not be fully verified; no fulfillment changes were made.',
        ]);
        Http::assertNotSent(fn (Request $request) => str_contains(
            json_decode($request->body(), true)['query'],
            'fulfillmentOrderMove',
        ));
    }

    public function test_it_does_not_query_shopify_when_required_scopes_are_missing(): void
    {
        $this->shop->forceFill(['scopes' => 'read_orders'])->save();

        app(AutomaticOrderRouter::class)->route($this->shop, 1001);

        Http::assertNothingSent();
        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_automatic_routing_skipped',
            'summary' => 'Automatic routing is waiting for Shopify permissions: read_third_party_fulfillment_orders, write_merchant_managed_fulfillment_orders, read_inventory.',
        ]);
    }

    public function test_it_respects_the_configured_daily_capacity_limit(): void
    {
        $this->warehouse->forceFill(['daily_order_cap' => 1])->save();
        Http::fake([
            $this->graphqlUrl() => function (Request $request) {
                $query = json_decode($request->body(), true)['query'];
                if (str_contains($query, 'AutomaticRoutingOrder')) {
                    return Http::response(['data' => ['node' => $this->orderNode([
                        $this->store->shopify_id => 8,
                        $this->warehouse->shopify_id => 5,
                    ])]]);
                }
                if (str_contains($query, 'CapacityOrders')) {
                    return Http::response(['data' => ['orders' => [
                        'nodes' => [[
                            'cancelledAt' => null,
                            'fulfillmentOrders' => ['nodes' => [[
                                'assignedLocation' => ['location' => ['id' => $this->warehouse->shopify_id]],
                            ]]],
                        ]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ]]]);
                }

                return Http::response(['data' => []]);
            },
        ]);

        app(AutomaticOrderRouter::class)->route($this->shop, 1001);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_automatic_routing_unchanged',
            'subject' => '#1001',
        ]);
        Http::assertNotSent(fn (Request $request) => str_contains(
            json_decode($request->body(), true)['query'],
            'fulfillmentOrderMove',
        ));
    }

    private function orderNode(array $availableByLocation): array
    {
        $levels = [];
        foreach ([$this->store->shopify_id, $this->warehouse->shopify_id] as $locationId) {
            if (array_key_exists($locationId, $availableByLocation)) {
                $levels[] = [
                    'location' => ['id' => $locationId],
                    'quantities' => [['name' => 'available', 'quantity' => $availableByLocation[$locationId]]],
                ];
            }
        }

        return [
            'name' => '#1001',
            'cancelledAt' => null,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'shippingAddress' => ['countryCodeV2' => 'US', 'provinceCode' => 'MN'],
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/1',
                    'quantity' => 2,
                    'variant' => [
                        'product' => ['tags' => ['fragile']],
                        'inventoryItem' => ['inventoryLevels' => [
                            'nodes' => $levels,
                            'pageInfo' => ['hasNextPage' => false],
                        ]],
                    ],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ],
            'fulfillmentOrders' => [
                'nodes' => [[
                    'id' => 'gid://shopify/FulfillmentOrder/1',
                    'status' => 'OPEN',
                    'requestStatus' => 'UNSUBMITTED',
                    'assignedLocation' => ['location' => ['id' => $this->store->shopify_id]],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ],
        ];
    }
}
