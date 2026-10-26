<?php

namespace App\Services\Shopify;

use App\Models\Shop;

class ShopDetails
{
    public function __construct(private readonly AdminGraphql $graphql) {}

    public function sync(Shop $shop): Shop
    {
        $data = $this->graphql->query($shop, 'query {
            shop { name currencyCode ianaTimezone plan { shopifyPlus partnerDevelopment } }
        }')['shop'] ?? [];

        $plan = $data['plan'] ?? [];

        $shop->forceFill([
            'name' => $data['name'] ?? $shop->name,
            'currency' => $data['currencyCode'] ?? $shop->currency,
            'timezone' => $data['ianaTimezone'] ?? $shop->timezone,
            'plan_name' => match (true) {
                (bool) ($plan['shopifyPlus'] ?? false) => 'plus',
                (bool) ($plan['partnerDevelopment'] ?? false) => 'development',
                default => 'standard',
            },
        ])->save();

        return $shop;
    }
}
