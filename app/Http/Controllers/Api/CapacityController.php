<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\Shopify\ShopifyCapacityReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapacityController extends Controller
{
    private const REQUIRED_SCOPES = ['read_orders', 'read_third_party_fulfillment_orders'];

    public function __construct(private readonly ShopifyCapacityReader $capacity) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $granted = array_filter(explode(',', $shop->scopes ?? ''));
        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $granted));
        if (! in_array('read_merchant_managed_fulfillment_orders', $granted, true)
            && ! in_array('write_merchant_managed_fulfillment_orders', $granted, true)) {
            $missing[] = 'read_merchant_managed_fulfillment_orders';
        }

        if ($missing !== []) {
            return response()->json([
                'message' => 'Shopify has not granted the permissions required to calculate live capacity.',
                'meta' => ['missing_scopes' => $missing],
            ], 403);
        }

        $counts = $this->capacity->ordersByLocationToday($shop);
        $locations = $shop->locations()->orderBy('name')->get();
        $rows = $locations->map(function (Location $location) use ($counts) {
            $ordersToday = $counts['by_location'][$location->shopify_id] ?? 0;
            $dailyCap = $location->daily_order_cap;
            $utilization = $dailyCap ? $ordersToday / $dailyCap : null;

            return [
                'id' => $location->id,
                'name' => $location->name,
                'type' => $location->type,
                'dailyCap' => $dailyCap,
                'ordersToday' => $ordersToday,
                'remaining' => $dailyCap === null ? null : max(0, $dailyCap - $ordersToday),
                'backlog' => null,
                'backlogCap' => $location->backlog_cap,
                'utilization' => $utilization,
                'forecast' => match (true) {
                    $utilization === null => 'unconfigured',
                    $utilization >= 0.9 => 'overload',
                    $utilization >= 0.75 => 'watch',
                    default => 'ok',
                },
                'projectedFullHour' => null,
            ];
        });
        $configured = $rows->filter(fn (array $row) => $row['dailyCap'] !== null);
        $capacity = $configured->sum('dailyCap');
        $ordersAgainstCapacity = $configured->sum('ordersToday');

        return response()->json([
            'data' => [
                'totals' => [
                    'utilization' => $capacity > 0 ? $ordersAgainstCapacity / $capacity : null,
                    'atRisk' => $configured->whereIn('forecast', ['watch', 'overload'])->count(),
                    'backlog' => null,
                    'available' => $configured->where('forecast', 'ok')->count(),
                    'ordersToday' => $counts['total'],
                    'unconfigured' => $rows->where('forecast', 'unconfigured')->count(),
                ],
                'rows' => $rows->values(),
                'asOf' => now($shop->timezone ?: 'UTC')->toIso8601String(),
                'timezone' => $shop->timezone ?: 'UTC',
            ],
        ]);
    }
}
