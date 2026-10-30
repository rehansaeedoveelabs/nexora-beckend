<?php

namespace App\Services\Routing;

use App\Models\Shop;
use App\Services\Shopify\AdminGraphql;

/**
 * Finds the products that carry the merchant's routing-rule tags, so the backend can turn
 * tag rules into product-ID rules the checkout Function can evaluate (Functions can't read tags).
 */
class ProductTagResolver
{
    /** Safety cap so the Function config metafield stays well under Shopify's size limit. */
    private const MAX_PRODUCTS = 2000;

    public function __construct(private readonly AdminGraphql $graphql) {}

    /**
     * @param  string[]  $tags
     * @return array<string, string[]>  product GID => the rule tags it has (lowercased)
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

        do {
            $page = $this->graphql->query($shop, $query, ['q' => $search, 'cursor' => $cursor])['products']
                ?? ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]];

            foreach ($page['nodes'] as $node) {
                $productTags = array_map('mb_strtolower', $node['tags'] ?? []);
                $matching = array_values(array_intersect($productTags, $tags));

                if ($matching !== []) {
                    $out[$node['id']] = $matching;
                    if (count($out) >= self::MAX_PRODUCTS) {
                        return $out;
                    }
                }
            }

            $cursor = $page['pageInfo']['endCursor'] ?? null;
        } while (($page['pageInfo']['hasNextPage'] ?? false) && $cursor !== null);

        return $out;
    }
}
