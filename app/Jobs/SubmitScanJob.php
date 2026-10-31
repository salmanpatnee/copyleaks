<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\ScanClient;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\Copyleaks\ScanOptions;
use App\Services\Copyleaks\SubmitOutcome;
use App\Services\Copyleaks\SubmitRequest;
use App\Services\ScanTransitioner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Sends one scan to Copyleaks (AC-5, AC-7). Queued only by the SubmitScan action.
 *
 * The scan ID never changes, so a repeat after a lost answer gets `409` from
 * Copyleaks and moves the scan to `waiting` instead of being billed twice.
 * Logs carry only the scan ID, never keys, tokens or manuscript text (AC-14).
 */
class SubmitScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const FILE_UNREADABLE = 'The saved file could not be read.';

    public int $tries = 3;

    /** Seconds to wait before try 2 and try 3. */
    private const BACKOFF = [60, 120];

    public function __construct(public readonly int $scanId) {}

    /** A hard deadline: after this the job never sends. */
    public function retryUntil(): Carbon
    {
        $created = Scan::query()->whereKey($this->scanId)->value('created_at');

        return Carbon::parse($created ?? now())->addMinutes(app(CopyleaksSettings::class)->deadlineMinutes());
    }

    public function handle(ScanClient $client, ScanTransitioner $transitioner, ScanOptions $options, CopyleaksSettings $settings): void
    {
        $scan = Scan::query()->with('submission')->find($this->scanId);

        // Gone, or already moved by the webhook or another run: nothing to do.
        if ($scan === null || $scan->status !== ScanStatus::Submitting) {
            return;
        }

        if ($scan->created_at->addMinutes($settings->deadlineMinutes())->isPast()) {
            Log::warning('Copyleaks submit skipped: deadline passed.', ['scan_id' => $scan->copyleaks_scan_id]);

            return;
        }

        $contents = Storage::disk('local')->get($scan->submission->file_path);

        if (! is_string($contents)) {
            $transitioner->markFailed($scan, self::FILE_UNREADABLE);

            return;
        }

        try {
            $result = $client->submit(new SubmitRequest(
                $scan->copyleaks_scan_id,
                $scan->submission->original_name,
                $contents,
                $options->for($scan->copyleaks_scan_id, $scan->ai_requested, $scan->is_sandbox),
            ));
        } catch (ScanClientUnavailable) {
            $this->retryOrGiveUp($scan);

            return;
        }

        match ($result->outcome) {
            SubmitOutcome::Created, SubmitOutcome::Conflict => $transitioner->markWaiting($scan),
            SubmitOutcome::Refused => $this->handleRefusal($scan, $transitioner, (string) $result->reason),
        };
    }

    /** Only a refusal on the first try fails the scan. Later, an earlier try may have been accepted. */
    private function handleRefusal(Scan $scan, ScanTransitioner $transitioner, string $reason): void
    {
        if ($this->attempts() <= 1) {
            $transitioner->markFailed($scan, mb_substr($reason, 0, 1000));

            return;
        }

        Log::warning('Copyleaks refused a later submit try. The scan stays submitting.', ['scan_id' => $scan->copyleaks_scan_id]);
    }

    /**
     * A connection error, timeout or 5xx: try again with the same scan ID. After
     * the last try the exception lands the job in failed_jobs and the scan stays
     * `submitting` on purpose, because an unanswered call may still have been accepted.
     */
    private function retryOrGiveUp(Scan $scan): void
    {
        Log::warning('Copyleaks could not be reached for a submit try.', ['scan_id' => $scan->copyleaks_scan_id, 'attempt' => $this->attempts()]);

        if ($this->attempts() >= $this->tries) {
            throw new ScanClientUnavailable('Submit gave up after '.$this->tries.' tries for scan '.$scan->copyleaks_scan_id.'.');
        }

        $this->release(self::BACKOFF[min($this->attempts(), count(self::BACKOFF)) - 1]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('Copyleaks submit job failed.', ['scan_id_row' => $this->scanId]);
    }
}
