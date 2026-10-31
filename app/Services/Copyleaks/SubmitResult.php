<?php

namespace App\Services\Copyleaks;

final readonly class SubmitResult
{
    private function __construct(
        public SubmitOutcome $outcome,
        public ?string $reason = null,
    ) {}

    public static function created(): self
    {
        return new self(SubmitOutcome::Created);
    }

    public static function conflict(): self
    {
        return new self(SubmitOutcome::Conflict);
    }

    /** `$reason` is already `"{code}: {message}"`. */
    public static function refused(string $reason): self
    {
        return new self(SubmitOutcome::Refused, $reason);
    }
}
