<?php

namespace App\Services\Reports\Manuscript;

/**
 * The whole Copyleaks text after the clean up: one entry per letter or digit, with the Copyleaks
 * offset it came from. Also kept as one string so a page probe can be found with `strpos`.
 */
final readonly class CleanedText
{
    public string $joined;

    /** @var list<int> byte position of every cleaned character inside `$joined` */
    private array $byteStarts;

    /**
     * @param  list<string>  $chars
     * @param  list<int>  $offsets  Copyleaks offset (UTF-16 unit) of every cleaned character
     */
    public function __construct(public array $chars, public array $offsets)
    {
        $this->joined = implode('', $chars);

        $starts = [];
        $position = 0;
        foreach ($chars as $character) {
            $starts[] = $position;
            $position += strlen($character);
        }
        $this->byteStarts = $starts;
    }

    public function length(): int
    {
        return count($this->chars);
    }

    /** First index in `[$from, $until)` where `$needle` (cleaned characters joined) starts, or null. */
    public function find(string $needle, int $from, int $until): ?int
    {
        $until = min($until, $this->length());
        if ($needle === '' || $from < 0 || $from >= $until) {
            return null;
        }

        $hit = strpos($this->joined, $needle, $this->byteStarts[$from]);
        if ($hit === false) {
            return null;
        }

        $index = $this->indexOfByte($hit);

        return $index !== null && $index < $until ? $index : null;
    }

    private function indexOfByte(int $byte): ?int
    {
        $low = 0;
        $high = count($this->byteStarts) - 1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($this->byteStarts[$mid] === $byte) {
                return $mid;
            }
            if ($this->byteStarts[$mid] < $byte) {
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        return null;
    }
}
