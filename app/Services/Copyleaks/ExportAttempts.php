<?php

namespace App\Services\Copyleaks;

use App\Enums\EventKind;
use App\Models\Scan;
use App\Models\ScanEvent;
use Illuminate\Support\Collection;

/**
 * Answers every question about a scan's export tries from its `export_started`
 * and `export_completed` events. Callers work out the attempt number here and
 * pass it in. The export job never counts (spec 0005, AC-1).
 */
class ExportAttempts
{
    public function exportId(Scan $scan, int $attempt): string
    {
        return $scan->copyleaks_scan_id.'-'.$attempt;
    }

    public function maxAttempts(): int
    {
        return (int) config('copyleaks.export_max_attempts');
    }

    /** Claims the attempt (one row per export ID). Returns the claim, new or existing. */
    public function claim(Scan $scan, int $attempt, ScanSummary $summary): ScanEvent
    {
        $exportId = $this->exportId($scan, $attempt);

        ScanEvent::query()->insertOrIgnore([[
            'scan_id' => $scan->getKey(),
            'kind' => EventKind::ExportStarted->value,
            'status' => $exportId,
            'payload' => json_encode(['attempt' => $attempt, 'expected_result_ids' => $summary->resultIds()]),
            'received_at' => now(),
            'handled_at' => null,
        ]]);

        return $this->event($scan, $exportId) ?? throw new \LogicException('The export claim was not saved.');
    }

    public function event(Scan $scan, string $exportId): ?ScanEvent
    {
        return ScanEvent::query()
            ->where('scan_id', $scan->getKey())
            ->where('kind', EventKind::ExportStarted->value)
            ->where('status', $exportId)
            ->first();
    }

    /** @return Collection<int, ScanEvent> every claim, oldest attempt first */
    public function claims(Scan $scan): Collection
    {
        return ScanEvent::query()
            ->where('scan_id', $scan->getKey())
            ->where('kind', EventKind::ExportStarted->value)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (ScanEvent $event): int => $this->attemptOf($event))
            ->values();
    }

    /** The newest claim, accepted or not. */
    public function latest(Scan $scan): ?ScanEvent
    {
        return $this->claims($scan)->last();
    }

    /** The newest claim Copyleaks accepted. */
    public function latestAccepted(Scan $scan): ?ScanEvent
    {
        return $this->claims($scan)->filter(fn (ScanEvent $event): bool => $event->handled_at !== null)->last();
    }

    public function acceptedCount(Scan $scan): int
    {
        return $this->claims($scan)->filter(fn (ScanEvent $event): bool => $event->handled_at !== null)->count();
    }

    /** Accepted tries plus one. The number a caller hands to the next job. */
    public function nextAttempt(Scan $scan): int
    {
        return $this->acceptedCount($scan) + 1;
    }

    public function hasCompletion(Scan $scan, ScanEvent $claim): bool
    {
        return ScanEvent::query()
            ->where('scan_id', $scan->getKey())
            ->where('kind', EventKind::ExportCompleted->value)
            ->where('status', $claim->status)
            ->exists();
    }

    public function attemptOf(ScanEvent $event): int
    {
        return (int) ($event->payload['attempt'] ?? 0);
    }

    /** @return list<string> */
    public function expectedResultIds(ScanEvent $claim): array
    {
        return array_values(array_filter((array) ($claim->payload['expected_result_ids'] ?? []), 'is_string'));
    }
}
