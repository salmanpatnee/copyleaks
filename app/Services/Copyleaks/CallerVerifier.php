<?php

namespace App\Services\Copyleaks;

use App\Models\Scan;
use Illuminate\Http\Request;

/**
 * The token and secret checks shared by every public Copyleaks address. Null means "answer the empty 404".
 * The secret header can be left out for the one address Copyleaks cannot add headers to (the export completion call).
 */
class CallerVerifier
{
    public function __construct(
        private readonly WebhookToken $tokens,
        private readonly CopyleaksSettings $settings,
    ) {}

    public function scanFor(Request $request, bool $requireSecret = true): ?Scan
    {
        $scan = Scan::query()->where('copyleaks_scan_id', (string) $request->route('scanId'))->first();

        if ($scan === null || ! hash_equals($scan->webhook_token_hash, $this->tokens->hash((string) $request->route('token')))) {
            return null;
        }

        if (! $requireSecret) {
            return $scan;
        }

        $secret = $this->settings->webhookSecret();

        if ($secret === '' || ! hash_equals($secret, (string) $request->header('X-Webhook-Secret', ''))) {
            return null;
        }

        return $scan;
    }
}
