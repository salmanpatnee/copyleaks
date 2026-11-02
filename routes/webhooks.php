<?php

use App\Http\Controllers\CopyleaksExportCompletedController;
use App\Http\Controllers\CopyleaksExportPushController;
use App\Http\Controllers\CopyleaksWebhookController;
use App\Http\Middleware\VerifyCopyleaksExportPush;
use App\Http\Middleware\VerifyCopyleaksWebhook;
use Illuminate\Support\Facades\Route;

/*
| Public webhooks. Registered in bootstrap/app.php outside the web group, so there
| is no session and no CSRF. The throttle runs first, then every check (AC-8).
| The status word is checked in the middleware, not here, so a bad word gets the
| same empty 404 as every other failed check.
*/
Route::post('webhooks/copyleaks/{scanId}/{token}/{status}', CopyleaksWebhookController::class)
    ->middleware(['throttle:copyleaks-webhook', VerifyCopyleaksWebhook::class])
    ->name('webhooks.copyleaks');

/*
| Export pushes (spec 0005). Copyleaks sends each result file, the crawled text and a
| completion call here. These addresses are longer than the status one, so they never clash.
*/
Route::prefix('webhooks/copyleaks/{scanId}/{token}/export/{exportId}')
    ->middleware('throttle:copyleaks-export')
    ->group(function (): void {
        Route::post('result/{resultId}', CopyleaksExportPushController::class)
            ->middleware(VerifyCopyleaksExportPush::class.':result')
            ->name('webhooks.copyleaks.export.result');

        Route::post('text', CopyleaksExportPushController::class)
            ->middleware(VerifyCopyleaksExportPush::class.':text')
            ->name('webhooks.copyleaks.export.text');

        Route::post('completed', CopyleaksExportCompletedController::class)
            ->middleware(VerifyCopyleaksExportPush::class.':completed')
            ->name('webhooks.copyleaks.export.completed');
    });
