<?php

namespace App\Exceptions;

use RuntimeException;

class SubmissionNumberUnavailable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A free submission number could not be found, so the upload could not be saved.');
    }
}
