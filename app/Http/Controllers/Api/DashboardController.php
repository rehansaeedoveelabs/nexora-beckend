<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Shopify\ShopifyOrderReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    private const REQUIRED_SCOPES = ['read_orders', 'read_third_party_fulfillment_orders'];

    public function __construct(private readonly ShopifyOrderReader $orders) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['sometimes', 'integer', 'in:7,14,30'],
        ]);
        $shop = $this->shop($request);
        $granted = array_filter(explode(',', $shop->scopes ?? ''));
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $granted));
        if (! in_array('read_merchant_managed_fulfillment_orders', $granted, true)
            && ! in_array('write_merchant_managed_fulfillment_orders', $granted, true)) {
            $missing[] = 'read_merchant_managed_fulfillment_orders';
        }

        if ($missing !== []) {
            return response()->json([
                'message' => 'Shopify has not granted the permissions required to load the live dashboard.',
                'meta' => ['missing_scopes' => $missing],
            ], 403);
        }

        return response()->json([
            'data' => $this->orders->overview($shop, (int) ($validated['days'] ?? 30)),
        ]);
    }
}
