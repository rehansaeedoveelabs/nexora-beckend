<?php

namespace App\Services\Routing;

use App\Exceptions\ShopifyApiException;
use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;

/**
 * Finds the products that carry the merchant's routing-rule tags, so the backend can turn
 * tag rules into product-ID rules the checkout Function can evaluate (Functions can't read tags).
 */
class ProductTagResolver
{
    /** Bound the amount of Shopify product data needed to build one config. */
    private const MAX_PRODUCTS = 2000;

    public function __construct(private readonly AdminGraphql $graphql) {}

    /**
     * @param  string[]  $tags
     * @return array<string, string[]> product GID => the rule tags it has (lowercased)
     */
    public function resolve(Shop $shop, array $tags): array
    {
        $tags = array_values(array_filter(array_unique(array_map(
            fn ($t) => mb_strtolower(trim($t)),
            $tags,
        ))));

        if ($tags === []) {
            return [];
        }

        // Shopify product search: tag:'a' OR tag:'b'. Single quotes handle tags with spaces.
        $search = implode(' OR ', array_map(fn ($t) => "tag:'".str_replace("'", "\\'", $t)."'", $tags));

        $query = 'query Products($q: String!, $cursor: String) {
            products(first: 250, after: $cursor, query: $q) {
                pageInfo { hasNextPage endCursor }
                nodes { id tags }
            }
        }';

        $out = [];
        $cursor = null;
        $seenCursors = [];

        do {
            $page = $this->graphql->query($shop, $query, ['q' => $search, 'cursor' => $cursor])['products'] ?? null;
            if (
                ! is_array($page)
                || ! is_array($page['nodes'] ?? null)
                || ! is_array($page['pageInfo'] ?? null)
                || ! is_bool($page['pageInfo']['hasNextPage'] ?? null)
            ) {
                throw new ShopifyApiException('Shopify returned an invalid product search response.');
            }

            foreach ($page['nodes'] as $node) {
                if (
                    ! is_array($node)
                    || ! is_string($node['id'] ?? null)
                    || ! is_array($node['tags'] ?? null)
                    || ! array_is_list($node['tags'])
                ) {
                    throw new ShopifyApiException('Shopify returned an invalid product in the search response.');
                }

                foreach ($node['tags'] as $tag) {
                    if (! is_string($tag)) {
                        throw new ShopifyApiException('Shopify returned invalid product tags in the search response.');
                    }
                }

                $productTags = array_map('mb_strtolower', $node['tags'] ?? []);
                $matching = array_values(array_intersect($productTags, $tags));

                if ($matching !== []) {
                    if (! array_key_exists($node['id'], $out) && count($out) >= self::MAX_PRODUCTS) {
                        throw new ShopifyApiException('Too many products match routing tags; narrow the tags before syncing checkout rules.');
                    }
                    $out[$node['id']] = $matching;
                }
            }

            $hasNextPage = $page['pageInfo']['hasNextPage'];
            $cursor = $page['pageInfo']['endCursor'] ?? null;
            if ($hasNextPage) {
                if (! is_string($cursor) || trim($cursor) === '' || isset($seenCursors[$cursor])) {
                    throw new ShopifyApiException('Shopify returned an invalid product search cursor.');
                }
                $seenCursors[$cursor] = true;
            }
        } while ($hasNextPage);

        return $out;
    }
}
