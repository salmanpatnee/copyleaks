<?php

namespace App\Services\Manuscripts;

use App\Exceptions\SubmissionNumberUnavailable;
use App\Models\Submission;

/** Picks a random 9 digit number that no submission uses yet (spec 0012, AC-2). Never writes. */
class SubmissionNumberGenerator
{
    private const MIN = 100_000_000;

    private const MAX = 999_999_999;

    private const TRIES = 5;

    /** @throws SubmissionNumberUnavailable when every try hit a number that is already used */
    public function next(): int
    {
        for ($try = 0; $try < self::TRIES; $try++) {
            $number = random_int(self::MIN, self::MAX);

            if (! Submission::query()->where('submission_number', $number)->exists()) {
                return $number;
            }
        }

        throw new SubmissionNumberUnavailable;
    }
}
