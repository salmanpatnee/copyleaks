<?php

namespace App\Services\Copyleaks;

use App\Actions\AdvanceExport;
use App\Actions\FinishScan;
use App\Enums\EventKind;
use App\Enums\ScanStatus;
use App\Jobs\StartExportJob;
use App\Models\Scan;
use App\Models\ScanEvent;
use App\Services\ScanTransitioner;
use Illuminate\Support\Carbon;

/**
 * Rescues scans stuck at `fetching` (spec 0005, AC-10). It never creates a scan and
 * never calls Submit. Fifteen minutes always means "start the next try", never "fail".
 */
class FetchingScanSweeper
{
    public const TOO_LATE_REASON = 'The files could not be stored in time.';

    public function __construct(
        private readonly FinishScan $finish,
        private readonly AdvanceExport $advance,
        private readonly ExportAttempts $attempts,
        private readonly ScanTransitioner $transitioner,
    ) {}

    /** @return int how many fetching scans were looked at */
    public function sweep(): int
    {
        $count = 0;

        Scan::query()->where('status', ScanStatus::Fetching->value)->each(function (Scan $scan) use (&$count): void {
            $this->handle($scan);
            $count++;
        });

        return $count;
    }

    private function handle(Scan $scan): void
    {
        if (($this->finish)($scan) || $scan->status !== ScanStatus::Fetching) {
            return;
        }

        $since = $this->fetchingSince($scan);

        if ($since !== null && $since->addHours((int) config('copyleaks.export_give_up_hours'))->isPast()) {
            $this->transitioner->markFailed($scan, self::TOO_LATE_REASON);

            return;
        }

        $latest = $this->attempts->latest($scan);

        if ($latest === null) {
            StartExportJob::dispatch($scan->getKey(), 1);

            return;
        }

        $stuck = (int) config('copyleaks.export_stuck_minutes');

        if ($latest->handled_at === null) {
            // The claim was never accepted. Reach Copyleaks again on the same try. This does not count toward the 3.
            if ($latest->received_at->addMinutes($stuck)->isPast()) {
                StartExportJob::dispatch($scan->getKey(), $this->attempts->attemptOf($latest));
            }

            return;
        }

        if ($this->attempts->hasCompletion($scan, $latest) || $latest->handled_at->addMinutes($stuck)->isPast()) {
            ($this->advance)($scan);
        }
    }

    /** When Copyleaks first told us the scan was finished: the `completed` status event of spec 0004. */
    private function fetchingSince(Scan $scan): ?Carbon
    {
        $received = ScanEvent::query()
            ->where('scan_id', $scan->getKey())
            ->where('kind', EventKind::Status->value)
            ->where('status', 'completed')
            ->value('received_at');

        return $received === null ? null : Carbon::parse($received);
    }
}
