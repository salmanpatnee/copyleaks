<?php

namespace App\Services;

/** The facts about a PDF the engine has already written to disk. */
final readonly class BuiltReport
{
    public function __construct(
        public string $path,
        public string $sha256,
        public int $sizeBytes,
        public int $pageCount,
        public string $rendererVersion,
    ) {}
}
