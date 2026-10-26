<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidSessionToken;
use App\Exceptions\ShopifyApiException;
use App\Jobs\BootstrapShop;
use App\Models\Shop;
use App\Services\Shopify\AccessTokens;
use App\Services\Shopify\SessionToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates every /api request with the App Bridge session token and resolves the Shop.
 * The shop ALWAYS comes from the verified token, never from client input.
 */
class VerifyShopifySessionToken
{
    public function __construct(
        private readonly SessionToken $sessionToken,
        private readonly AccessTokens $tokens,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $jwt = $request->bearerToken();
        if (! $jwt) {
            return $this->unauthorized('Missing session token.');
        }

        try {
            $session = $this->sessionToken->verify($jwt);
        } catch (InvalidSessionToken) {
            return $this->unauthorized('Invalid session token.');
        }

        $shop = Shop::query()->createOrFirst(['domain' => $session['shop']]);

        if (! $shop->hasUsableGrant()) {
            try {
                $this->authorize($shop, $jwt);
            } catch (ShopifyApiException) {
                return $this->unauthorized('Could not authorize with Shopify. Reload the app to try again.');
            }
        }

        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    /**
     * First open after install (or after the grant was revoked): token exchange, then bootstrap.
     * Serialized per shop: concurrent exchanges would retire each other's refresh tokens.
     */
    private function authorize(Shop $shop, string $jwt): void
    {
        Cache::lock("shopify-token-exchange:{$shop->domain}", 20)->block(15, function () use ($shop, $jwt) {
            $shop->refresh();
            if ($shop->hasUsableGrant()) {
                return;
            }

            $this->tokens->exchange($shop, $jwt);
            BootstrapShop::dispatch($shop->id);
        });
    }

    private function unauthorized(string $message): JsonResponse
    {
        // App Bridge convention: tells the frontend to fetch a fresh session token and retry once.
        return response()->json(['message' => $message], 401, [
            'X-Shopify-Retry-Invalid-Session-Request' => '1',
        ]);
    }
}
