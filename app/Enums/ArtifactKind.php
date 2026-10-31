<?php

namespace App\Enums;

enum ArtifactKind: string
{
    case Summary = 'summary';
    case Result = 'result';
    case Text = 'text';
}
