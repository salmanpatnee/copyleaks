<?php

namespace App\Services\Copyleaks;

/** What the export job hands the client. Every address is already built, with the token inside. */
final readonly class ExportRequest
{
    /** @param  array<string, string>  $resultEndpoints  result ID => push address */
    public function __construct(
        public string $scanId,
        public string $exportId,
        public array $resultEndpoints,
        public string $textEndpoint,
        public string $completionEndpoint,
        public string $secret,
    ) {}
}
