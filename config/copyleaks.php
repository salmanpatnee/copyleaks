<?php

/*
|--------------------------------------------------------------------------
| Copyleaks (spec 0004)
|--------------------------------------------------------------------------
|
| Keys live in .env only. Never log them. The option values under `options`
| are the only scan options we send (AC-6). Changing one means a new paid scan.
|
*/

return [

    'email' => env('COPYLEAKS_EMAIL'),

    'api_key' => env('COPYLEAKS_API_KEY'),

    // Sandbox scans cost nothing and return mock results.
    'sandbox' => (bool) env('COPYLEAKS_SANDBOX', true),

    // Public base address Copyleaks calls back. Empty means use APP_URL. Must start with https://.
    'webhook_url' => env('COPYLEAKS_WEBHOOK_URL'),

    // Long random string sent by Copyleaks in the X-Webhook-Secret header.
    'webhook_secret' => env('COPYLEAKS_WEBHOOK_SECRET'),

    'api_base' => 'https://api.copyleaks.com',

    'id_base' => 'https://id.copyleaks.com',

    // The Copyleaks login token lasts 48 hours. We cache it for 47.
    'token_cache_hours' => 47,

    'options' => [
        'ai_sensitivity' => 2,
        'expiration_hours' => 72,
    ],

    // Used only for the credit estimate on the Submit panel.
    'words_per_page_estimate' => 500,

    // The submit job never sends after this many minutes from the scan being created.
    'submit_deadline_minutes' => 15,

    // How long a scan must wait before staff may ask Copyleaks to resend its result.
    'resend_wait_minutes' => 10,

    'max_webhook_bytes' => 10 * 1024 * 1024,

];
