<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Services\Routing\ShopifyFunctionConfigWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class ShopifyFunctionConfigWriterTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_it_creates_a_constraint_rule_with_the_function_config_metafield(): void
    {
        $shop = $this->installedShop();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => [
                'fulfillmentConstraintRuleCreate' => [
                    'fulfillmentConstraintRule' => [
                        'id' => 'gid://shopify/FulfillmentConstraintRule/10',
                        'function' => ['id' => 'gid://shopify/ShopifyFunction/20'],
                    ],
                    'userErrors' => [],
                ],
            ]]),
        ]);

        app(ShopifyFunctionConfigWriter::class)->sync($shop, [
            'version' => 1,
            'no_split' => true,
            'products' => ['gid://shopify/Product/1' => []],
        ]);

        $this->assertSame('gid://shopify/FulfillmentConstraintRule/10', $shop->fresh()->fulfillment_constraint_rule_id);
        $this->assertSame('gid://shopify/ShopifyFunction/20', $shop->fresh()->fulfillment_function_id);
        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $variables = $body['variables'];
            $metafield = $variables['metafields'][0];

            return str_contains($body['query'], 'fulfillmentConstraintRuleCreate')
                && $variables['functionHandle'] === 'nexora-fulfillment-constraints'
                && $variables['deliveryMethodTypes'] === ['SHIPPING']
                && $metafield['namespace'] === '$app:fulfillment-constraints'
                && $metafield['key'] === 'function-configuration'
                && $metafield['type'] === 'json'
                && json_decode($metafield['value'], true)['products']['gid://shopify/Product/1'] === [];
        });
    }

    public function test_it_updates_the_existing_rule_metafield_without_creating_a_second_rule(): void
    {
        $shop = $this->installedShop(attributes: [
            'fulfillment_constraint_rule_id' => 'gid://shopify/FulfillmentConstraintRule/10',
        ]);
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => [
                'metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/30']], 'userErrors' => []],
            ]]),
        ]);

        app(ShopifyFunctionConfigWriter::class)->sync($shop, ['version' => 1, 'no_split' => false, 'products' => []]);

        Http::assertSent(function (Request $request) {
            $body = json_decode($request->body(), true);
            $metafield = $body['variables']['metafields'][0];

            return str_contains($body['query'], 'metafieldsSet')
                && ! str_contains($body['query'], 'fulfillmentConstraintRuleCreate')
                && $metafield['ownerId'] === 'gid://shopify/FulfillmentConstraintRule/10'
                && $metafield['namespace'] === '$app:fulfillment-constraints';
        });
    }

    public function test_it_does_not_store_rule_ids_when_shopify_rejects_creation(): void
    {
        $shop = $this->installedShop();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => [
                'fulfillmentConstraintRuleCreate' => [
                    'fulfillmentConstraintRule' => null,
                    'userErrors' => [['field' => ['functionHandle'], 'message' => 'Function is unavailable.']],
                ],
            ]]),
        ]);

        try {
            app(ShopifyFunctionConfigWriter::class)->sync($shop, ['version' => 1, 'no_split' => false, 'products' => []]);
            $this->fail('A rejected Shopify mutation must throw.');
        } catch (ShopifyApiException $exception) {
            $this->assertStringContainsString('Shopify rejected the change', $exception->getMessage());
        }

        $this->assertNull($shop->fresh()->fulfillment_constraint_rule_id);
        $this->assertNull($shop->fresh()->fulfillment_function_id);
    }

    public function test_it_rejects_oversized_function_configs_before_calling_shopify(): void
    {
        $shop = $this->installedShop();

        $this->expectException(ShopifyApiException::class);
        $this->expectExceptionMessage('Checkout configuration is too large');

        app(ShopifyFunctionConfigWriter::class)->sync($shop, [
            'version' => 1,
            'no_split' => false,
            'products' => ['oversized' => str_repeat('x', 60_001)],
        ]);
    }
}
