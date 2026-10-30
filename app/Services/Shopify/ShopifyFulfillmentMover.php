<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Location;
use App\Models\Shop;

class ShopifyFulfillmentMover
{
    public function __construct(private readonly AdminGraphql $graphql) {}

    public function move(Shop $shop, int $orderId, Location $destination): array
    {
        $order = $this->graphql->query($shop, <<<'GQL'
            query OrderFulfillmentForMove($id: ID!) {
                node(id: $id) {
                    ... on Order {
                        id
                        name
                        cancelledAt
                        displayFulfillmentStatus
                        fulfillmentOrders(first: 2) {
                            nodes {
                                id
                                status
                                requestStatus
                                assignedLocation { location { id } }
                            }
                        }
                    }
                }
            }
        GQL, ['id' => "gid://shopify/Order/{$orderId}"])['node'] ?? null;

        if ($order === null) {
            return ['status' => 'not_found'];
        }

        if (($order['cancelledAt'] ?? null) !== null || ($order['displayFulfillmentStatus'] ?? null) !== 'UNFULFILLED') {
            return ['status' => 'blocked', 'message' => 'Only uncancelled, unfulfilled orders can be reassigned.'];
        }

        $fulfillmentOrders = $order['fulfillmentOrders']['nodes'] ?? [];
        if (count($fulfillmentOrders) !== 1) {
            return ['status' => 'blocked', 'message' => 'Only orders with one fulfillment order can be reassigned here.'];
        }

        $fulfillmentOrder = $fulfillmentOrders[0];
        if (($fulfillmentOrder['status'] ?? null) !== 'OPEN'
            || ! in_array($fulfillmentOrder['requestStatus'] ?? null, [null, 'UNSUBMITTED'], true)) {
            return ['status' => 'blocked', 'message' => 'Shopify has already started or requested this fulfillment, so it cannot be reassigned here.'];
        }

        $sourceShopifyId = $fulfillmentOrder['assignedLocation']['location']['id'] ?? null;
        $source = $sourceShopifyId === null
            ? null
            : $shop->locations()->where('shopify_id', $sourceShopifyId)->first(['id', 'managed_by_app']);
        if ($source === null
            || $sourceShopifyId === $destination->shopify_id
            || $source->managed_by_app !== null) {
            return ['status' => 'blocked', 'message' => 'This fulfillment is not assigned to a supported merchant-managed location.'];
        }

        try {
            $payload = $this->graphql->mutate($shop, <<<'GQL'
                mutation MoveFulfillmentOrder($id: ID!, $newLocationId: ID!) {
                    fulfillmentOrderMove(id: $id, newLocationId: $newLocationId) {
                        movedFulfillmentOrder {
                            id
                            assignedLocation { location { id } }
                        }
                        originalFulfillmentOrder {
                            id
                            assignedLocation { location { id } }
                        }
                    }
                }
            GQL, [
                'id' => $fulfillmentOrder['id'],
                'newLocationId' => $destination->shopify_id,
            ], 'fulfillmentOrderMove');
        } catch (ShopifyApiException $exception) {
            $accessDenied = $exception->status === 403
                || collect($exception->errors)
                    ->contains(fn (array $error) => ($error['extensions']['code'] ?? null) === 'ACCESS_DENIED');
            if (! $accessDenied) {
                throw $exception;
            }

            throw new ShopifyApiException(
                'Shopify denied the reassignment. Approve Nexora’s requested access and ensure your staff account can fulfill and ship orders.',
                status: 403,
            );
        }

        $moved = $payload['movedFulfillmentOrder'] ?? null;
        if ($moved === null) {
            throw new ShopifyApiException('Shopify did not confirm the fulfillment reassignment.');
        }

        $movedLocationId = $moved['assignedLocation']['location']['id'] ?? null;
        if ($movedLocationId !== $destination->shopify_id) {
            throw new ShopifyApiException('Shopify did not confirm the requested destination location.');
        }

        $remainingLocationId = $payload['originalFulfillmentOrder']['assignedLocation']['location']['id'] ?? null;
        $partial = $remainingLocationId !== null && $remainingLocationId !== $destination->shopify_id;

        return [
            'status' => 'moved',
            'order_name' => $order['name'],
            'destination_name' => $destination->name,
            'partial' => $partial,
        ];
    }
}
