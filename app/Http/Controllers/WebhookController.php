<?php

namespace App\Http\Controllers;

use App\Jobs\RouteNewOrder;
use App\Jobs\SyncLocation;
use App\Models\Shop;
use App\Services\Activity\ActivityLogger;
use App\Services\Locations\LocationSync;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single webhook endpoint (HMAC already verified by middleware).
 * Pattern: record receipt (idempotency) → hand off → 200 fast.
 */
class WebhookController extends Controller
{
    private const ORDER_TOPICS = [
        'orders/create' => ['shopify_order_created', 'Shopify reported a new order.'],
        'orders/updated' => ['shopify_order_updated', 'Shopify reported an order update.'],
        'orders/cancelled' => ['shopify_order_cancelled', 'Shopify reported an order cancellation.'],
        'fulfillments/create' => ['shopify_fulfillment_created', 'Shopify reported a fulfillment created.'],
        'fulfillments/update' => ['shopify_fulfillment_updated', 'Shopify reported a fulfillment update.'],
    ];

    private const LOCATION_TOPICS = [
        'locations/create',
        'locations/update',
        'locations/activate',
        'locations/deactivate',
    ];

    public function __invoke(Request $request, ActivityLogger $activity): JsonResponse
    {
        $topic = strtolower((string) $request->header('X-Shopify-Topic'));
        $domain = strtolower((string) $request->header('X-Shopify-Shop-Domain'));
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id');

        try {
            DB::table('webhook_receipts')->insert([
                'webhook_id' => $webhookId,
                'shop_domain' => $domain,
                'topic' => $topic,
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            $this->handle($topic, $domain, $request->json()->all(), $activity);
        } catch (Throwable $e) {
            // Release the receipt so Shopify's retry is processed instead of skipped as a duplicate.
            DB::table('webhook_receipts')->where('webhook_id', $webhookId)->delete();

            throw $e;
        }

        DB::table('webhook_receipts')->where('webhook_id', $webhookId)->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    private function handle(string $topic, string $domain, array $payload, ActivityLogger $activity): void
    {
        $shop = Shop::where('domain', $domain)->first();

        match (true) {
            $topic === 'app/uninstalled' => $shop?->forceFill(['uninstalled_at' => now()])->forgetTokens(),
            $topic === 'app/scopes_update' => $shop?->forceFill(['scopes' => implode(',', $payload['current'] ?? [])])->save(),
            $topic === 'shop/redact' => $shop?->delete(), // cascades to all shop data
            // Nexora stores no customer-level data, so there is nothing to export or erase.
            $topic === 'customers/data_request', $topic === 'customers/redact' => null,
            in_array($topic, self::LOCATION_TOPICS, true) => $this->queueLocation($shop, $payload, false),
            $topic === 'locations/delete' => $this->queueLocation($shop, $payload, true),
            isset(self::ORDER_TOPICS[$topic]) => $this->recordOrderEvent($shop, $topic, $payload, $activity),
            default => Log::warning('Unhandled Shopify webhook topic', ['topic' => $topic]),
        };
    }

    private function recordOrderEvent(?Shop $shop, string $topic, array $payload, ActivityLogger $activity): void
    {
        if (! $shop?->isInstalled()) {
            return;
        }

        [$type, $summary] = self::ORDER_TOPICS[$topic];
        $number = $payload['name'] ?? $payload['order_name'] ?? null;
        $subject = is_string($number) && preg_match('/^#[A-Za-z0-9-]{1,40}$/', $number) === 1
            ? $number
            : 'Shopify order';

        $activity->record($shop, $type, $subject, $summary);

        if ($topic === 'orders/create') {
            $orderId = filter_var($payload['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($orderId === false) {
                Log::warning('New-order webhook has no valid order ID; automatic routing was skipped.', [
                    'shop' => $shop->domain,
                ]);

                return;
            }

            RouteNewOrder::dispatch($shop->id, $orderId)->onConnection('database');
        }
    }

    private function queueLocation(?Shop $shop, array $payload, bool $deleted): void
    {
        $gid = LocationSync::gidFromWebhook($payload);

        if ($shop?->isInstalled() && $gid !== null) {
            SyncLocation::dispatch($shop->id, $gid, $deleted);
        }
    }
}
