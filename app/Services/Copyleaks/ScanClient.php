<?php

namespace App\Services\Copyleaks;

/**
 * The only door to Copyleaks. Nothing else may call it (spec 0004, money safety).
 */
interface ScanClient
{
    /**
     * @throws ScanClientUnavailable on a connection error, a timeout or a 5xx
     */
    public function submit(SubmitRequest $request): SubmitResult;

    /**
     * @throws ScanClientUnavailable on a connection error, a timeout or a 5xx
     */
    public function resendWebhook(string $scanId): ResendResult;
}
