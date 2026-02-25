<?php

use Illuminate\Support\Facades\Route;
use Modules\CreemDemo\Http\Controllers\DemoController;
use Modules\CreemDemo\Http\Controllers\DemoWebhookController;

/*
|--------------------------------------------------------------------------
| Creem Demo Module Routes
|--------------------------------------------------------------------------
|
| The demo module does NOT own the default webhook endpoint.
| romansh/laravel-creem registers POST /creem/webhook and dispatches
| Laravel Events — this module listens via CreemDemoListener.
|
| However, the demo UI generates per-profile keyed endpoints:
|   POST /creem/hook/{token}
| These bypass the session and load credentials directly from cache.
|
*/

Route::name('creem-demo.')->group(function () {
    Route::get('/',        [DemoController::class, 'index'])  ->name('index');
    Route::get('/success', [DemoController::class, 'success'])->name('success');
    Route::get('/transactions', [DemoController::class, 'transactions'])->name('transactions');
});

// Per-profile keyed webhook endpoint — no CSRF, no session, credentials from cache by token
Route::post('/creem/hook/{token}', DemoWebhookController::class)
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->name('creem-demo.webhook');
