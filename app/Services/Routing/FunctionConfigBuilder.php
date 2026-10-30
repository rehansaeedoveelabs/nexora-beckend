<?php

namespace App\Services\Routing;

use App\Models\Location;
use App\Models\Shop;

/**
 * Builds the config the checkout Function reads from its metafield:
 *   { "version": 1, "no_split": bool, "products": { "<productGid>": ["<locationGid>", ...] } }
 *
 * It resolves the merchant's tag rules to product IDs (via ProductTagResolver) and computes each
 * product's allowed locations with the SAME eligibility logic as the simulator (RoutingEngine),
 * so checkout and the simulator agree.
 */
class FunctionConfigBuilder
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly RoutingConfigCompiler $compiler,
        private readonly RoutingEngine $engine,
        private readonly ProductTagResolver $resolver,
    ) {}

    public function build(Shop $shop): array
    {
        $settings = $shop->routingConfig?->settings ?? $this->compiler->defaults($shop);
        $compiled = $this->compiler->compile($shop, $settings);

        $locations = $this->compiler->shippingLocations($shop);
        $allGids = $locations->pluck('shopify_id')->sort()->values()->all();

        $scenarioLocations = $locations->map(fn (Location $l) => [
            'id' => $l->shopify_id,
            'name' => $l->name,
            'country_code' => $l->country_code,
            'province_code' => $l->province_code,
        ])->all();

        $products = $this->resolver->resolve($shop, $compiled['tags'] ?? []);

        $productConstraints = [];
        foreach ($products as $productGid => $matchingTags) {
            $eligible = $this->engine->eligibleByLine($compiled, [
                'lines' => [['id' => $productGid, 'tags' => $matchingTags]],
                'destination' => [],
                'locations' => $scenarioLocations,
            ])[$productGid] ?? $allGids;

            // Only record products a rule actually narrows; unconstrained products need no entry.
            $sortedEligible = $eligible;
            sort($sortedEligible);
            if ($sortedEligible !== $allGids) {
                $productConstraints[$productGid] = array_values($eligible);
            }
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'no_split' => (bool) ($compiled['no_split'] ?? false),
            'products' => $productConstraints,
        ];
    }
}
