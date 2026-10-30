<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\CapacityController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RoutingController;
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
    Route::get('dashboard', [DashboardController::class, 'show']);
    Route::get('activity', [ActivityController::class, 'index']);

    Route::get('locations', [LocationController::class, 'index']);
    Route::post('locations/sync', [LocationController::class, 'sync'])->middleware('throttle:6,1');
    Route::patch('locations/bulk', [LocationController::class, 'bulkUpdate']);
    Route::patch('locations/{id}', [LocationController::class, 'update'])->whereNumber('id');

    Route::get('orders', [OrderController::class, 'index']);
    Route::get('orders/{orderId}', [OrderController::class, 'show'])->whereNumber('orderId');
    Route::post('orders/{orderId}/reassign', [OrderController::class, 'reassign'])->whereNumber('orderId')->middleware('throttle:10,1');
    Route::get('capacity', [CapacityController::class, 'show']);

    Route::get('routing', [RoutingController::class, 'show']);
    Route::put('routing', [RoutingController::class, 'update']);
    Route::post('routing/simulate', [RoutingController::class, 'simulate'])->middleware('throttle:60,1');
});
