<?php

namespace Tests\Concerns;

use App\Models\Shop;
use Firebase\JWT\JWT;
use Illuminate\Testing\TestResponse;

trait InteractsWithShopify
{
    protected function sessionToken(string $shop = 'demo.myshopify.com', array $overrides = [], ?string $secret = null): string
    {
        $now = time();

        $claims = array_merge([
            'iss' => "https://{$shop}/admin",
            'dest' => "https://{$shop}",
            'aud' => config('shopify.api_key'),
            'sub' => '42',
            'exp' => $now + 60,
            'nbf' => $now - 5,
            'iat' => $now - 5,
            'jti' => bin2hex(random_bytes(8)),
            'sid' => 'session-id',
        ], $overrides);

        return JWT::encode($claims, $secret ?? config('shopify.api_secret'), 'HS256');
    }

    protected function installedShop(string $domain = 'demo.myshopify.com', array $attributes = []): Shop
    {
        return Shop::create(array_merge([
            'domain' => $domain,
            'access_token' => 'shpat_test_access',
            'access_token_expires_at' => now()->addHour(),
            'refresh_token' => 'shprt_test_refresh',
            'refresh_token_expires_at' => now()->addDays(90),
            'installed_at' => now(),
            'locations_synced_at' => now(),
        ], $attributes));
    }

    protected function apiAs(Shop|string $shop): static
    {
        $domain = $shop instanceof Shop ? $shop->domain : $shop;

        return $this->withHeader('Authorization', 'Bearer '.$this->sessionToken($domain));
    }

    protected function sendWebhook(string $topic, array $payload, string $shop = 'demo.myshopify.com', ?string $webhookId = null, ?string $hmac = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/api/webhooks', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => $topic,
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $shop,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId ?? bin2hex(random_bytes(8)),
            'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac ?? base64_encode(hash_hmac('sha256', $body, config('shopify.api_secret'), true)),
        ], $body);
    }

    protected function graphqlUrl(string $shop = 'demo.myshopify.com'): string
    {
        return "https://{$shop}/admin/api/".config('shopify.api_version').'/graphql.json';
    }

    protected function locationNode(int $id, string $name, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => "gid://shopify/Location/{$id}",
            'name' => $name,
            'isActive' => true,
            'fulfillsOnlineOrders' => true,
            'address' => [
                'address1' => '123 Main St',
                'city' => 'Toronto',
                'province' => 'Ontario',
                'countryCode' => 'CA',
                'zip' => 'A1A 1A1',
                'formatted' => ['123 Main St', 'Toronto ON A1A 1A1', 'Canada'],
            ],
            'localPickupSettingsV2' => null,
            'fulfillmentService' => null,
        ], $overrides);
    }
}
