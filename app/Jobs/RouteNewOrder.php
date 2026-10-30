<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\Routing\AutomaticOrderRouter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RouteNewOrder implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 86400;

    public array $backoff = [5, 30, 120, 600];

    public function __construct(
        public readonly int $shopId,
        public readonly int $orderId,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->shopId}:{$this->orderId}";
    }

    public function handle(AutomaticOrderRouter $router): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop?->isInstalled()) {
            return;
        }

        $router->route($shop, $this->orderId);
    }
}
