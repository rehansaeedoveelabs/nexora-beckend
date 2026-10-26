<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Locations\LocationSync;
use App\Services\Shopify\ShopDetails;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs once after install (and on manual "Sync now"): shop details + full location import.
 */
class BootstrapShop implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(public readonly int $shopId) {}

    public function uniqueId(): string
    {
        return (string) $this->shopId;
    }

    public function handle(ShopDetails $details, LocationSync $locations): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop?->isInstalled()) {
            return;
        }

        $details->sync($shop);
        $locations->syncAll($shop);
    }
}
