<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * The single gateway to the Shopify Admin GraphQL API (pinned version).
 */
class AdminGraphql
{
    private const MAX_ATTEMPTS = 4;

    public function __construct(
        private readonly AccessTokens $tokens,
        private readonly string $apiVersion,
    ) {}

    /**
     * Run a query and return its `data`. Throws on transport, HTTP or GraphQL errors.
     */
    public function query(Shop $shop, string $query, array $variables = []): array
    {
        $url = "https://{$shop->domain}/admin/api/{$this->apiVersion}/graphql.json";

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::acceptJson()
                    ->timeout(20)
                    ->withHeaders(['X-Shopify-Access-Token' => $this->tokens->tokenFor($shop)])
                    ->post($url, ['query' => $query, 'variables' => (object) $variables]);
            } catch (ConnectionException $e) {
                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new ShopifyApiException('Could not reach Shopify.');
                }
                $this->backoff($attempt);

                continue;
            }

            if ($this->isRetryable($response) && $attempt < self::MAX_ATTEMPTS) {
                $this->backoff($attempt, $response);

                continue;
            }

            return $this->unwrap($response);
        }
    }

    /**
     * Run a mutation and throw if Shopify reports userErrors under `$payloadKey`.
     */
    public function mutate(Shop $shop, string $mutation, array $variables, string $payloadKey): array
    {
        $data = $this->query($shop, $mutation, $variables);
        $userErrors = $data[$payloadKey]['userErrors'] ?? [];

        if ($userErrors !== []) {
            throw new ShopifyApiException('Shopify rejected the change.', $userErrors, 422);
        }

        return $data[$payloadKey] ?? [];
    }

    private function isRetryable(Response $response): bool
    {
        if ($response->status() === 429 || $response->serverError()) {
            return true;
        }

        foreach ($response->json('errors') ?? [] as $error) {
            if (($error['extensions']['code'] ?? null) === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    private function unwrap(Response $response): array
    {
        if ($response->status() === 401) {
            throw new ShopifyApiException('Shopify rejected the access token.', status: 401);
        }

        if (! $response->successful()) {
            throw new ShopifyApiException('Shopify API request failed.', status: $response->status());
        }

        $errors = $response->json('errors');
        if (! empty($errors)) {
            throw new ShopifyApiException('Shopify GraphQL error.', is_array($errors) ? $errors : [$errors], $response->status());
        }

        return $response->json('data') ?? [];
    }

    private function backoff(int $attempt, ?Response $response = null): void
    {
        $retryAfter = (float) ($response?->header('Retry-After') ?: 0);
        $seconds = $retryAfter > 0 ? min($retryAfter, 10) : min(2 ** ($attempt - 1), 8);

        Sleep::usleep((int) ($seconds * 1_000_000));
    }
}
