<?php

namespace App\Services\Copyleaks;

use RuntimeException;

/** Copyleaks could not be reached or answered with a server error. Safe to try again with the same scan ID. */
class ScanClientUnavailable extends RuntimeException {}
