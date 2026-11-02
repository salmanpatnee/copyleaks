<?php

namespace App\Services;

use App\Enums\ScanStatus;
use App\Models\Scan;

/**
 * The only place a scan's status changes (spec 0002, AC-11).
 *
 * Every move is one guarded update that only applies while the scan is still
 * in an allowed earlier state. A move that matches no row is skipped quietly,
 * which is how a second job loses the race safely.
 */
class ScanTransitioner
{
    /**
     * @param  array<string, mixed>  $attributes  extra columns to set in the same update
     * @return bool true when this call made the move, false when it was skipped
     */
    public function move(Scan $scan, ScanStatus $to, array $attributes = []): bool
    {
        $changed = Scan::query()
            ->whereKey($scan->getKey())
            ->whereIn('status', array_map(fn (ScanStatus $from): string => $from->value, $to->allowedFrom()))
            ->update([...$attributes, 'status' => $to->value]);

        if ($changed === 0) {
            // Another job got there first. Show the model what is really stored.
            $scan->refresh();

            return false;
        }

        $scan->refresh();

        return true;
    }

    /** Copyleaks accepted the scan. `submitted_at` is set only now, never before the call. */
    public function markWaiting(Scan $scan): bool
    {
        return $this->move($scan, ScanStatus::Waiting, ['submitted_at' => now()]);
    }

    public function markFetching(Scan $scan): bool
    {
        return $this->move($scan, ScanStatus::Fetching);
    }

    /**
     * All artifacts are saved.
     *
     * @param  array<string, mixed>  $attributes  extra columns set in the same update, such as the scores
     */
    public function markCompleted(Scan $scan, array $attributes = []): bool
    {
        return $this->move($scan, ScanStatus::Completed, ['completed_at' => now(), ...$attributes]);
    }

    /** The reason is an error code and message, or our own text. Never manuscript text or secrets. */
    public function markFailed(Scan $scan, string $reason): bool
    {
        return $this->move($scan, ScanStatus::Failed, ['failure_reason' => $reason]);
    }
}
