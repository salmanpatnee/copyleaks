<?php

namespace App\Events;

use App\Models\Scan;

/**
 * Fired once, after the commit, when a `completed` webhook moved a scan to `fetching`.
 * The seam for feature 7 (raw results storage). No listener in feature 6.
 */
class ScanResultsArrived
{
    public function __construct(public readonly Scan $scan) {}
}
