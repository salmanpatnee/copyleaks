<?php

namespace App\Services\Reports\Mapping;

/** Reads a JSON number as a whole number. Anything else, including text and fractions, gives null. */
final class WholeNumber
{
    public static function from(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && is_finite($value) && $value === floor($value) && abs($value) < 9.0e15) {
            return (int) $value;
        }

        return null;
    }
}
