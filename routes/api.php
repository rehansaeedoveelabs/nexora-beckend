<?php

use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\WebhookController;
use App\Http\Middleware\VerifyShopifySessionToken;
use App\Http\Middleware\VerifyShopifyWebhook;
use Illuminate\Support\Facades\Route;

// Shopify → Nexora. Authenticated by HMAC, not by session token.
Route::post('webhooks', WebhookController::class)
    ->middleware(VerifyShopifyWebhook::class)
    ->name('webhooks');

// Embedded frontend → Nexora. Authenticated by the App Bridge session token.
Route::middleware([VerifyShopifySessionToken::class, 'throttle:api'])->group(function () {
    Route::get('session', [SessionController::class, 'show']);
    Route::get('setup', [SessionController::class, 'setup']);

    Route::get('locations', [LocationController::class, 'index']);
    Route::post('locations/sync', [LocationController::class, 'sync'])->middleware('throttle:6,1');
    Route::patch('locations/bulk', [LocationController::class, 'bulkUpdate']);
    Route::patch('locations/{id}', [LocationController::class, 'update'])->whereNumber('id');
});
