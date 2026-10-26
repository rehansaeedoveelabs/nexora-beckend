<?php

namespace Tests\Feature;

use App\Exceptions\ShopifyApiException;
use App\Services\Shopify\AccessTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class AccessTokensTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_fresh_token_is_returned_without_calling_shopify(): void
    {
        $shop = $this->installedShop();

        $this->assertSame('shpat_test_access', app(AccessTokens::class)->tokenFor($shop));
        Http::assertNothingSent();
    }

    public function test_token_close_to_expiry_is_refreshed_and_rotated(): void
    {
        $shop = $this->installedShop(attributes: ['access_token_expires_at' => now()->addMinute()]);

        Http::fake(['demo.myshopify.com/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_rotated',
            'expires_in' => 3600,
            'refresh_token' => 'shprt_rotated',
            'refresh_token_expires_in' => 7776000,
        ])]);

        $this->assertSame('shpat_rotated', app(AccessTokens::class)->tokenFor($shop));

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'shprt_test_refresh');

        $shop->refresh();
        $this->assertSame('shprt_rotated', $shop->refresh_token);
        $this->assertTrue($shop->access_token_expires_at->isAfter(now()->addMinutes(55)));
    }

    public function test_revoked_refresh_token_is_forgotten(): void
    {
        $shop = $this->installedShop(attributes: ['access_token_expires_at' => now()->subMinute()]);

        Http::fake(['*/admin/oauth/access_token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(AccessTokens::class)->tokenFor($shop);
            $this->fail('Expected ShopifyApiException');
        } catch (ShopifyApiException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringNotContainsString('shprt_test_refresh', $e->getMessage());
        }

        $shop->refresh();
        $this->assertNull($shop->refresh_token);
        $this->assertFalse($shop->hasUsableGrant());
    }

    public function test_expired_refresh_token_is_not_sent(): void
    {
        $shop = $this->installedShop(attributes: [
            'access_token_expires_at' => now()->subMinute(),
            'refresh_token_expires_at' => now()->subDay(),
        ]);

        $this->expectException(ShopifyApiException::class);

        try {
            app(AccessTokens::class)->tokenFor($shop);
        } finally {
            Http::assertNothingSent();
        }
    }
}
