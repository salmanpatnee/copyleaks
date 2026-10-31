<?php

namespace App\Services\Manuscripts;

use App\Exceptions\ManuscriptRejected;

/** Hands the file to the first inspector that recognises it. */
class ManuscriptInspectorChain implements ManuscriptInspector
{
    /** @param  list<ManuscriptInspector>  $inspectors */
    public function __construct(private readonly array $inspectors) {}

    public function accepts(string $path): bool
    {
        foreach ($this->inspectors as $inspector) {
            if ($inspector->accepts($path)) {
                return true;
            }
        }

        return false;
    }

    public function inspect(string $path): InspectedManuscript
    {
        foreach ($this->inspectors as $inspector) {
            if ($inspector->accepts($path)) {
                return $inspector->inspect($path);
            }
        }

        throw ManuscriptRejected::unsupportedType();
    }
}
