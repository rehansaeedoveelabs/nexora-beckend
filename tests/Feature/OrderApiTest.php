<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->shop = $this->installedShop(attributes: [
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
            'currency' => 'USD',
        ]);
    }

    public function test_order_list_reads_live_shopify_data_and_omits_customer_identity(): void
    {
        $location = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
        ]);

        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => [
                'orders' => [
                    'nodes' => [$this->orderNode()],
                    'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-2'],
                ],
            ]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/orders?first=20&after=cursor-1')
            ->assertOk()
            ->assertJsonPath('data.0.id', '1001')
            ->assertJsonPath('data.0.number', '#1001')
            ->assertJsonPath('data.0.destination.city', 'Toronto')
            ->assertJsonPath('data.0.itemCount', 2)
            ->assertJsonPath('data.0.assignedLocationId', (string) $location->id)
            ->assertJsonPath('data.0.assignedLocationName', 'Warehouse')
            ->assertJsonPath('data.0.items.0.qty', 2)
            ->assertJsonPath('data.0.shippingEstimate', 8.5)
            ->assertJsonPath('data.0.reassignment.eligible', true)
            ->assertJsonPath('meta.page_info.has_next_page', true)
            ->assertJsonPath('meta.page_info.end_cursor', 'cursor-2')
            ->assertJsonMissingPath('data.0.customer');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['query'], 'orders(first: $first, after: $after')
                && ! str_contains($body['query'], 'totalQuantity')
                && $body['variables'] === ['first' => 20, 'after' => 'cursor-1'];
        });
    }

    public function test_order_list_logs_safe_details_for_shopify_graphql_errors(): void
    {
        Log::spy();
        Http::fake([
            $this->graphqlUrl() => Http::response(['errors' => [[
                'message' => 'Field "unknownField" does not exist on type "Order".',
                'path' => ['orders'],
                'extensions' => ['code' => 'GRAPHQL_VALIDATION_FAILED'],
            ]]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/orders?first=20')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Shopify is not responding right now. Try again in a moment.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Shopify Admin GraphQL returned errors'
                && $context['shop'] === $this->shop->domain
                && $context['errors'][0]['code'] === 'GRAPHQL_VALIDATION_FAILED'
                && $context['errors'][0]['path'] === ['orders']
                && $context['errors'][0]['message'] === 'Field "unknownField" does not exist on type "Order".');
    }

    public function test_order_detail_uses_the_shop_scoped_shopify_order_id(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders',
        ])->save();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['node' => $this->orderNode()]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/orders/1001')
            ->assertOk()
            ->assertJsonPath('data.id', '1001')
            ->assertJsonPath('data.number', '#1001')
            ->assertJsonPath('data.decisionReason', 'Automatic routing outcomes are recorded in Activity; detailed routing decisions are not stored on this order.')
            ->assertJsonPath('data.reassignment.permissionGranted', false)
            ->assertJsonMissingPath('data.customer');

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['variables']['id'] === 'gid://shopify/Order/1001');
    }

    public function test_order_reassignment_moves_an_open_fulfillment_and_records_activity(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();
        $source = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'is_active' => true,
            'ships' => true,
        ]);
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
        ]);

        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['data' => ['fulfillmentOrderMove' => [
                        'movedFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/88',
                            'assignedLocation' => ['location' => ['id' => $destination->shopify_id]],
                        ],
                        'originalFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/88',
                            'assignedLocation' => ['location' => ['id' => $destination->shopify_id]],
                        ],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id)]]);
            },
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.order_name', '#1001')
            ->assertJsonPath('data.destination_name', 'Store')
            ->assertJsonPath('data.partial', false);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_fulfillment_reassigned',
            'subject' => '#1001',
            'summary' => 'Moved #1001 to Store.',
        ]);
        Http::assertSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'fulfillmentOrderMove')
            && json_decode($request->body(), true)['variables'] === [
                'id' => 'gid://shopify/FulfillmentOrder/88',
                'newLocationId' => $destination->shopify_id,
            ]);
    }

    public function test_order_reassignment_requires_write_scope_before_calling_shopify(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders',
        ])->save();

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => 1,
        ])
            ->assertForbidden()
            ->assertJsonPath('meta.missing_scopes.0', 'write_merchant_managed_fulfillment_orders');

        Http::assertNothingSent();
    }

    public function test_order_reassignment_reports_when_shopify_only_moves_part_of_the_fulfillment(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();
        $source = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'is_active' => true,
            'ships' => true,
        ]);
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
        ]);
        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['data' => ['fulfillmentOrderMove' => [
                        'movedFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/90',
                            'assignedLocation' => ['location' => ['id' => $destination->shopify_id]],
                        ],
                        'originalFulfillmentOrder' => [
                            'id' => 'gid://shopify/FulfillmentOrder/88',
                            'assignedLocation' => ['location' => ['id' => $source->shopify_id]],
                        ],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id)]]);
            },
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.partial', true);

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_fulfillment_reassigned',
            'summary' => 'Shopify partially moved #1001; review its remaining fulfillment items.',
        ]);
    }

    public function test_order_reassignment_surfaces_shopify_inventory_errors(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();
        $source = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'is_active' => true,
            'ships' => true,
        ]);
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
        ]);
        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['data' => ['fulfillmentOrderMove' => [
                        'userErrors' => [[
                            'field' => ['newLocationId'],
                            'message' => 'The destination location does not stock the requested inventory.',
                        ]],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id)]]);
            },
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Shopify rejected the change: The destination location does not stock the requested inventory.');

        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_order_reassignment_surfaces_shopify_staff_permission_errors(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();
        $source = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'is_active' => true,
            'ships' => true,
        ]);
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
        ]);
        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['errors' => [[
                        'message' => 'Access denied',
                        'extensions' => ['code' => 'ACCESS_DENIED'],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id)]]);
            },
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertForbidden()
            ->assertJsonPath('message', 'Shopify denied the reassignment. Approve Nexora’s requested access and ensure your staff account can fulfill and ship orders.');

        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_order_reassignment_rejects_split_orders_without_mutating_shopify(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
        ]);
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['node' => $this->moveOrderNode('gid://shopify/Location/44', [
                ['id' => 'gid://shopify/FulfillmentOrder/88', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44']]],
                ['id' => 'gid://shopify/FulfillmentOrder/89', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/46']]],
            ])]]),
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only orders with one fulfillment order can be reassigned here.');

        Http::assertSentCount(1);
    }

    public function test_order_api_requires_the_scopes_and_does_not_call_shopify_without_them(): void
    {
        $shop = $this->installedShop('limited.myshopify.com', ['scopes' => 'read_locations']);

        $this->apiAs($shop)->getJson('/api/orders')
            ->assertForbidden()
            ->assertJsonPath('message', 'The installed Shopify permission grant does not include the access Nexora needs to read orders. Open Nexora in Shopify Admin and approve the requested permission update, then retry.')
            ->assertJsonPath('meta.missing_scopes.0', 'read_orders')
            ->assertJsonPath('meta.missing_scopes.1', 'read_third_party_fulfillment_orders');

        Http::assertNothingSent();
    }

    public function test_order_list_rejects_page_sizes_outside_shopify_connection_limits(): void
    {
        $this->apiAs($this->shop)->getJson('/api/orders?first=21')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first']);

        Http::assertNothingSent();
    }

    private function orderNode(): array
    {
        return [
            'id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'createdAt' => '2026-10-28T12:00:00Z',
            'cancelledAt' => null,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'shippingAddress' => ['city' => 'Toronto', 'countryCodeV2' => 'CA'],
            'shippingLines' => ['nodes' => [[
                'discountedPriceSet' => ['shopMoney' => ['amount' => '8.50', 'currencyCode' => 'CAD']],
            ]]],
            'lineItems' => ['nodes' => [[
                'title' => 'Sample product',
                'sku' => 'NX-100',
                'quantity' => 2,
                'variant' => ['product' => ['tags' => ['fragile']]],
            ]]],
            'fulfillmentOrders' => ['nodes' => [[
                'id' => 'gid://shopify/FulfillmentOrder/88',
                'status' => 'OPEN',
                'requestStatus' => 'UNSUBMITTED',
                'assignedLocation' => ['location' => [
                    'id' => 'gid://shopify/Location/44',
                    'name' => 'Warehouse',
                ]],
            ]]],
        ];
    }

    private function moveOrderNode(string $sourceLocationId, ?array $fulfillmentOrders = null): array
    {
        $order = $this->orderNode();
        $order['fulfillmentOrders']['nodes'] = $fulfillmentOrders ?? [[
            'id' => 'gid://shopify/FulfillmentOrder/88',
            'status' => 'OPEN',
            'requestStatus' => 'UNSUBMITTED',
            'assignedLocation' => ['location' => ['id' => $sourceLocationId]],
        ]];

        return $order;
    }
}
