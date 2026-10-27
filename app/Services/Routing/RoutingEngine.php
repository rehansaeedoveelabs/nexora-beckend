<?php

namespace App\Services\Routing;

/**
 * Decides where an order ships from, given a COMPILED routing config (schema v1) and a scenario.
 *
 * Pure and deterministic: no DB, no HTTP. The simulator, the decision log and the golden fixtures
 * (tests/Fixtures/routing) all go through here.
 *
 * Two kinds of logic live here — keep them distinct:
 *  1. NEXORA LOGIC (what the Shopify Functions will also compute): which locations each item may
 *     ship from (product rules, at-capacity) and the location ranking. The Rust Functions must
 *     reproduce these exactly for the same fixtures.
 *  2. SHOPIFY APPROXIMATION: picking one location / the fewest-split set using stock. At checkout
 *     Shopify does this itself; the outcome/shipments here are our best model of it, not a guarantee.
 *
 * Compiled config v1:
 *   ranking        string[]   Location GIDs, best first. Unlisted locations rank after, by name.
 *   prefer_local   null|'country'|'province'  Locations matching the destination rank first.
 *   no_split       bool       All items must ship from one location (fulfillment constraint).
 *   constraints    [{rule_id, tags_any: string[], allowed_location_ids: string[]}]
 *
 * Scenario:
 *   lines          [{id, title, sku?, quantity, tags: string[]}]
 *   destination    {country_code, province_code?}
 *   locations      [{id, name, country_code?, province_code?, at_capacity?: bool}]  (ship-capable only)
 *   stock          {locationId: {lineId: available}}  (missing = 0)
 *
 * Mirrors Shopify's behaviour: prefer a single location that can ship everything (by rank);
 * otherwise split across as few locations as possible, best-ranked first, unless no_split is on.
 */
class RoutingEngine
{
    public const OUTCOME_SINGLE = 'single';
    public const OUTCOME_SPLIT = 'split';
    public const OUTCOME_BLOCKED = 'blocked';

    /**
     * NEXORA LOGIC shared with the Rust Shopify Functions.
     *
     * Ranking = what the order-routing location-rule Function returns (compiled ranking, reordered
     * by prefer_local). The Rust crate must reproduce this exactly (asserted via cases.json).
     *
     * @return string[] location ids, best first
     */
    public function rankedIds(array $config, array $scenario): array
    {
        return array_column($this->rank($config, $scenario), 'id');
    }

    /**
     * NEXORA LOGIC shared with the Rust Shopify Functions.
     *
     * Per line, the locations a product rule allows it to ship from, in ranked order. This is what
     * the fulfillment-constraints Function computes (rules only — stock and capacity are not its job).
     *
     * @return array<string, string[]> line id => allowed location ids
     */
    public function eligibleByLine(array $config, array $scenario): array
    {
        $ranked = $this->rank($config, $scenario);
        $out = [];

        foreach ($scenario['lines'] ?? [] as $line) {
            $allowed = [];
            foreach ($ranked as $location) {
                if ($this->ruleBlocking($config, $line, $location['id']) === null) {
                    $allowed[] = $location['id'];
                }
            }
            $out[$line['id']] = $allowed;
        }

        return $out;
    }

    public function route(array $config, array $scenario): array
    {
        $lines = array_values($scenario['lines'] ?? []);
        $locations = $this->rank($config, $scenario);
        $reasons = [];

        // Per location: which lines it may ship, and why not for the others.
        foreach ($locations as &$location) {
            $location['can_ship'] = [];
            $location['ships_everything'] = false;
            $location['reasons'] = $this->rankReasons($config, $location, $scenario['destination'] ?? []);

            if (! empty($location['at_capacity'])) {
                $location['reasons'][] = ['code' => 'at_capacity', 'location' => $location['id']];

                continue;
            }

            foreach ($lines as $line) {
                $blocked = $this->ruleBlocking($config, $line, $location['id']);
                if ($blocked !== null) {
                    $location['reasons'][] = ['code' => 'rule_excludes', 'rule_id' => $blocked, 'line' => $line['id'], 'location' => $location['id']];

                    continue;
                }

                $available = (int) ($scenario['stock'][$location['id']][$line['id']] ?? 0);
                if ($available < (int) $line['quantity']) {
                    $location['reasons'][] = [
                        'code' => 'insufficient_stock', 'line' => $line['id'], 'location' => $location['id'],
                        'available' => $available, 'needed' => (int) $line['quantity'],
                    ];

                    continue;
                }

                $location['can_ship'][] = $line['id'];
            }

            $location['ships_everything'] = count($location['can_ship']) === count($lines) && $lines !== [];
        }
        unset($location);

        $lineIds = array_column($lines, 'id');
        $single = $this->first($locations, fn ($l) => $l['ships_everything']);

        if ($lineIds === []) {
            $outcome = self::OUTCOME_BLOCKED;
            $shipments = [];
            $reasons[] = ['code' => 'empty_cart'];
        } elseif ($single !== null) {
            $outcome = self::OUTCOME_SINGLE;
            $shipments = [['location' => $single['id'], 'lines' => $lineIds]];
            $reasons[] = ['code' => 'single_location', 'location' => $single['id'], 'rank' => $single['rank']];
        } elseif (! empty($config['no_split'])) {
            $outcome = self::OUTCOME_BLOCKED;
            $shipments = [];
            $reasons[] = ['code' => 'no_split_unfulfillable'];
        } else {
            [$shipments, $unshippable] = $this->split($locations, $lineIds);

            if ($unshippable !== []) {
                $outcome = self::OUTCOME_BLOCKED;
                $shipments = [];
                foreach ($unshippable as $lineId) {
                    $reasons[] = ['code' => 'line_unfulfillable', 'line' => $lineId];
                }
            } else {
                $outcome = self::OUTCOME_SPLIT;
                $reasons[] = ['code' => 'split', 'shipments' => count($shipments)];
            }
        }

        return [
            'outcome' => $outcome,
            'shipments' => $shipments,
            'reasons' => $reasons,
            'locations' => array_map(fn ($l) => [
                'id' => $l['id'],
                'name' => $l['name'],
                'rank' => $l['rank'],
                'ships_everything' => $l['ships_everything'],
                'can_ship' => $l['can_ship'],
                'reasons' => $l['reasons'],
            ], $locations),
        ];
    }

    /**
     * Final location order: compiled ranking, then unlisted locations by name,
     * then (stable) local-first partition when prefer_local is set.
     */
    private function rank(array $config, array $scenario): array
    {
        $byId = [];
        foreach ($scenario['locations'] ?? [] as $location) {
            $byId[$location['id']] = $location;
        }

        $ordered = [];
        foreach ($config['ranking'] ?? [] as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }

        $rest = array_values($byId);
        usort($rest, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $ordered = [...$ordered, ...$rest];

        $mode = $config['prefer_local'] ?? null;
        if ($mode !== null) {
            $destination = $scenario['destination'] ?? [];
            $local = array_values(array_filter($ordered, fn ($l) => $this->isLocal($mode, $l, $destination)));
            $remote = array_values(array_filter($ordered, fn ($l) => ! $this->isLocal($mode, $l, $destination)));
            $ordered = [...$local, ...$remote];
        }

        foreach ($ordered as $i => &$location) {
            $location['rank'] = $i + 1;
        }

        return $ordered;
    }

    private function rankReasons(array $config, array $location, array $destination): array
    {
        $mode = $config['prefer_local'] ?? null;
        if ($mode === null) {
            return [];
        }

        return [[
            'code' => $this->isLocal($mode, $location, $destination) ? 'local_match' : 'not_local',
            'mode' => $mode,
            'location' => $location['id'],
        ]];
    }

    private function isLocal(string $mode, array $location, array $destination): bool
    {
        $sameCountry = ! empty($destination['country_code'])
            && strcasecmp($location['country_code'] ?? '', $destination['country_code']) === 0;

        if ($mode === 'country') {
            return $sameCountry;
        }

        return $sameCountry
            && ! empty($destination['province_code'])
            && strcasecmp($location['province_code'] ?? '', $destination['province_code']) === 0;
    }

    /**
     * The id of the first enabled constraint that forbids this location for this line, if any.
     */
    private function ruleBlocking(array $config, array $line, string $locationId): ?string
    {
        $tags = array_map('mb_strtolower', $line['tags'] ?? []);

        foreach ($config['constraints'] ?? [] as $constraint) {
            $ruleTags = array_map('mb_strtolower', $constraint['tags_any'] ?? []);
            if (array_intersect($tags, $ruleTags) === []) {
                continue;
            }
            if (! in_array($locationId, $constraint['allowed_location_ids'] ?? [], true)) {
                return $constraint['rule_id'];
            }
        }

        return null;
    }

    /**
     * Greedy split: walk locations best-ranked first and give each one every remaining line it can
     * ship. Respects ranking and keeps the number of shipments low.
     *
     * @return array{0: array, 1: string[]} [shipments, unshippable line ids]
     */
    private function split(array $locations, array $lineIds): array
    {
        $remaining = $lineIds;
        $shipments = [];

        foreach ($locations as $location) {
            $take = array_values(array_intersect($remaining, $location['can_ship']));
            if ($take === []) {
                continue;
            }
            $shipments[] = ['location' => $location['id'], 'lines' => $take];
            $remaining = array_values(array_diff($remaining, $take));
            if ($remaining === []) {
                break;
            }
        }

        return [$shipments, $remaining];
    }

    private function first(array $items, callable $predicate): ?array
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return $item;
            }
        }

        return null;
    }
}
