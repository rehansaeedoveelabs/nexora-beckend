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
            ->assertJsonPath('data.0.routingStatus', 'not_recorded')
            ->assertJsonPath('data.0.fulfillmentOrders.0.id', '88')
            ->assertJsonPath('data.0.fulfillmentOrders.0.status', 'OPEN')
            ->assertJsonPath('data.0.fulfillmentOrders.0.requestStatus', 'UNSUBMITTED')
            ->assertJsonPath('data.0.fulfillmentOrders.0.assignedLocationId', (string) $location->id)
            ->assertJsonPath('meta.page_info.has_next_page', true)
            ->assertJsonPath('meta.page_info.end_cursor', 'cursor-2')
            ->assertJsonMissingPath('data.0.customer');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['query'], 'orders(first: $first, after: $after')
                && str_contains($body['query'], 'requestStatus')
                && ! str_contains($body['query'], 'totalQuantity')
                && $body['variables'] === ['first' => 20, 'after' => 'cursor-1'];
        });
    }

    public function test_order_list_marks_submitted_fulfillment_orders_ineligible_like_the_mover(): void
    {
        $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
        ]);

        $order = $this->orderNode();
        $order['fulfillmentOrders']['nodes'][0]['requestStatus'] = 'SUBMITTED';

        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => [
                'orders' => [
                    'nodes' => [$order],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ],
            ]]),
        ]);

        $this->apiAs($this->shop)->getJson('/api/orders?first=5')
            ->assertOk()
            ->assertJsonPath('data.0.reassignment.eligible', false)
            ->assertJsonPath(
                'data.0.reassignment.reason',
                'Shopify has already started or requested this fulfillment, so it cannot be reassigned here.',
            )
            ->assertJsonPath('data.0.fulfillmentOrders.0.requestStatus', 'SUBMITTED');
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

    public function test_order_list_handles_a_string_graphql_errors_payload(): void
    {
        Log::spy();
        Http::fake([
            $this->graphqlUrl() => Http::response(['errors' => 'Temporary upstream failure']),
        ]);

        $this->apiAs($this->shop)->getJson('/api/orders?first=20')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Shopify is not responding right now. Try again in a moment.');

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => $message === 'Shopify Admin GraphQL returned errors'
                && $context['shop'] === $this->shop->domain
                && $context['errors'][0]['message'] === 'Temporary upstream failure');
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
        [$source, $destination] = $this->reassignmentLocations();

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

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id, destinationShopifyId: $destination->shopify_id)]]);
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

    public function test_reassignment_emits_flow_trigger_and_a_failed_emit_never_blocks_the_move(): void
    {
        config(['shopify.flow_triggers.order_rerouted' => 'nexora-order-rerouted']);

        [$source, $destination] = $this->reassignmentLocations();

        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'flowTriggerReceive')) {
                    // Simulate an undeployed/rejected trigger: Shopify returns userErrors.
                    return Http::response(['data' => ['flowTriggerReceive' => [
                        'userErrors' => [['field' => null, 'message' => 'No Flow trigger found for handle.']],
                    ]]]);
                }
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

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id, destinationShopifyId: $destination->shopify_id)]]);
            },
        ]);

        // The move must still succeed and be recorded even though the trigger failed.
        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])->assertOk()->assertJsonPath('data.order_name', '#1001');

        $this->assertDatabaseHas('activity_events', [
            'shop_id' => $this->shop->id,
            'type' => 'order_fulfillment_reassigned',
            'subject' => '#1001',
        ]);

        // The trigger was attempted with the exact schema payload: numeric order id
        // (Flow reference convention), and no stray keys (strict === rejects order_name).
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return str_contains($body['query'], 'flowTriggerReceive')
                && $body['variables']['handle'] === 'nexora-order-rerouted'
                && $body['variables']['payload'] === [
                    'order_id' => 1001,
                    'destination' => 'Store',
                    'partial' => false,
                    'trigger' => 'manual',
                ];
        });
    }

    public function test_order_reassignment_requires_write_scope_before_calling_shopify(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_inventory,read_merchant_managed_fulfillment_orders,read_third_party_fulfillment_orders',
        ])->save();

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => 1,
        ])
            ->assertForbidden()
            ->assertJsonPath('meta.missing_scopes.0', 'write_merchant_managed_fulfillment_orders');

        Http::assertNothingSent();
    }

    public function test_order_reassignment_requires_inventory_read_scope_before_calling_shopify(): void
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => 1,
        ])
            ->assertForbidden()
            ->assertJsonPath('meta.missing_scopes.0', 'read_inventory');

        Http::assertNothingSent();
    }

    public function test_order_reassignment_reports_when_shopify_only_moves_part_of_the_fulfillment(): void
    {
        [$source, $destination] = $this->reassignmentLocations();
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

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id, destinationShopifyId: $destination->shopify_id)]]);
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
        [$source, $destination] = $this->reassignmentLocations();
        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['data' => ['fulfillmentOrderMove' => [
                        'userErrors' => [[
                            'field' => ['newLocationId'],
                            'message' => 'The destination location does not stock the requested inventory.',
                        ]],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id, destinationShopifyId: $destination->shopify_id)]]);
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
        [$source, $destination] = $this->reassignmentLocations();
        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'MoveFulfillmentOrder')) {
                    return Http::response(['errors' => [[
                        'message' => 'Access denied',
                        'extensions' => ['code' => 'ACCESS_DENIED'],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode($source->shopify_id, destinationShopifyId: $destination->shopify_id)]]);
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
        [, $destination] = $this->reassignmentLocations();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['node' => $this->moveOrderNode(
                'gid://shopify/Location/44',
                fulfillmentOrders: [
                    ['id' => 'gid://shopify/FulfillmentOrder/88', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/44']]],
                    ['id' => 'gid://shopify/FulfillmentOrder/89', 'status' => 'OPEN', 'requestStatus' => 'UNSUBMITTED', 'assignedLocation' => ['location' => ['id' => 'gid://shopify/Location/46']]],
                ],
                destinationShopifyId: $destination->shopify_id,
            )]]),
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Only orders with one fulfillment order can be reassigned here.');

        Http::assertSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'OrderForFulfillmentMove'));
        Http::assertNotSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'fulfillmentOrderMove'));
    }

    public function test_order_reassignment_blocks_missing_destination_inventory_before_mutating_shopify(): void
    {
        [$source, $destination] = $this->reassignmentLocations();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['node' => $this->moveOrderNode(
                $source->shopify_id,
                destinationShopifyId: $destination->shopify_id,
                destinationAvailable: null,
            )]]),
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Inventory at Store could not be verified for every item (missing inventory level).');

        Http::assertNotSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'fulfillmentOrderMove'));
        $this->assertDatabaseCount('activity_events', 0);
    }

    public function test_order_reassignment_blocks_insufficient_destination_stock_before_mutating_shopify(): void
    {
        [$source, $destination] = $this->reassignmentLocations();
        Http::fake([
            $this->graphqlUrl() => Http::response(['data' => ['node' => $this->moveOrderNode(
                $source->shopify_id,
                destinationShopifyId: $destination->shopify_id,
                destinationAvailable: 1,
            )]]),
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Store does not have enough available inventory (after safety stock) to fulfill every item on this order.');

        Http::assertNotSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'fulfillmentOrderMove'));
    }

    public function test_order_reassignment_blocks_at_capacity_destination_before_mutating_shopify(): void
    {
        [$source, $destination] = $this->reassignmentLocations();
        $destination->forceFill(['daily_order_cap' => 1])->save();

        Http::fake([
            $this->graphqlUrl() => function ($request) use ($source, $destination) {
                $body = json_decode($request->body(), true);
                if (str_contains($body['query'], 'CapacityOrders')) {
                    return Http::response(['data' => ['orders' => [
                        'nodes' => [[
                            'cancelledAt' => null,
                            'fulfillmentOrders' => ['nodes' => [[
                                'assignedLocation' => ['location' => ['id' => $destination->shopify_id]],
                            ]]],
                        ]],
                        'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    ]]]);
                }

                return Http::response(['data' => ['node' => $this->moveOrderNode(
                    $source->shopify_id,
                    destinationShopifyId: $destination->shopify_id,
                )]]);
            },
        ]);

        $this->apiAs($this->shop)->postJson('/api/orders/1001/reassign', [
            'destination_location_id' => $destination->id,
        ])
            ->assertStatus(409)
            ->assertJsonPath('message', 'Store is at its daily order capacity, so this order cannot be moved there.');

        Http::assertNotSent(fn ($request) => str_contains(json_decode($request->body(), true)['query'], 'fulfillmentOrderMove'));
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

    private function reassignmentLocations(): array
    {
        $this->shop->forceFill([
            'scopes' => 'read_orders,read_inventory,read_third_party_fulfillment_orders,write_merchant_managed_fulfillment_orders',
        ])->save();

        $source = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/44',
            'name' => 'Warehouse',
            'is_active' => true,
            'ships' => true,
            'country_code' => 'CA',
            'province_code' => 'ON',
        ]);
        $destination = $this->shop->locations()->create([
            'shopify_id' => 'gid://shopify/Location/45',
            'name' => 'Store',
            'is_active' => true,
            'ships' => true,
            'country_code' => 'CA',
            'province_code' => 'ON',
        ]);

        return [$source, $destination];
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

    private function moveOrderNode(
        string $sourceLocationId,
        ?array $fulfillmentOrders = null,
        ?string $destinationShopifyId = 'gid://shopify/Location/45',
        ?int $destinationAvailable = 5,
        int $sourceAvailable = 5,
    ): array {
        $levels = [[
            'location' => ['id' => $sourceLocationId],
            'quantities' => [
                ['name' => 'available', 'quantity' => $sourceAvailable],
                ['name' => 'safety_stock', 'quantity' => 0],
            ],
        ]];
        if ($destinationShopifyId !== null && $destinationAvailable !== null) {
            $levels[] = [
                'location' => ['id' => $destinationShopifyId],
                'quantities' => [
                    ['name' => 'available', 'quantity' => $destinationAvailable],
                    ['name' => 'safety_stock', 'quantity' => 0],
                ],
            ];
        }

        return [
            'id' => 'gid://shopify/Order/1001',
            'name' => '#1001',
            'cancelledAt' => null,
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'shippingAddress' => ['countryCodeV2' => 'CA', 'provinceCode' => 'ON'],
            'lineItems' => [
                'nodes' => [[
                    'id' => 'gid://shopify/LineItem/1',
                    'quantity' => 2,
                    'variant' => [
                        'product' => ['tags' => []],
                        'inventoryItem' => [
                            'inventoryLevels' => [
                                'nodes' => $levels,
                                'pageInfo' => ['hasNextPage' => false],
                            ],
                        ],
                    ],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ],
            'fulfillmentOrders' => [
                'nodes' => $fulfillmentOrders ?? [[
                    'id' => 'gid://shopify/FulfillmentOrder/88',
                    'status' => 'OPEN',
                    'requestStatus' => 'UNSUBMITTED',
                    'assignedLocation' => ['location' => ['id' => $sourceLocationId]],
                ]],
                'pageInfo' => ['hasNextPage' => false],
            ],
        ];
    }
}
