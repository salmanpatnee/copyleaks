<?php

namespace App\Exceptions;

use RuntimeException;

class ManuscriptStorageFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The manuscript file could not be written to storage.');
    }
}
