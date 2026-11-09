<?php

namespace Tests\Feature;

use App\Jobs\SyncCheckoutRoutingConfig;
use App\Models\Location;
use App\Services\Routing\FunctionConfigBuilder;
use App\Services\Routing\RoutingConfigCompiler;
use App\Services\Routing\ShopifyFunctionConfigWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SyncCheckoutRoutingConfigTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    public function test_job_builds_and_persists_checkout_config_from_the_saved_rules(): void
    {
        Http::preventStrayRequests();
        $shop = $this->installedShop(attributes: [
            'scopes' => 'read_products,write_fulfillment_constraint_rules',
        ]);
        $warehouse = $shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/101',
            'name' => 'Warehouse',
            'type' => Location::TYPE_WAREHOUSE,
            'is_active' => true,
            'ships' => true,
        ]);
        $store = $shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/102',
            'name' => 'Store',
            'type' => Location::TYPE_STORE,
            'is_active' => true,
            'ships' => true,
        ]);

        $settings = [
            'priority' => [$warehouse->id, $store->id],
            'prefer_local' => null,
            'no_split' => true,
            'rules' => [[
                'id' => 'fragile',
                'enabled' => true,
                'tags' => ['fragile'],
                'action' => 'only_from',
                'location_types' => ['warehouse'],
                'location_ids' => [],
            ]],
        ];
        $shop->routingConfig()->create([
            'settings' => $settings,
            'compiled' => app(RoutingConfigCompiler::class)->compile($shop, $settings),
            'schema_version' => RoutingConfigCompiler::SCHEMA_VERSION,
            'checkout_sync_status' => 'pending',
        ]);

        Http::fake([
            $this->graphqlUrl() => function (Request $request) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'query Products')) {
                    return Http::response(['data' => ['products' => [
                        'nodes' => [[
                            'id' => 'gid://shopify/Product/501',
                            'tags' => ['fragile'],
                        ]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ]]]);
                }

                if (str_contains($body['query'], 'fulfillmentConstraintRuleCreate')) {
                    return Http::response(['data' => ['fulfillmentConstraintRuleCreate' => [
                        'fulfillmentConstraintRule' => [
                            'id' => 'gid://shopify/FulfillmentConstraintRule/10',
                            'function' => ['id' => 'gid://shopify/ShopifyFunction/20'],
                        ],
                        'userErrors' => [],
                    ]]]);
                }

                return Http::response(['errors' => [['message' => 'Unexpected query.']]]);
            },
        ]);

        (new SyncCheckoutRoutingConfig($shop->id))->handle(
            app(FunctionConfigBuilder::class),
            app(ShopifyFunctionConfigWriter::class),
        );

        $this->assertSame('synced', $shop->routingConfig()->first()->checkout_sync_status);
        $this->assertNotNull($shop->routingConfig()->first()->checkout_synced_at);
        $this->assertSame(
            'gid://shopify/FulfillmentConstraintRule/10',
            $shop->fresh()->fulfillment_constraint_rule_id,
        );
        Http::assertSent(fn (Request $request) => str_contains(
            json_decode($request->body(), true)['query'],
            'fulfillmentConstraintRuleCreate',
        ) && json_decode($request->body(), true)['variables']['metafields'][0]['value'] === json_encode([
            'version' => 1,
            'no_split' => true,
            'products' => ['gid://shopify/Product/501' => [$warehouse->shopify_id]],
        ], JSON_UNESCAPED_SLASHES));
    }
}
