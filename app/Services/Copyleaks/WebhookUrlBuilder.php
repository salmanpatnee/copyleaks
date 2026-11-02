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

    /** Where Copyleaks pushes one result file of an export. */
    public function resultUrl(string $scanId, string $exportId, string $resultId): string
    {
        return $this->exportBase($scanId, $exportId).'/result/'.$resultId;
    }

    /** Where Copyleaks pushes the crawled text of an export. */
    public function textUrl(string $scanId, string $exportId): string
    {
        return $this->exportBase($scanId, $exportId).'/text';
    }

    /** Where Copyleaks reports that an export is finished. */
    public function completionUrl(string $scanId, string $exportId): string
    {
        return $this->exportBase($scanId, $exportId).'/completed';
    }

    private function exportBase(string $scanId, string $exportId): string
    {
        return $this->settings->webhookBase()
            .'/webhooks/copyleaks/'.$scanId.'/'.$this->token->for($scanId).'/export/'.$exportId;
    }
}
