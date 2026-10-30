<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Activity\ActivityLogger;
use App\Services\Shopify\ShopifyFulfillmentMover;
use App\Services\Shopify\ShopifyOrderReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    private const REQUIRED_SCOPES = ['read_orders', 'read_third_party_fulfillment_orders'];

    public function __construct(
        private readonly ShopifyOrderReader $orders,
        private readonly ShopifyFulfillmentMover $fulfillmentMover,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'first' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'after' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);
        $shop = $this->shop($request);

        if ($response = $this->scopeError($shop)) {
            return $response;
        }

        $result = $this->orders->list($shop, (int) ($validated['first'] ?? 20), $validated['after'] ?? null);

        return response()->json([
            'data' => $result['orders'],
            'meta' => ['page_info' => $result['page_info']],
        ]);
    }

    public function show(Request $request, int $orderId): JsonResponse
    {
        $shop = $this->shop($request);

        if ($response = $this->scopeError($shop)) {
            return $response;
        }

        $order = $this->orders->find($shop, $orderId);
        if ($order === null) {
            return response()->json(['message' => 'Order not found.'], 404);
        }

        $grantedScopes = array_filter(explode(',', $shop->scopes ?? ''));
        $order['reassignment']['permissionGranted'] = in_array(
            'write_merchant_managed_fulfillment_orders',
            $grantedScopes,
            true,
        );

        return response()->json(['data' => $order]);
    }

    public function reassign(Request $request, int $orderId): JsonResponse
    {
        $validated = $request->validate([
            'destination_location_id' => ['required', 'integer'],
        ]);
        $shop = $this->shop($request);

        if ($response = $this->scopeError($shop, true)) {
            return $response;
        }

        $destination = $shop->locations()->find($validated['destination_location_id']);
        if ($destination === null) {
            return response()->json(['message' => 'Destination location not found.'], 404);
        }
        if (! $destination->is_active || ! $destination->ships || $destination->managed_by_app !== null) {
            return response()->json(['message' => 'Choose an active, merchant-managed location that fulfills online orders.'], 422);
        }

        $result = $this->fulfillmentMover->move($shop, $orderId, $destination);
        if ($result['status'] === 'not_found') {
            return response()->json(['message' => 'Order not found.'], 404);
        }
        if ($result['status'] === 'blocked') {
            return response()->json(['message' => $result['message']], 409);
        }

        $summary = $result['partial']
            ? "Shopify partially moved {$result['order_name']}; review its remaining fulfillment items."
            : "Moved {$result['order_name']} to {$result['destination_name']}.";
        $this->activity->record($shop, 'order_fulfillment_reassigned', $result['order_name'], $summary);

        return response()->json([
            'data' => [
                'order_name' => $result['order_name'],
                'destination_name' => $result['destination_name'],
                'partial' => $result['partial'],
            ],
        ]);
    }

    private function scopeError(Shop $shop, bool $forReassignment = false): ?JsonResponse
    {
        $granted = array_filter(explode(',', $shop->scopes ?? ''));
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $granted));
        $hasMerchantManagedRead = in_array('read_merchant_managed_fulfillment_orders', $granted, true)
            || in_array('write_merchant_managed_fulfillment_orders', $granted, true);

        if ($forReassignment) {
            if (! in_array('write_merchant_managed_fulfillment_orders', $granted, true)) {
                $missing[] = 'write_merchant_managed_fulfillment_orders';
            }
        } elseif (! $hasMerchantManagedRead) {
            $missing[] = 'read_merchant_managed_fulfillment_orders';
        }

        if ($missing === []) {
            return null;
        }

        return response()->json([
            'message' => in_array('write_merchant_managed_fulfillment_orders', $missing, true)
                ? 'Order reassignment is unavailable until Shopify grants Nexora the merchant-managed fulfillment write permission. Reopen Nexora in Shopify Admin and approve the requested access.'
                : 'The installed Shopify permission grant does not include the access Nexora needs to read orders. Open Nexora in Shopify Admin and approve the requested permission update, then retry.',
            'meta' => ['missing_scopes' => $missing],
        ], 403);
    }
}
