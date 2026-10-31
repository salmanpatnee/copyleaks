<?php

use App\Http\Controllers\CopyleaksWebhookController;
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
