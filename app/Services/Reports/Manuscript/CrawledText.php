<?php

namespace App\Services\Reports\Manuscript;

/**
 * The Copyleaks text, as UTF-16 code units so an index is a Copyleaks offset. Copyleaks page starts
 * are not kept: they are about 5000 character chunks, not PDF pages (AC-13).
 */
final readonly class CrawledText
{
    /** @param  list<int>  $units  UTF-16 code units of `text.value` */
    public function __construct(
        public string $value,
        public array $units,
    ) {}

    public static function make(string $value): self
    {
        $packed = unpack('v*', mb_convert_encoding($value, 'UTF-16LE', 'UTF-8'));

        return new self($value, $packed === false ? [] : array_values($packed));
    }

    public function length(): int
    {
        return count($this->units);
    }

    public function hasLettersOrDigits(): bool
    {
        return preg_match('/[\p{L}\p{N}]/u', $this->value) === 1;
    }
}
