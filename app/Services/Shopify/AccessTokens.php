<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Expiring offline tokens: token exchange on install/re-auth, refresh-token rotation afterwards.
 *
 * Refresh tokens rotate (every refresh returns a new pair and retires the old one),
 * so refreshes are serialized per shop with a cache lock.
 */
class AccessTokens
{
    private const ENDPOINT = 'https://%s/admin/oauth/access_token';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $refreshMargin = 300,
    ) {}

    /**
     * Swap an App Bridge ID token for an expiring offline token pair.
     */
    public function exchange(Shop $shop, string $idToken): Shop
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post(sprintf(self::ENDPOINT, $shop->domain), [
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $idToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            'expiring' => 1,
        ]);

        if (! $response->successful()) {
            throw new ShopifyApiException('Token exchange failed.', status: $response->status());
        }

        $this->store($shop, $response->json());

        $shop->forceFill([
            'installed_at' => $shop->installed_at ?? now(),
            'uninstalled_at' => null,
        ])->save();

        return $shop;
    }

    /**
     * Return a valid access token, refreshing it first if it is about to expire.
     */
    public function tokenFor(Shop $shop): string
    {
        if ($this->isFresh($shop)) {
            return $shop->access_token;
        }

        return Cache::lock("shopify-token-refresh:{$shop->id}", 20)->block(15, function () use ($shop) {
            // Another worker may have refreshed while we waited for the lock.
            $shop->refresh();
            if ($this->isFresh($shop)) {
                return $shop->access_token;
            }

            return $this->refresh($shop)->access_token;
        });
    }

    public function refresh(Shop $shop): Shop
    {
        if (! $shop->hasUsableGrant()) {
            throw new ShopifyApiException('Shop has no usable refresh token; merchant must reopen the app.', status: 401);
        }

        $response = Http::asForm()->acceptJson()->timeout(15)->post(sprintf(self::ENDPOINT, $shop->domain), [
            'client_id' => $this->apiKey,
            'client_secret' => $this->apiSecret,
            'grant_type' => 'refresh_token',
            'refresh_token' => $shop->refresh_token,
        ]);

        if (! $response->successful()) {
            if (in_array($response->status(), [400, 401], true)) {
                // Grant revoked or expired: drop it so the next app open re-runs token exchange.
                $shop->forgetTokens();
            }

            throw new ShopifyApiException('Access token refresh failed.', status: $response->status());
        }

        $this->store($shop, $response->json());

        return $shop;
    }

    private function isFresh(Shop $shop): bool
    {
        return $shop->access_token !== null
            && $shop->access_token_expires_at !== null
            && $shop->access_token_expires_at->isAfter(now()->addSeconds($this->refreshMargin));
    }

    private function store(Shop $shop, ?array $body): void
    {
        if (empty($body['access_token']) || empty($body['refresh_token'])) {
            throw new ShopifyApiException('Token response missing expiring offline token pair.');
        }

        $shop->forceFill([
            'access_token' => $body['access_token'],
            'access_token_expires_at' => now()->addSeconds((int) ($body['expires_in'] ?? 3600)),
            'refresh_token' => $body['refresh_token'],
            'refresh_token_expires_at' => isset($body['refresh_token_expires_in'])
                ? now()->addSeconds((int) $body['refresh_token_expires_in'])
                : null,
            'scopes' => $body['scope'] ?? $shop->scopes,
        ])->save();
    }
}
