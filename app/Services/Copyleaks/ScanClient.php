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

    /**
     * Asks Copyleaks to push every result file and the crawled text to our addresses (spec 0005).
     *
     * @throws ScanClientUnavailable on a connection error, a timeout, a 429 or a 5xx
     */
    public function startExport(ExportRequest $request): ExportResult;

    /**
     * Deletes a completed scan on Copyleaks. True only when Copyleaks accepted the request (202).
     *
     * @throws ScanClientUnavailable on a connection error, a timeout or a 5xx
     */
    public function deleteScan(string $scanId): bool;
}
