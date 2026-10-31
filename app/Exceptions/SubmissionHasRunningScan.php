<?php

namespace App\Exceptions;

use RuntimeException;

class SubmissionHasRunningScan extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This submission has a scan that is still running, so it cannot be deleted yet.');
    }
}
