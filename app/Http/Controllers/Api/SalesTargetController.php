<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ShopifyApiException;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Services\Activity\ActivityLogger;
use App\Services\Shopify\ShopifyOrderReader;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesTargetController extends Controller
{
    // Progress reuses the dashboard order read, so it needs the same order scopes.
    private const REQUIRED_SCOPES = ['read_orders', 'read_third_party_fulfillment_orders'];

    public function __construct(
        private readonly ShopifyOrderReader $orders,
        private readonly ActivityLogger $activity,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $target = $shop->salesTarget?->monthly_order_target;

        return response()->json(['data' => [
            'monthly_order_target' => $target,
            'progress' => $this->progress($shop, $target),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'monthly_order_target' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $shop = $this->shop($request);
        $target = $validated['monthly_order_target'];

        if ($target === null) {
            $shop->salesTarget()->delete();
            $summary = 'Cleared the monthly order target.';
        } else {
            $shop->salesTarget()->updateOrCreate([], ['monthly_order_target' => $target]);
            $summary = "Set the monthly order target to {$target} orders.";
        }
        $this->activity->record($shop, 'sales_target_updated', 'Monthly order target', $summary);

        return response()->json(['data' => [
            'monthly_order_target' => $target,
            'progress' => $this->progress($shop, $target),
        ]]);
    }

    /**
     * Calendar-month-to-date order count vs. the target, in the shop timezone.
     * Returns null when no target is set, and a scope notice (no live read) when the
     * order scopes are missing.
     *
     * @return array<string, mixed>|null
     */
    private function progress(Shop $shop, ?int $target): ?array
    {
        if ($target === null) {
            return null;
        }

        $granted = array_filter(explode(',', $shop->scopes ?? ''));
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $granted));
        if (! in_array('read_merchant_managed_fulfillment_orders', $granted, true)
            && ! in_array('write_merchant_managed_fulfillment_orders', $granted, true)) {
            $missing[] = 'read_merchant_managed_fulfillment_orders';
        }
        if ($missing !== []) {
            return ['current' => null, 'target' => $target, 'rate' => null, 'missing_scopes' => $missing];
        }

        // overview(dayOfMonth) counts from the start of the current month to today.
        $dayOfMonth = CarbonImmutable::now($shop->timezone ?: 'UTC')->day;
        try {
            $current = $this->orders->overview($shop, $dayOfMonth)['totals']['orders'];
        } catch (ShopifyApiException) {
            // e.g. Shopify denies the Order object until Protected Customer Data is approved.
            // The target must still come back; progress is simply unavailable.
            return ['current' => null, 'target' => $target, 'rate' => null, 'unavailable' => true];
        }

        return [
            'current' => $current,
            'target' => $target,
            'rate' => $target > 0 ? $current / $target : 0,
        ];
    }
}
