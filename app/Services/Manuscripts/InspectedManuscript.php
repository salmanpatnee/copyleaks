<?php

namespace App\Services\Manuscripts;

use App\Enums\ManuscriptType;

/** The facts read from a checked file. A null page count means unknown (Word). */
final readonly class InspectedManuscript
{
    public function __construct(
        public ManuscriptType $type,
        public ?int $pageCount,
    ) {}
}
