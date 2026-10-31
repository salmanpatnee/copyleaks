<?php

namespace App\Exceptions;

use RuntimeException;

/** Submit or resend was refused. The message is safe to show to staff. */
class ScanNotAllowed extends RuntimeException {}
