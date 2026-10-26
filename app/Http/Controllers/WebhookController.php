<?php

namespace App\Http\Controllers;

use App\Jobs\SyncLocation;
use App\Models\Shop;
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
    private const LOCATION_TOPICS = [
        'locations/create',
        'locations/update',
        'locations/activate',
        'locations/deactivate',
    ];

    public function __invoke(Request $request): JsonResponse
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
            $this->handle($topic, $domain, $request->json()->all());
        } catch (Throwable $e) {
            // Release the receipt so Shopify's retry is processed instead of skipped as a duplicate.
            DB::table('webhook_receipts')->where('webhook_id', $webhookId)->delete();

            throw $e;
        }

        DB::table('webhook_receipts')->where('webhook_id', $webhookId)->update(['processed_at' => now()]);

        return response()->json(['status' => 'ok']);
    }

    private function handle(string $topic, string $domain, array $payload): void
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
            default => Log::warning('Unhandled Shopify webhook topic', ['topic' => $topic]),
        };
    }

    private function queueLocation(?Shop $shop, array $payload, bool $deleted): void
    {
        $gid = LocationSync::gidFromWebhook($payload);

        if ($shop?->isInstalled() && $gid !== null) {
            SyncLocation::dispatch($shop->id, $gid, $deleted);
        }
    }
}
