<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Routing\FulfillmentDestinationGuard;

class ShopifyFulfillmentMover
{
    public function __construct(
        private readonly AdminGraphql $graphql,
        private readonly FulfillmentDestinationGuard $destinationGuard,
    ) {}

    public function move(Shop $shop, int $orderId, Location $destination): array
    {
        $evaluation = $this->destinationGuard->evaluate($shop, $orderId, $destination);
        if ($evaluation['status'] !== 'ok') {
            return [
                'status' => $evaluation['status'],
                'message' => $evaluation['message'] ?? null,
            ];
        }

        $order = $evaluation['order'];
        $fulfillmentOrder = $evaluation['fulfillment_order'];

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
