<?php

namespace App\Services\Reports\Mapping;

/** One source that made the list, ranked, with the id its result file is saved under (null when it has none). */
final readonly class ListedSource
{
    public function __construct(
        public int $rank,
        public ?string $id,
        public int $matchedWords,
        public float $percent,
        public ?string $category,
        public string $name,
    ) {}
}
