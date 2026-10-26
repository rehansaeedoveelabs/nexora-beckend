<?php

namespace App\Providers;

use App\Services\Shopify\AccessTokens;
use App\Services\Shopify\AdminGraphql;
use App\Services\Shopify\SessionToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SessionToken::class, fn () => new SessionToken(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.jwt_leeway'),
        ));

        $this->app->singleton(AccessTokens::class, fn () => new AccessTokens(
            (string) config('shopify.api_key'),
            (string) config('shopify.api_secret'),
            (int) config('shopify.token_refresh_margin'),
        ));

        $this->app->singleton(AdminGraphql::class, fn ($app) => new AdminGraphql(
            $app->make(AccessTokens::class),
            (string) config('shopify.api_version'),
        ));
    }

    public function boot(): void
    {
        // Keyed per shop once the session token is verified, else per IP.
        RateLimiter::for('api', function (Request $request) {
            $shop = $request->attributes->get('shop');

            return Limit::perMinute(240)->by($shop ? 'shop:'.$shop->id : 'ip:'.$request->ip());
        });
    }
}
