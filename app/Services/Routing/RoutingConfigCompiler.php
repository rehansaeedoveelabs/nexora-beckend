<?php

namespace App\Services\Routing;

use App\Models\Location;
use App\Models\Shop;
use Illuminate\Support\Collection;

/**
 * Turns merchant-editable routing settings into the compiled config v1 (see RoutingEngine), and
 * flags settings that would break checkout before they are saved.
 *
 * Settings v1 (what the Routing page edits, local location ids):
 *   priority      int[]     Location ids, best first.
 *   prefer_local  null|'country'|'province'
 *   no_split      bool
 *   rules         [{id, enabled, tags: string[], action: 'only_from'|'never_from',
 *                   location_types: string[], location_ids: int[]}]
 */
class RoutingConfigCompiler
{
    public const SCHEMA_VERSION = 1;

    private const TYPE_ORDER = [Location::TYPE_WAREHOUSE, Location::TYPE_STORE, Location::TYPE_THIRD_PARTY];

    /** Locations that can take part in routing: active and fulfilling online orders in Shopify. */
    public function shippingLocations(Shop $shop): Collection
    {
        return $shop->locations()->where('is_active', true)->where('ships', true)->orderBy('name')->get();
    }

    public function defaults(Shop $shop): array
    {
        // Sensible starting order: warehouses, then stores, then third-party, then untyped; by name within each.
        $typeRank = function (Location $l): int {
            $index = array_search($l->type, self::TYPE_ORDER, true);

            return $index === false ? count(self::TYPE_ORDER) : $index;
        };

        $priority = $this->shippingLocations($shop)
            ->sort(fn (Location $a, Location $b) => [$typeRank($a), $a->name] <=> [$typeRank($b), $b->name])
            ->pluck('id')
            ->values()
            ->all();

        return ['priority' => $priority, 'prefer_local' => null, 'no_split' => false, 'rules' => []];
    }

    public function compile(Shop $shop, array $settings): array
    {
        $locations = $this->shippingLocations($shop)->keyBy('id');

        $ranking = [];
        foreach ($settings['priority'] ?? [] as $id) {
            if ($locations->has($id)) {
                $ranking[] = $locations[$id]->shopify_id;
            }
        }
        foreach ($locations as $location) {
            if (! in_array($location->shopify_id, $ranking, true)) {
                $ranking[] = $location->shopify_id; // new locations rank last until the merchant moves them
            }
        }

        $constraints = [];
        $tags = [];
        foreach ($settings['rules'] ?? [] as $rule) {
            if (empty($rule['enabled'])) {
                continue;
            }

            $ruleTags = array_values(array_unique(array_map(fn ($t) => mb_strtolower(trim($t)), $rule['tags'] ?? [])));
            $targets = $this->targets($locations, $rule);
            $allowed = $rule['action'] === 'only_from'
                ? $targets
                : $locations->reject(fn (Location $l) => in_array($l->id, $targets->pluck('id')->all(), true));

            $constraints[] = [
                'rule_id' => $rule['id'],
                'tags_any' => $ruleTags,
                'allowed_location_ids' => $allowed->pluck('shopify_id')->values()->all(),
            ];
            $tags = [...$tags, ...$ruleTags];
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'ranking' => $ranking,
            'prefer_local' => $settings['prefer_local'] ?? null,
            'no_split' => (bool) ($settings['no_split'] ?? false),
            'constraints' => $constraints,
            // Shopify Functions can only test tags named in their input query; this list feeds it.
            'tags' => array_values(array_unique($tags)),
        ];
    }

    /**
     * Problems worth telling the merchant about before they save. Never blocks saving.
     *
     * @return array<int, array{code: string, tone: string, message: string}>
     */
    public function warnings(Shop $shop, array $settings, array $compiled): array
    {
        $warnings = [];
        $locations = $this->shippingLocations($shop);

        if ($locations->isEmpty()) {
            $warnings[] = [
                'code' => 'no_shipping_locations',
                'tone' => 'critical',
                'message' => 'None of your active locations ship online orders, so there is nothing to route. Turn on “Fulfill online orders” for a location in Shopify settings.',
            ];
        }

        $rulesById = collect($settings['rules'] ?? [])->keyBy('id');
        foreach ($compiled['constraints'] as $constraint) {
            if ($constraint['allowed_location_ids'] === []) {
                $warnings[] = [
                    'code' => 'rule_blocks_all',
                    'tone' => 'critical',
                    'message' => sprintf(
                        'The rule for products tagged %s leaves no location able to ship them. Checkout would show no shipping rates for those products.',
                        $this->quoteList($constraint['tags_any']),
                    ),
                    'rule_id' => $constraint['rule_id'],
                ];
            }
        }

        $usesTypes = $rulesById->contains(fn ($r) => ! empty($r['enabled']) && ! empty($r['location_types']));
        $untyped = $locations->whereNull('type')->count();
        if ($usesTypes && $untyped > 0) {
            $warnings[] = [
                'code' => 'untyped_locations',
                'tone' => 'warning',
                'message' => sprintf('%d shipping %s no type yet, so rules that target stores or warehouses ignore %s.',
                    $untyped, $untyped === 1 ? 'location has' : 'locations have', $untyped === 1 ? 'it' : 'them'),
            ];
        }

        if (! empty($settings['no_split']) && $compiled['constraints'] !== []) {
            $warnings[] = [
                'code' => 'no_split_with_rules',
                'tone' => 'info',
                'message' => 'Keeping orders together and product rules both apply. An order mixing products that must ship from different locations can’t be placed. Test those combinations in the simulator below.',
            ];
        }

        return $warnings;
    }

    /**
     * Short sentence for a rule, used inside reasons ("…because of your rule: …").
     */
    public function describe(array $rule, Collection $locations): string
    {
        $tags = $this->quoteList(array_map('trim', $rule['tags'] ?? []));
        $typeLabels = ['store' => 'stores', 'warehouse' => 'warehouses', 'third_party' => 'third-party locations'];
        $targets = [
            ...array_map(fn ($t) => $typeLabels[$t] ?? $t, $rule['location_types'] ?? []),
            ...$locations->whereIn('id', array_map('intval', $rule['location_ids'] ?? []))->pluck('name')->all(),
        ];
        $verb = $rule['action'] === 'only_from' ? 'only ship from' : 'never ship from';

        return sprintf('products tagged %s %s %s', $tags, $verb, $this->quoteList($targets, quote: false) ?: 'the chosen locations');
    }

    private function targets(Collection $locations, array $rule): Collection
    {
        $types = $rule['location_types'] ?? [];
        $ids = array_map('intval', $rule['location_ids'] ?? []);

        return $locations->filter(fn (Location $l) => in_array($l->type, $types, true) || in_array($l->id, $ids, true))->values();
    }

    private function quoteList(array $items, bool $quote = true): string
    {
        $quoted = $quote ? array_map(fn ($t) => "“{$t}”", $items) : array_values($items);

        return count($quoted) > 1
            ? implode(', ', array_slice($quoted, 0, -1)).' or '.end($quoted)
            : ($quoted[0] ?? '');
    }
}
