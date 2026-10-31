<?php

namespace App\Services\Copyleaks;

/**
 * Builds the status address by joining strings. `route()` would encode the
 * `{STATUS}` word, and Copyleaks must see it as written to fill it in.
 */
class WebhookUrlBuilder
{
    public function __construct(
        private readonly CopyleaksSettings $settings,
        private readonly WebhookToken $token,
    ) {}

    public function statusUrl(string $scanId): string
    {
        return $this->settings->webhookBase()
            .'/webhooks/copyleaks/'.$scanId.'/'.$this->token->for($scanId).'/{STATUS}';
    }
}
