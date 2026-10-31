<?php

namespace App\Services\Copyleaks;

/** Reads config/copyleaks.php and answers whether Copyleaks is set up (AC-13). */
class CopyleaksSettings
{
    public function email(): string
    {
        return (string) config('copyleaks.email');
    }

    public function apiKey(): string
    {
        return (string) config('copyleaks.api_key');
    }

    public function webhookSecret(): string
    {
        return (string) config('copyleaks.webhook_secret');
    }

    public function isSandbox(): bool
    {
        return (bool) config('copyleaks.sandbox');
    }

    /** The public base address, no trailing slash. COPYLEAKS_WEBHOOK_URL, else APP_URL. */
    public function webhookBase(): string
    {
        $base = (string) (config('copyleaks.webhook_url') ?: config('app.url'));

        return rtrim($base, '/');
    }

    public function deadlineMinutes(): int
    {
        return (int) config('copyleaks.submit_deadline_minutes');
    }

    public function resendWaitMinutes(): int
    {
        return (int) config('copyleaks.resend_wait_minutes');
    }

    public function isReady(): bool
    {
        return $this->email() !== ''
            && $this->apiKey() !== ''
            && $this->webhookSecret() !== ''
            && str_starts_with($this->webhookBase(), 'https://');
    }

    /** About N credits: ceil(pages * words per page / 250). Null when the page count is unknown. */
    public function estimateCredits(?int $pageCount): ?int
    {
        if ($pageCount === null) {
            return null;
        }

        return (int) ceil($pageCount * (int) config('copyleaks.words_per_page_estimate') / 250);
    }
}
