<?php

namespace Tests\Feature;

use App\Jobs\BootstrapShop;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_request_without_session_token_is_rejected_with_retry_header(): void
    {
        $this->getJson('/api/session')
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');
    }

    public function test_request_with_forged_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->sessionToken(secret: 'forged-secret-0123456789abcdef0123456'))
            ->getJson('/api/session')
            ->assertUnauthorized();

        $this->assertDatabaseCount('shops', 0);
    }

    public function test_first_open_exchanges_token_stores_it_encrypted_and_bootstraps(): void
    {
        Http::fake([
            'demo.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_new',
                'expires_in' => 3600,
                'refresh_token' => 'shprt_new',
                'refresh_token_expires_in' => 7776000,
                'scope' => 'read_locations',
            ]),
        ]);

        $this->apiAs('demo.myshopify.com')
            ->getJson('/api/session')
            ->assertOk()
            ->assertJsonPath('data.shop.domain', 'demo.myshopify.com')
            ->assertJsonPath('data.shop.syncing', true)
            ->assertJsonMissingPath('data.shop.access_token');

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:token-exchange'
            && $r['subject_token_type'] === 'urn:ietf:params:oauth:token-type:id_token'
            && $r['requested_token_type'] === 'urn:shopify:params:oauth:token-type:offline-access-token'
            && (int) $r['expiring'] === 1
            && $r['client_secret'] === config('shopify.api_secret'));

        $shop = Shop::where('domain', 'demo.myshopify.com')->firstOrFail();
        $this->assertSame('shpat_new', $shop->access_token);
        $this->assertSame('shprt_new', $shop->refresh_token);
        $this->assertSame('read_locations', $shop->scopes);
        $this->assertNotNull($shop->installed_at);

        // Encrypted at rest: raw column must not contain the token.
        $raw = Shop::query()->toBase()->where('id', $shop->id)->value('access_token');
        $this->assertStringNotContainsString('shpat_new', $raw);

        Queue::assertPushed(BootstrapShop::class, fn ($job) => $job->shopId === $shop->id);
    }

    public function test_installed_shop_does_not_exchange_again(): void
    {
        $this->installedShop();

        $this->apiAs('demo.myshopify.com')->getJson('/api/session')->assertOk();

        Http::assertNothingSent();
        Queue::assertNotPushed(BootstrapShop::class);
    }

    public function test_failed_exchange_returns_401_and_stores_no_tokens(): void
    {
        Http::fake(['*/admin/oauth/access_token' => Http::response(['error' => 'invalid_subject_token'], 400)]);

        $this->apiAs('demo.myshopify.com')
            ->getJson('/api/session')
            ->assertUnauthorized()
            ->assertHeader('X-Shopify-Retry-Invalid-Session-Request', '1');

        $this->assertNull(Shop::where('domain', 'demo.myshopify.com')->value('refresh_token'));
    }

    public function test_reinstall_after_uninstall_exchanges_again(): void
    {
        $this->installedShop(attributes: ['uninstalled_at' => now(), 'refresh_token' => null]);

        Http::fake(['*/admin/oauth/access_token' => Http::response([
            'access_token' => 'shpat_again', 'expires_in' => 3600,
            'refresh_token' => 'shprt_again', 'refresh_token_expires_in' => 7776000,
        ])]);

        $this->apiAs('demo.myshopify.com')->getJson('/api/session')->assertOk();

        $shop = Shop::where('domain', 'demo.myshopify.com')->first();
        $this->assertNull($shop->uninstalled_at);
        $this->assertSame('shpat_again', $shop->access_token);
    }
}
