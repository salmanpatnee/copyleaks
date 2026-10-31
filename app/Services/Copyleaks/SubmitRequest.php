<?php

namespace App\Services\Copyleaks;

/** What the submit job hands the client. `options` is built by ScanOptions. */
final readonly class SubmitRequest
{
    /** @param  array<string, mixed>  $options */
    public function __construct(
        public string $scanId,
        public string $fileName,
        public string $contents,
        public array $options,
    ) {}
}
