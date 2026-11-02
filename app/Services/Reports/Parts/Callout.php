<?php

namespace App\Services\Reports\Parts;

/** A light blue info box. The title and paragraphs that are null or empty are left out. */
final readonly class Callout
{
    /**
     * @param  list<string|null>  $paragraphs
     */
    public function __construct(
        public ?string $title,
        public array $paragraphs,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}

    /** @return list<string> */
    public function cleanParagraphs(): array
    {
        return array_values(array_filter(
            $this->paragraphs,
            static fn (?string $p): bool => $p !== null && trim($p) !== '',
        ));
    }
}
