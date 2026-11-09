<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Services\Routing\ProductTagResolver;
use App\Services\Shopify\AdminGraphql;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class ProductTagResolverTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    public function test_it_follows_all_pages_and_returns_only_requested_tags(): void
    {
        $shop = $this->installedShop();
        $graphql = Mockery::mock(AdminGraphql::class);
        $graphql->shouldReceive('query')->twice()->andReturn(
            ['products' => [
                'nodes' => [
                    ['id' => 'gid://shopify/Product/1', 'tags' => ['Fragile', 'New']],
                    ['id' => 'gid://shopify/Product/2', 'tags' => ['New']],
                ],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
            ]],
            ['products' => [
                'nodes' => [['id' => 'gid://shopify/Product/3', 'tags' => ['FROZEN']]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]],
        );
        $this->app->instance(AdminGraphql::class, $graphql);

        $products = app(ProductTagResolver::class)->resolve($shop, ['fragile', 'frozen']);

        $this->assertSame([
            'gid://shopify/Product/1' => ['fragile'],
            'gid://shopify/Product/3' => ['frozen'],
        ], $products);
    }

    public function test_it_rejects_more_than_the_complete_product_cap_instead_of_returning_partial_data(): void
    {
        $shop = $this->installedShop();
        $pages = [];
        for ($page = 1; $page <= 8; $page++) {
            $nodes = [];
            for ($product = 1; $product <= 250; $product++) {
                $id = (($page - 1) * 250) + $product;
                $nodes[] = ['id' => "gid://shopify/Product/{$id}", 'tags' => ['fragile']];
            }
            $pages[] = ['products' => [
                'nodes' => $nodes,
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => "cursor-{$page}"],
            ]];
        }
        $pages[] = ['products' => [
            'nodes' => [['id' => 'gid://shopify/Product/2001', 'tags' => ['fragile']]],
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ]];

        $graphql = Mockery::mock(AdminGraphql::class);
        $graphql->shouldReceive('query')->times(9)->andReturn(...$pages);
        $this->app->instance(AdminGraphql::class, $graphql);

        $this->expectException(ShopifyApiException::class);
        $this->expectExceptionMessage('Too many products match routing tags');

        app(ProductTagResolver::class)->resolve($shop, ['fragile']);
    }
}
