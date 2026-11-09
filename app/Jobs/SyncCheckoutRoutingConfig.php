<?php

namespace App\Jobs;

use App\Models\RoutingConfig;
use App\Models\Shop;
use App\Services\Routing\FunctionConfigBuilder;
use App\Services\Routing\ShopifyFunctionConfigWriter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCheckoutRoutingConfig implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    public function __construct(public readonly int $shopId) {}

    public static function queueFor(Shop $shop): bool
    {
        $config = $shop->routingConfig()->first();
        if (! $config) {
            return false;
        }

        $config->forceFill([
            'checkout_sync_status' => 'pending',
            'checkout_sync_error' => null,
        ])->save();
        self::dispatch($shop->id);

        return true;
    }

    public function backoff(): array
    {
        return [15, 60, 180];
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("checkout-routing-config:{$this->shopId}"))
                ->releaseAfter(15)
                ->expireAfter(180),
        ];
    }

    public function handle(FunctionConfigBuilder $builder, ShopifyFunctionConfigWriter $writer): void
    {
        $shop = Shop::query()->findOrFail($this->shopId);
        if (! $shop->isInstalled()) {
            throw new \RuntimeException('Shop is not installed.');
        }

        $config = $shop->routingConfig;
        if (! $config) {
            throw new \RuntimeException('Routing configuration no longer exists.');
        }

        $writer->sync($shop, $builder->build($shop));

        $config->forceFill([
            'checkout_sync_status' => 'synced',
            'checkout_sync_error' => null,
            'checkout_synced_at' => now(),
        ])->save();
    }

    public function failed(?Throwable $exception): void
    {
        RoutingConfig::query()->where('shop_id', $this->shopId)->update([
            'checkout_sync_status' => 'failed',
            'checkout_sync_error' => 'Shopify did not accept the checkout routing configuration. Check app permissions and that the fulfillment-constraints Function is deployed, then retry.',
        ]);

        Log::warning('Checkout routing configuration sync failed', [
            'shop_id' => $this->shopId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
