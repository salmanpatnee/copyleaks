<?php

namespace App\Services\Copyleaks;

use RuntimeException;

/** Copyleaks said no to our email or API key. Retrying cannot help. The message never holds the key. */
class CopyleaksLoginRefused extends RuntimeException {}
