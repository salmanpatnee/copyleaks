<?php

namespace App\Services\Copyleaks;

use App\Enums\ScanStatus;
use App\Models\Submission;

/**
 * A duplicate means another submission with the same file checksum already has a
 * real scan that did not fail (AC-3). Sandbox scans cost nothing, so they do not count.
 */
class DuplicateScanFinder
{
    /** The newest earlier submission that was already scanned, or null. */
    public function find(Submission $submission): ?Submission
    {
        return Submission::query()
            ->where('file_sha256', $submission->file_sha256)
            ->whereKeyNot($submission->getKey())
            ->whereHas('scans', fn ($scans) => $scans
                ->where('status', '!=', ScanStatus::Failed->value)
                ->where('is_sandbox', false))
            ->latest('id')
            ->first();
    }
}
