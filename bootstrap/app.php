<?php

use App\Exceptions\ShopifyApiException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Safe, token-free message to the client; 401 makes the frontend re-auth once.
        $exceptions->render(function (ShopifyApiException $e, Request $request) {
            if ($e->status === 401) {
                return response()->json(['message' => 'Shopify authorization expired. Reload the app.'], 401, [
                    'X-Shopify-Retry-Invalid-Session-Request' => '1',
                ]);
            }

            if ($e->status === 403) {
                return response()->json(['message' => $e->getMessage()], 403);
            }

            return response()->json([
                'message' => $e->status === 422 ? $e->getMessage() : 'Shopify is not responding right now. Try again in a moment.',
                'errors' => $e->status === 422 ? $e->errors : [],
            ], $e->status === 422 ? 422 : 502);
        });
    })->create();
