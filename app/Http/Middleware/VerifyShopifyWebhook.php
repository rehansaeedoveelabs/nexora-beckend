<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies X-Shopify-Hmac-Sha256 against the RAW request body before anything parses it.
 */
class VerifyShopifyWebhook
{
    private const SHOP_HOST = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('shopify.api_secret');
        $signature = (string) $request->header('X-Shopify-Hmac-Sha256', '');

        if ($secret === '' || $signature === '') {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), $secret, true));

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $shop = strtolower((string) $request->header('X-Shopify-Shop-Domain', ''));
        if (! preg_match(self::SHOP_HOST, $shop) || ! $request->header('X-Shopify-Topic') || ! $request->header('X-Shopify-Webhook-Id')) {
            return response()->json(['message' => 'Malformed webhook.'], 400);
        }

        return $next($request);
    }
}
