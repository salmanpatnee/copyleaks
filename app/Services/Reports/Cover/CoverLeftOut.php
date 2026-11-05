<?php

namespace App\Services\Reports\Cover;

use App\Services\Reports\Parts\DocumentDetails;

/** Which cover spots have no value and are left out. Text that is null or blank, and counts that are null or 0. */
final class CoverLeftOut
{
    /** @return list<string> spot names, in cover order */
    public function names(DocumentDetails $d): array
    {
        $spots = [
            'Author' => $this->blank($d->author),
            'Title' => $this->blank($d->title),
            'Assignment' => $this->blank($d->assignment),
            'Submission ID' => $this->blank($d->submissionId),
            'Submission Date' => $this->blank($d->submissionDate),
            'Download Date' => $this->blank($d->downloadDate),
            'File Name' => $this->blank($d->fileName),
            'File Size' => $this->blank($d->fileSize),
            'Pages' => $d->pages === null || $d->pages === 0,
            'Words' => $d->words === null || $d->words === 0,
            'Characters' => $d->characters === null || $d->characters === 0,
        ];

        return array_keys(array_filter($spots));
    }

    private function blank(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
