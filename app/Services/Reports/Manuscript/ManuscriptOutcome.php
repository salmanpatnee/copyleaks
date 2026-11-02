<?php

namespace App\Services\Reports\Manuscript;

use App\Enums\ManuscriptFallbackReason;
use App\Enums\ManuscriptMode;

/** The mode plus reason that the caller saves on the report row. The reason is null exactly when the mode is `original`. */
final readonly class ManuscriptOutcome
{
    public function __construct(
        public ManuscriptMode $mode,
        public ?ManuscriptFallbackReason $reason,
    ) {}
}
