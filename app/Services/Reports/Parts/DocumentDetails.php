<?php

namespace App\Services\Reports\Parts;

/** The cover facts. Any field can be null, and then its line is left out. */
final readonly class DocumentDetails
{
    public function __construct(
        public ?string $author = null,
        public ?string $title = null,
        public ?string $assignment = null,
        public ?string $submissionId = null,
        public ?string $submissionDate = null,
        public ?string $downloadDate = null,
        public ?string $fileName = null,
        public ?string $fileSize = null,
        public ?int $pages = null,
        public ?int $words = null,
        public ?int $characters = null,
    ) {}
}
