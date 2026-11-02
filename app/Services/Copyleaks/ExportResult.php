<?php

namespace App\Services\Copyleaks;

final readonly class ExportResult
{
    private function __construct(
        public ExportOutcome $outcome,
        public ?string $code = null,
    ) {}

    public static function started(): self
    {
        return new self(ExportOutcome::Started);
    }

    public static function conflict(): self
    {
        return new self(ExportOutcome::Conflict);
    }

    public static function notFound(): self
    {
        return new self(ExportOutcome::NotFound);
    }

    /** `$code` is the HTTP status word only, safe to log. */
    public static function refused(string $code): self
    {
        return new self(ExportOutcome::Refused, $code);
    }
}
