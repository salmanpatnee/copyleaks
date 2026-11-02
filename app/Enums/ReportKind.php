<?php

namespace App\Enums;

enum ReportKind: string
{
    case Similarity = 'similarity';
    case Ai = 'ai';

    /** The PDF title. */
    public function title(): string
    {
        return match ($this) {
            self::Similarity => 'Similarity Report',
            self::Ai => 'AI Writing Report',
        };
    }
}
