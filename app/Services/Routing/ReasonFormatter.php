<?php

namespace App\Services\Routing;

/**
 * The single place where engine reason codes become merchant-facing sentences.
 * Simulator, decision log and (later) alert emails all use this, so wording never drifts.
 */
class ReasonFormatter
{
    /**
     * @param  array<string, string>  $locationNames  location id => name
     * @param  array<string, string>  $lineTitles     line id => title
     * @param  array<string, string>  $ruleLabels     rule id => short description
     */
    public function __construct(
        private readonly array $locationNames = [],
        private readonly array $lineTitles = [],
        private readonly array $ruleLabels = [],
    ) {}

    public function format(array $reason): string
    {
        $location = $this->locationNames[$reason['location'] ?? ''] ?? 'This location';
        $line = $this->lineTitles[$reason['line'] ?? ''] ?? 'an item';

        return match ($reason['code']) {
            'single_location' => "{$location} can ship the whole order, and it’s the highest-ranked location that can.",
            'split' => "No single location has everything, so the order ships in {$reason['shipments']} parts, using the highest-ranked locations first.",
            'no_split_unfulfillable' => '“Keep orders together” is on, and no single location can ship everything. Checkout would show no shipping rates for this order.',
            'line_unfulfillable' => "No location can ship {$line}. Checkout would show no shipping rates for this order.",
            'empty_cart' => 'Add at least one product to test an order.',
            'at_capacity' => "{$location} has reached its daily or backlog limit, so it’s skipped.",
            'rule_excludes' => "{$location} can’t ship {$line} because of your rule: ".($this->ruleLabels[$reason['rule_id']] ?? 'a product rule').'.',
            'insufficient_stock' => "{$location} has {$reason['available']} of {$line} in stock, but the order needs {$reason['needed']}.",
            'local_match' => "{$location} is in the customer’s ".($reason['mode'] === 'country' ? 'country' : 'state or province').', so it’s tried first.',
            'not_local' => "{$location} is outside the customer’s ".($reason['mode'] === 'country' ? 'country' : 'state or province').', so nearby locations are tried first.',
            default => 'Routing detail: '.$reason['code'],
        };
    }

    /**
     * Attach a `message` to every reason in an engine result (top-level and per location).
     */
    public function decorate(array $result): array
    {
        $result['reasons'] = array_map(fn ($r) => $r + ['message' => $this->format($r)], $result['reasons']);

        foreach ($result['locations'] as &$location) {
            $location['reasons'] = array_map(fn ($r) => $r + ['message' => $this->format($r)], $location['reasons']);
        }

        return $result;
    }
}
