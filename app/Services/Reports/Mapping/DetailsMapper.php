<?php

namespace App\Services\Reports\Mapping;

use App\Models\Scan;
use App\Services\Reports\Parts\DocumentDetails;
use Illuminate\Support\Carbon;

/** Cover facts from the submission (spec 0012). */
class DetailsMapper
{
    public function __construct(
        private ReportIdFormatter $ids,
        private ReportDateFormatter $dates,
        private FileSizeFormatter $sizes,
    ) {}

    public function map(Scan $scan, int $totalWords, int $textLength): DocumentDetails
    {
        $submission = $scan->submission;

        return new DocumentDetails(
            author: $this->clean($submission->author),
            title: $this->clean($submission->title),
            assignment: $this->clean($submission->assignment),
            submissionId: $submission->submission_number === null ? null : $this->ids->format($submission->submission_number),
            submissionDate: $submission->created_at === null ? null : $this->dates->format($submission->created_at),
            downloadDate: $this->dates->format(Carbon::now()),
            fileName: $this->clean($submission->original_name),
            fileSize: $this->sizes->format($submission->file_size),
            pages: ($submission->page_count ?? 0) > 0 ? $submission->page_count : null,
            words: $totalWords,
            characters: $textLength,
        );
    }

    private function clean(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
