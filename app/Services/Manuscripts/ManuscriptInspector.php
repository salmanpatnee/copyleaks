<?php

namespace App\Services\Manuscripts;

use App\Exceptions\ManuscriptRejected;

interface ManuscriptInspector
{
    /** Does this inspector recognise the file from its first bytes? */
    public function accepts(string $path): bool;

    /** @throws ManuscriptRejected */
    public function inspect(string $path): InspectedManuscript;
}
