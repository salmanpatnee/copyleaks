<?php

namespace App\Jobs;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Services\Copyleaks\ScanClient;
use App\Services\Copyleaks\ScanClientUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Removes the scan on Copyleaks once we hold our own copy (spec 0005, AC-12).
 *
 * One try only. A failed delete is logged and changes nothing: the 72 hour expiry
 * we already send is the backup. It never runs for a scan that is not `completed`.
 */
class DeleteRemoteScanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $scanId) {}

    public function handle(ScanClient $client): void
    {
        $scan = Scan::query()->find($this->scanId);

        if ($scan === null || $scan->status !== ScanStatus::Completed || $scan->remote_deleted_at !== null) {
            return;
        }

        try {
            $deleted = $client->deleteScan($scan->copyleaks_scan_id);
        } catch (ScanClientUnavailable) {
            $deleted = false;
        }

        if (! $deleted) {
            Log::warning('Copyleaks scan was not deleted. The 72 hour expiry will remove it.', ['scan_id' => $scan->copyleaks_scan_id]);

            return;
        }

        Scan::query()->whereKey($scan->getKey())->update(['remote_deleted_at' => now()]);
    }
}
