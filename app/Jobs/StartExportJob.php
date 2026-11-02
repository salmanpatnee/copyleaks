<?php

namespace App\Jobs;

use App\Actions\AdvanceExport;
use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Models\ScanEvent;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\ExportAttempts;
use App\Services\Copyleaks\ExportOutcome;
use App\Services\Copyleaks\ExportRequest;
use App\Services\Copyleaks\ScanClient;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\Copyleaks\ScanSummaryReader;
use App\Services\Copyleaks\WebhookUrlBuilder;
use App\Services\ScanTransitioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes one export call for one numbered try (spec 0005, AC-1, AC-11).
 *
 * The caller works out the attempt number and passes it in. The job never counts.
 * It claims the try with a unique `export_started` event, so a repeat reaches
 * Copyleaks with the same export ID (Copyleaks answers 409) and never starts a
 * second try. It never starts a scan.
 */
class StartExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const GONE_REASON = 'Copyleaks no longer has this scan.';

    public int $tries = 3;

    /** Seconds to wait before try 2 and try 3 of the same export call. */
    private const BACKOFF = [30, 120];

    public function __construct(public readonly int $scanId, public readonly int $attempt) {}

    public function handle(
        ScanClient $client,
        ExportAttempts $attempts,
        ScanSummaryReader $reader,
        WebhookUrlBuilder $urls,
        CopyleaksSettings $settings,
        ScanTransitioner $transitioner,
        AdvanceExport $advance,
    ): void {
        $scan = Scan::query()->find($this->scanId);

        if ($scan === null || $scan->status !== ScanStatus::Fetching || $this->attempt > $attempts->maxAttempts()) {
            return;
        }

        $summary = $reader->read($scan);

        if ($summary === null) {
            Log::warning('Copyleaks export skipped: the saved summary could not be read.', ['scan_id' => $scan->copyleaks_scan_id]);

            return;
        }

        $claim = $attempts->claim($scan, $this->attempt, $summary);

        // Already accepted by Copyleaks. A second call would only repeat it.
        if ($claim->handled_at !== null) {
            return;
        }

        Scan::query()->whereKey($scan->getKey())->update(['copyleaks_export_id' => $claim->status]);

        try {
            $result = $client->startExport($this->requestFor($scan, $claim, $attempts, $urls, $settings));
        } catch (ScanClientUnavailable) {
            $this->retryOrGiveUp($scan, $claim->status);

            return;
        }

        if ($result->outcome === ExportOutcome::NotFound) {
            $transitioner->markFailed($scan, self::GONE_REASON);

            return;
        }

        $this->markAccepted($claim);

        if (! $result->outcome->wasAccepted()) {
            Log::warning('Copyleaks refused an export try.', [
                'scan_id' => $scan->copyleaks_scan_id,
                'export_id' => $claim->status,
                'attempt' => $this->attempt,
                'status' => $result->code,
            ]);

            // An accepted try that failed. The normal retry rules apply.
            $advance($scan);
        }
    }

    private function requestFor(Scan $scan, ScanEvent $claim, ExportAttempts $attempts, WebhookUrlBuilder $urls, CopyleaksSettings $settings): ExportRequest
    {
        $endpoints = [];

        foreach ($attempts->expectedResultIds($claim) as $resultId) {
            $endpoints[$resultId] = $urls->resultUrl($scan->copyleaks_scan_id, $claim->status, $resultId);
        }

        return new ExportRequest(
            $scan->copyleaks_scan_id,
            $claim->status,
            $endpoints,
            $urls->textUrl($scan->copyleaks_scan_id, $claim->status),
            $urls->completionUrl($scan->copyleaks_scan_id, $claim->status),
            $settings->webhookSecret(),
        );
    }

    private function markAccepted(ScanEvent $claim): void
    {
        ScanEvent::query()->whereKey($claim->getKey())->whereNull('handled_at')->update(['handled_at' => now()]);
    }

    /**
     * A connection error, timeout, 429 or 5xx: try the same export ID again. A short
     * outage never uses up an export try and never moves the scan. After the last
     * call the sweep picks the scan up again.
     */
    private function retryOrGiveUp(Scan $scan, string $exportId): void
    {
        Log::warning('Copyleaks could not be reached for an export call.', [
            'scan_id' => $scan->copyleaks_scan_id,
            'export_id' => $exportId,
            'attempt' => $this->attempts(),
        ]);

        if ($this->attempts() >= $this->tries) {
            throw new ScanClientUnavailable('Export call gave up after '.$this->tries.' tries for scan '.$scan->copyleaks_scan_id.'.');
        }

        $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Copyleaks export job failed.', ['scan_id_row' => $this->scanId, 'attempt' => $this->attempt]);
    }
}
