<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Jobs\BootstrapShop;
use App\Models\Location;
use App\Services\Activity\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    private const TABS = ['all', 'active', 'inactive', 'untyped', ...Location::TYPES];

    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tab' => ['sometimes', Rule::in(self::TABS)],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $shop = $this->shop($request);
        $tab = $validated['tab'] ?? 'all';

        $query = $shop->locations()->getQuery();
        $this->applyTab($query, $tab);

        $locations = $query
            ->when($validated['q'] ?? null, function (Builder $q, string $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('formatted_address', 'like', $like));
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => LocationResource::collection($locations),
            'meta' => [
                'tab' => $tab,
                'counts' => $this->counts($request),
                'synced_at' => $shop->locations_synced_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        // Scoped lookup: a location id from another shop is a 404, never a leak.
        $shop = $this->shop($request);
        $location = $shop->locations()->findOrFail($id);

        $validated = $request->validate([
            'type' => ['sometimes', 'nullable', Rule::in(Location::TYPES)],
            'tags' => ['sometimes', 'array', 'max:20'],
            'tags.*' => ['string', 'min:1', 'max:40', 'distinct:ignore_case'],
            'daily_order_cap' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'backlog_cap' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ]);

        if (isset($validated['tags'])) {
            $validated['tags'] = array_values(array_map('trim', $validated['tags']));
        }

        $changed = array_keys($validated);
        DB::transaction(function () use ($location, $validated, $changed, $shop) {
            $location->update($validated);
            if ($changed !== []) {
                $labels = [
                    'type' => 'location type',
                    'tags' => 'supported tags',
                    'daily_order_cap' => 'daily order limit',
                    'backlog_cap' => 'backlog limit',
                ];
                $fields = array_map(fn (string $field) => $labels[$field], $changed);
                sort($fields);
                $this->activity->record(
                    $shop,
                    'location_settings_updated',
                    $location->name,
                    'Updated '.implode(', ', $fields).'.',
                );
            }
        });

        return response()->json([
            'data' => new LocationResource($location),
            'meta' => ['counts' => $this->counts($request)],
        ]);
    }

    public function bulkUpdate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:250'],
            'ids.*' => ['integer', 'distinct'],
            'type' => ['required', Rule::in(Location::TYPES)],
        ]);

        $shop = $this->shop($request);
        $updated = DB::transaction(function () use ($shop, $validated) {
            $updated = $shop->locations()
                ->whereIn('id', $validated['ids'])
                ->update(['type' => $validated['type'], 'updated_at' => now()]);
            if ($updated > 0) {
                $this->activity->record(
                    $shop,
                    'location_types_bulk_updated',
                    'Locations',
                    "Updated the type for {$updated} locations.",
                );
            }

            return $updated;
        });

        return response()->json([
            'data' => ['updated' => $updated],
            'meta' => ['counts' => $this->counts($request)],
        ]);
    }

    public function sync(Request $request): JsonResponse
    {
        BootstrapShop::dispatch($this->shop($request)->id);

        return response()->json(['data' => ['queued' => true]], 202);
    }

    private function applyTab(Builder $query, string $tab): void
    {
        match ($tab) {
            'active' => $query->where('is_active', true),
            'inactive' => $query->where('is_active', false),
            'untyped' => $query->where('is_active', true)->whereNull('type'),
            'all' => null,
            default => $query->where('type', $tab),
        };
    }

    private function counts(Request $request): array
    {
        $row = $this->shop($request)->locations()->toBase()->selectRaw('
            COUNT(*) AS c_all,
            SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS c_active,
            SUM(CASE WHEN is_active = 0 THEN 1 ELSE 0 END) AS c_inactive,
            SUM(CASE WHEN is_active = 1 AND type IS NULL THEN 1 ELSE 0 END) AS c_untyped,
            SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS c_store,
            SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS c_warehouse,
            SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS c_third_party
        ', Location::TYPES)->first();

        return [
            'all' => (int) $row->c_all,
            'active' => (int) $row->c_active,
            'inactive' => (int) $row->c_inactive,
            'untyped' => (int) $row->c_untyped,
            'store' => (int) $row->c_store,
            'warehouse' => (int) $row->c_warehouse,
            'third_party' => (int) $row->c_third_party,
        ];
    }
}
