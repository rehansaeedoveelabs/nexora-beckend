<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Models\Shop;
use App\Services\Activity\ActivityLogger;
use App\Services\Routing\ReasonFormatter;
use App\Services\Routing\RoutingConfigCompiler;
use App\Services\Routing\RoutingEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RoutingController extends Controller
{
    public function __construct(
        private readonly RoutingConfigCompiler $compiler,
        private readonly RoutingEngine $engine,
        private readonly ActivityLogger $activity,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $config = $shop->routingConfig;
        $settings = $config?->settings ?? $this->compiler->defaults($shop);

        return response()->json(['data' => $this->payload($shop, $settings, $config?->updated_at?->toIso8601String())]);
    }

    public function update(Request $request): JsonResponse
    {
        $shop = $this->shop($request);
        $settings = $this->validatedSettings($request, $shop);
        $compiled = $this->compiler->compile($shop, $settings);

        $config = DB::transaction(function () use ($shop, $settings, $compiled) {
            $config = $shop->routingConfig()->updateOrCreate([], [
                'settings' => $settings,
                'compiled' => $compiled,
                'schema_version' => RoutingConfigCompiler::SCHEMA_VERSION,
            ]);
            $this->activity->record(
                $shop,
                'routing_config_updated',
                'Routing configuration',
                'Saved routing rules and location priorities.',
            );

            return $config;
        });

        // Phase 2b: dispatch the job that writes `compiled` to the app metafield for the Functions.

        return response()->json(['data' => $this->payload($shop, $settings, $config->updated_at->toIso8601String())]);
    }

    /**
     * Run a test order through the engine. Accepts unsaved draft settings, so merchants can try a
     * change before saving it.
     */
    public function simulate(Request $request): JsonResponse
    {
        $shop = $this->shop($request);

        $input = $request->validate([
            'lines' => ['required', 'array', 'min:1', 'max:20'],
            'lines.*.title' => ['required', 'string', 'max:100'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:9999'],
            'lines.*.tags' => ['sometimes', 'array', 'max:10'],
            'lines.*.tags.*' => ['string', 'max:40'],
            'lines.*.stock' => ['sometimes', 'array'],
            'lines.*.stock.*' => ['integer', 'min:0', 'max:1000000'],
            'destination.country_code' => ['required', 'string', 'size:2'],
            'destination.province_code' => ['nullable', 'string', 'max:10'],
            'settings' => ['sometimes', 'array'],
        ]);

        $settings = $request->has('settings')
            ? $this->validatedSettings($request, $shop, 'settings.')
            : ($shop->routingConfig?->settings ?? $this->compiler->defaults($shop));

        $compiled = $this->compiler->compile($shop, $settings);
        $locations = $this->compiler->shippingLocations($shop);
        $gidById = $locations->pluck('shopify_id', 'id');

        $lines = [];
        $stock = [];
        foreach ($input['lines'] as $i => $line) {
            $lineId = 'line-'.($i + 1);
            $lines[] = ['id' => $lineId, 'title' => $line['title'], 'quantity' => $line['quantity'], 'tags' => $line['tags'] ?? []];
            foreach ($line['stock'] ?? [] as $locationId => $available) {
                if ($gidById->has((int) $locationId)) {
                    $stock[$gidById[(int) $locationId]][$lineId] = (int) $available;
                }
            }
        }

        $result = $this->engine->route($compiled, [
            'lines' => $lines,
            'destination' => [
                'country_code' => strtoupper($input['destination']['country_code']),
                'province_code' => isset($input['destination']['province_code']) ? strtoupper($input['destination']['province_code']) : null,
            ],
            'locations' => $locations->map(fn (Location $l) => [
                'id' => $l->shopify_id,
                'name' => $l->name,
                'country_code' => $l->country_code,
                'province_code' => $l->province_code,
                'at_capacity' => false, // Phase 2b: live capacity flag
            ])->all(),
            'stock' => $stock,
        ]);

        $formatter = new ReasonFormatter(
            $locations->pluck('name', 'shopify_id')->all(),
            collect($lines)->pluck('title', 'id')->all(),
            collect($settings['rules'])->mapWithKeys(fn ($r) => [$r['id'] => $this->compiler->describe($r, $locations)])->all(),
        );

        $result = $formatter->decorate($result);
        $localId = $locations->pluck('id', 'shopify_id');
        foreach ($result['locations'] as &$location) {
            $location['location_id'] = $localId[$location['id']] ?? null;
        }
        unset($location);
        foreach ($result['shipments'] as &$shipment) {
            $shipment['location_name'] = $locations->firstWhere('shopify_id', $shipment['location'])?->name;
            $shipment['location_id'] = $localId[$shipment['location']] ?? null;
            $shipment['line_titles'] = array_map(fn ($id) => collect($lines)->firstWhere('id', $id)['title'], $shipment['lines']);
        }
        unset($shipment);

        return response()->json(['data' => $result + [
            'ranking_enforced' => $shop->isPlus(),
            'warnings' => $this->compiler->warnings($shop, $settings, $compiled),
        ]]);
    }

    private function validatedSettings(Request $request, Shop $shop, string $prefix = ''): array
    {
        // One query instead of one `exists` lookup per id.
        $ownLocation = Rule::in($shop->locations()->pluck('id')->all());

        $validated = $request->validate([
            $prefix.'priority' => ['present', 'array', 'max:500'],
            $prefix.'priority.*' => ['integer', 'distinct', $ownLocation],
            $prefix.'prefer_local' => ['nullable', Rule::in(['country', 'province'])],
            $prefix.'no_split' => ['required', 'boolean'],
            $prefix.'rules' => ['present', 'array', 'max:50'],
            $prefix.'rules.*.id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/', 'distinct'],
            $prefix.'rules.*.enabled' => ['required', 'boolean'],
            $prefix.'rules.*.tags' => ['required', 'array', 'min:1', 'max:10'],
            $prefix.'rules.*.tags.*' => ['string', 'min:1', 'max:40'],
            $prefix.'rules.*.action' => ['required', Rule::in(['only_from', 'never_from'])],
            $prefix.'rules.*.location_types' => ['present', 'array'],
            $prefix.'rules.*.location_types.*' => [Rule::in(Location::TYPES)],
            $prefix.'rules.*.location_ids' => ['present', 'array'],
            $prefix.'rules.*.location_ids.*' => ['integer', $ownLocation],
        ], [
            $prefix.'rules.*.tags.required' => 'Add at least one product tag.',
            $prefix.'rules.*.tags.min' => 'Add at least one product tag.',
        ]);

        $settings = $prefix === '' ? $validated : $validated['settings'];

        $missingTarget = [];
        foreach ($settings['rules'] as $i => $rule) {
            if (empty($rule['location_types']) && empty($rule['location_ids'])) {
                $missingTarget[$prefix."rules.{$i}.location_types"] = 'Choose at least one location or location type.';
            }
        }
        if ($missingTarget !== []) {
            throw ValidationException::withMessages($missingTarget);
        }

        return [
            'priority' => array_values(array_map('intval', $settings['priority'])),
            'prefer_local' => $settings['prefer_local'] ?? null,
            'no_split' => (bool) $settings['no_split'],
            'rules' => array_values(array_map(fn ($r) => [
                'id' => $r['id'],
                'enabled' => (bool) $r['enabled'],
                'tags' => array_values(array_unique(array_map('trim', $r['tags']))),
                'action' => $r['action'],
                'location_types' => array_values($r['location_types']),
                'location_ids' => array_values(array_map('intval', $r['location_ids'])),
            ], $settings['rules'])),
        ];
    }

    private function payload(Shop $shop, array $settings, ?string $updatedAt): array
    {
        $shipping = $this->compiler->shippingLocations($shop);
        $compiled = $this->compiler->compile($shop, $settings);

        return [
            'settings' => $settings,
            'warnings' => $this->compiler->warnings($shop, $settings, $compiled),
            'plan' => ['is_plus' => $shop->isPlus(), 'name' => $shop->plan_name],
            'locations' => LocationResource::collection($shipping)->resolve(),
            'not_shipping' => $shop->locations()->where('is_active', true)->where('ships', false)->orderBy('name')->pluck('name'),
            'updated_at' => $updatedAt,
        ];
    }
}
