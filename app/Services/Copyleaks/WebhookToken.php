<?php

namespace App\Services\Copyleaks;

/**
 * The per scan webhook token. Derived from the scan ID and APP_KEY, so the plain
 * token is never stored and never sits in the jobs table. Only its hash is saved.
 */
class WebhookToken
{
    public function for(string $scanId): string
    {
        return hash_hmac('sha256', 'copyleaks-webhook|'.$scanId, (string) config('app.key'));
    }

    public function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hashFor(string $scanId): string
    {
        return $this->hash($this->for($scanId));
    }
}
