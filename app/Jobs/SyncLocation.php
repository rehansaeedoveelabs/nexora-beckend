<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Locations\LocationSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SyncLocation implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [5, 30, 120, 600];

    public function __construct(
        public readonly int $shopId,
        public readonly string $gid,
        public readonly bool $deleted = false,
    ) {}

    public function handle(LocationSync $sync): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop?->isInstalled()) {
            return;
        }

        // Re-read from Shopify instead of trusting webhook payload order:
        // deliveries can arrive out of sequence, the API is always current.
        $this->deleted ? $sync->remove($shop, $this->gid) : $sync->syncOne($shop, $this->gid);
    }
}
