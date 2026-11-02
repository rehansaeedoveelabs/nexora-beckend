<?php

namespace App\Services\Flow;

use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Emits Shopify Flow triggers for Nexora events (the "send" half of Flow).
 *
 * The Flow trigger extension (handles + payload schema) is declared and deployed
 * via the Shopify CLI; this service only fires `flowTriggerReceive` at runtime.
 *
 * Emitting is strictly best-effort: a trigger that is unconfigured, not yet
 * deployed, or rejected by Shopify must never interrupt the routing/fulfillment
 * flow that called it. Every failure is logged (handle + shop id only, no token,
 * no customer data) and swallowed.
 */
class FlowTriggerEmitter
{
    private const MUTATION = <<<'GQL'
        mutation NexoraFlowTrigger($handle: String, $payload: JSON) {
            flowTriggerReceive(handle: $handle, payload: $payload) {
                userErrors { field message }
            }
        }
    GQL;

    public function __construct(
        private readonly AdminGraphql $graphql,
    ) {}

    /**
     * Fire the Flow trigger mapped to $key with $payload.
     *
     * @param  string  $key  Symbolic name from config('shopify.flow_triggers').
     * @param  array<string, mixed>  $payload  Keys must match the extension's field schema. No PII.
     * @return bool True if Shopify accepted the trigger; false if unconfigured or failed.
     */
    public function emit(Shop $shop, string $key, array $payload): bool
    {
        $handle = config("shopify.flow_triggers.{$key}");

        if (! is_string($handle) || $handle === '') {
            // Not configured (no deployed extension yet): a no-op by design.
            return false;
        }

        try {
            $this->graphql->mutate(
                $shop,
                self::MUTATION,
                ['handle' => $handle, 'payload' => (object) $payload],
                'flowTriggerReceive',
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Flow trigger not delivered.', [
                'shop_id' => $shop->id,
                'trigger' => $key,
                'handle' => $handle,
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
