<?php

namespace App\Actions;

use App\Enums\ArtifactKind;
use App\Enums\ScanStatus;
use App\Jobs\DeleteRemoteScanJob;
use App\Models\Scan;
use App\Services\Copyleaks\ScanSummary;
use App\Services\Copyleaks\ScanSummaryReader;
use App\Services\ScanTransitioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The finish check (spec 0005, AC-7, AC-8). It looks at our own files only.
 *
 * It passes when the `text` artifact and one `result` artifact for every expected
 * result ID exist. Then one transaction moves `fetching` to `completed` and sets
 * the scores. The remote delete is queued after the commit, and only by the call
 * whose move really happened, so two finishers never queue two deletes.
 */
class FinishScan
{
    public function __construct(
        private readonly ScanSummaryReader $reader,
        private readonly ScanTransitioner $transitioner,
    ) {}

    /** @return bool true when the scan is completed (now or before), false when files are still missing */
    public function __invoke(Scan $scan): bool
    {
        $scan->refresh();

        if ($scan->status === ScanStatus::Completed) {
            return true;
        }

        if ($scan->status !== ScanStatus::Fetching) {
            return false;
        }

        $summary = $this->reader->read($scan);

        if ($summary === null || ! $this->filesComplete($scan, $summary)) {
            return false;
        }

        $similarity = $summary->similarityPercent();

        if ($similarity === null) {
            // Never invent a number. The scan stays `fetching` and the normal retry rules apply.
            Log::warning('Copyleaks summary has no similarity score.', ['scan_id' => $scan->copyleaks_scan_id]);

            return false;
        }

        $moved = DB::transaction(fn (): bool => $this->transitioner->markCompleted($scan, [
            'similarity_percent' => $similarity,
            'ai_percent' => $this->aiPercent($scan, $summary),
        ]));

        if ($moved) {
            DeleteRemoteScanJob::dispatch($scan->getKey());
        }

        return true;
    }

    private function filesComplete(Scan $scan, ScanSummary $summary): bool
    {
        $saved = $scan->artifacts()
            ->whereIn('kind', [ArtifactKind::Result->value, ArtifactKind::Text->value])
            ->get(['kind', 'source_id']);

        if (! $saved->contains(fn ($artifact): bool => $artifact->kind === ArtifactKind::Text)) {
            return false;
        }

        $resultIds = $saved->filter(fn ($artifact): bool => $artifact->kind === ArtifactKind::Result)->pluck('source_id')->all();

        return array_diff($summary->resultIds(), $resultIds) === [];
    }

    private function aiPercent(Scan $scan, ScanSummary $summary): ?float
    {
        $ai = $summary->aiPercent();

        if ($ai === null && $scan->ai_requested) {
            Log::warning('Copyleaks returned no usable AI value.', ['scan_id' => $scan->copyleaks_scan_id]);
        }

        return $ai;
    }
}
