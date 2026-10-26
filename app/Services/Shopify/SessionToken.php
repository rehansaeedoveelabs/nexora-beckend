<?php

namespace App\Services\Shopify;

use App\Exceptions\InvalidSessionToken;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Throwable;

/**
 * Verifies the App Bridge session token (ID token) sent as `Authorization: Bearer <jwt>`.
 */
class SessionToken
{
    private const SHOP_HOST = '/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $leeway = 10,
    ) {}

    /**
     * @return array{shop: string, user_id: ?string, claims: object}
     *
     * @throws InvalidSessionToken
     */
    public function verify(string $jwt): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new InvalidSessionToken('App credentials are not configured.');
        }

        JWT::$leeway = $this->leeway;

        try {
            // Signature (HS256 only, so no alg confusion), exp and nbf are enforced here.
            $claims = JWT::decode($jwt, new Key($this->apiSecret, 'HS256'));
        } catch (Throwable $e) {
            throw new InvalidSessionToken('Session token failed verification.', previous: $e);
        }

        if (($claims->aud ?? null) !== $this->apiKey) {
            throw new InvalidSessionToken('Session token audience mismatch.');
        }

        $shop = $this->hostOf($claims->dest ?? null);
        if ($shop === null || ! preg_match(self::SHOP_HOST, $shop)) {
            throw new InvalidSessionToken('Session token destination is not a Shopify shop.');
        }

        // iss is https://{shop}/admin, so it must point at the same shop as dest.
        if ($this->hostOf($claims->iss ?? null) !== $shop) {
            throw new InvalidSessionToken('Session token issuer mismatch.');
        }

        return [
            'shop' => $shop,
            'user_id' => isset($claims->sub) ? (string) $claims->sub : null,
            'claims' => $claims,
        ];
    }

    private function hostOf(mixed $url): ?string
    {
        if (! is_string($url)) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }
}
