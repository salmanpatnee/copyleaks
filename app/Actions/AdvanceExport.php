<?php

namespace App\Actions;

use App\Enums\ScanStatus;
use App\Jobs\StartExportJob;
use App\Models\Scan;
use App\Services\Copyleaks\ExportAttempts;
use App\Services\ScanTransitioner;

/**
 * Called when an export try has ended (a completion call, 15 minutes, or a refused try).
 * The finish check runs first, always. If files are still missing it starts the next
 * try, or fails the scan once 3 accepted tries have ended (spec 0005, AC-9). The
 * summary artifact is never deleted.
 */
class AdvanceExport
{
    public const FAILURE_REASON = 'Export incomplete after 3 tries. The summary is kept.';

    public function __construct(
        private readonly FinishScan $finish,
        private readonly ExportAttempts $attempts,
        private readonly ScanTransitioner $transitioner,
    ) {}

    public function __invoke(Scan $scan): void
    {
        if (($this->finish)($scan) || $scan->status !== ScanStatus::Fetching) {
            return;
        }

        if ($this->attempts->acceptedCount($scan) >= $this->attempts->maxAttempts()) {
            $this->transitioner->markFailed($scan, self::FAILURE_REASON);

            return;
        }

        StartExportJob::dispatch($scan->getKey(), $this->attempts->nextAttempt($scan));
    }
}
