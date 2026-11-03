<?php

namespace App\Services\Reports\Mapping;

use App\Models\Scan;
use App\Services\Reports\Parts\DocumentDetails;

/** Cover facts from the submission and the scan. Dates, size, pages and characters stay null until feature 21 (AC-11). */
class DetailsMapper
{
    public function map(Scan $scan, int $totalWords): DocumentDetails
    {
        $submission = $scan->submission;

        return new DocumentDetails(
            author: $this->clean($submission->author),
            title: $this->clean($submission->title),
            assignment: $this->clean($submission->assignment),
            submissionId: $scan->copyleaks_scan_id,
            fileName: $this->clean($submission->original_name),
            words: $totalWords,
        );
    }

    private function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
