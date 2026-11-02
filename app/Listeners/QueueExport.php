<?php

namespace App\Listeners;

use App\Events\ScanResultsArrived;
use App\Jobs\StartExportJob;
use App\Services\Copyleaks\ExportAttempts;

/** The summary is saved and the scan is `fetching`: ask Copyleaks to push the files (spec 0005, AC-1). */
class QueueExport
{
    public function __construct(private readonly ExportAttempts $attempts) {}

    public function handle(ScanResultsArrived $event): void
    {
        StartExportJob::dispatch($event->scan->getKey(), $this->attempts->nextAttempt($event->scan));
    }
}
