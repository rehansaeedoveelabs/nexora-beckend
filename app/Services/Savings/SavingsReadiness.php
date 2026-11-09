<?php

namespace App\Services\Savings;

use App\Models\Shop;
use Carbon\CarbonInterface;

/**
 * Live operational readiness for shipping-savings reporting.
 *
 * Dollar savings stay unavailable until comparable carrier quotes and actual
 * carrier charges are collected. This service only reports what Nexora already
 * knows from locations and routing activity — never demo estimates.
 */
class SavingsReadiness
{
    private const ROUTING_DECISION_TYPES = [
        'order_automatically_routed',
        'order_automatic_routing_partial',
        'order_fulfillment_reassigned',
    ];

    /**
     * @return array{
     *     measurable: bool,
     *     reporting: array{status: string, reason: string},
     *     requirements: list<array{id: string, label: string, status: string, detail: string}>,
     *     operations: array<string, mixed>
     * }
     */
    public function forShop(Shop $shop): array
    {
        $operations = $this->operations($shop);
        $requirements = $this->requirements($operations);

        $measurable = collect($requirements)->every(fn (array $item) => $item['status'] === 'ready');

        return [
            'measurable' => $measurable,
            'reporting' => $measurable
                ? [
                    'status' => 'ready',
                    'reason' => 'Comparable quotes and reconciled carrier charges are available for reporting.',
                ]
                : [
                    'status' => 'unavailable',
                    'reason' => 'Comparable carrier-rate quotes and actual carrier charges are not collected yet. Shopify checkout shipping amounts are customer charges, not merchant carrier costs.',
                ],
            'requirements' => $requirements,
            'operations' => $operations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operations(Shop $shop): array
    {
        $active = $shop->locations()->where('is_active', true)->count();
        $shipping = $shop->locations()->where('is_active', true)->where('ships', true)->count();
        $typedShipping = $shop->locations()
            ->where('is_active', true)
            ->where('ships', true)
            ->whereNotNull('type')
            ->count();

        $decisions = $shop->activityEvents()->whereIn('type', self::ROUTING_DECISION_TYPES);
        $automatic = (clone $decisions)->whereIn('type', [
            'order_automatically_routed',
            'order_automatic_routing_partial',
        ])->count();
        $manual = (clone $decisions)->where('type', 'order_fulfillment_reassigned')->count();
        $lastDecisionAt = (clone $decisions)->max('occurred_at');

        return [
            'shipping_locations' => [
                'active' => $active,
                'shipping' => $shipping,
                'typed_shipping' => $typedShipping,
            ],
            'routing_decisions' => [
                'total' => $automatic + $manual,
                'automatic' => $automatic,
                'manual' => $manual,
                'last_at' => $lastDecisionAt instanceof CarbonInterface
                    ? $lastDecisionAt->toIso8601String()
                    : ($lastDecisionAt ? (string) $lastDecisionAt : null),
            ],
            'carrier_quotes' => [
                'collected' => false,
                'stored_with_decisions' => false,
            ],
            'carrier_charges' => [
                'reconciled' => false,
            ],
            'customer_shipping' => [
                'usable_for_savings' => false,
                'note' => 'Shopify shipping amounts are what customers paid at checkout; they are not merchant carrier costs.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $operations
     * @return list<array{id: string, label: string, status: string, detail: string}>
     */
    private function requirements(array $operations): array
    {
        $shipping = $operations['shipping_locations']['shipping'];
        $typed = $operations['shipping_locations']['typed_shipping'];
        $decisions = $operations['routing_decisions']['total'];
        $quotesCollected = $operations['carrier_quotes']['collected'];
        $quotesStored = $operations['carrier_quotes']['stored_with_decisions'];
        $chargesReconciled = $operations['carrier_charges']['reconciled'];

        return [
            [
                'id' => 'location_quotes',
                'label' => 'Collect comparable shipping-rate quotes for each eligible fulfillment location',
                'status' => $quotesCollected && $shipping > 0 ? 'ready' : 'missing',
                'detail' => $quotesCollected
                    ? "Carrier quotes are available for {$shipping} shipping ".($shipping === 1 ? 'location' : 'locations').'.'
                    : ($shipping === 0
                        ? 'No active shipping locations are configured yet. Sync locations and enable shipping before quotes can be collected.'
                        : "{$shipping} shipping ".($shipping === 1 ? 'location is' : 'locations are').' live'.($typed < $shipping ? " ({$typed} typed)" : '').', but no verified carrier-rate quotes have been collected.'),
            ],
            [
                'id' => 'decision_quotes',
                'label' => 'Store the quote selected for each order alongside the routing decision',
                'status' => $quotesStored && $decisions > 0 ? 'ready' : 'missing',
                'detail' => $quotesStored
                    ? "Selected quotes are stored with {$decisions} recorded routing ".($decisions === 1 ? 'decision' : 'decisions').'.'
                    : ($decisions === 0
                        ? 'No automatic or manual routing decisions have been recorded yet, and selected quotes are not stored.'
                        : "{$decisions} routing ".($decisions === 1 ? 'decision is' : 'decisions are').' recorded in Activity, but selected carrier quotes are not stored with those decisions.'),
            ],
            [
                'id' => 'charge_reconciliation',
                'label' => 'Reconcile selected rates against actual carrier charges before reporting realized savings',
                'status' => $chargesReconciled ? 'ready' : 'missing',
                'detail' => $chargesReconciled
                    ? 'Selected rates are reconciled against actual carrier charges.'
                    : 'Actual carrier charges are not connected, so realized savings cannot be reported.',
            ],
        ];
    }
}
