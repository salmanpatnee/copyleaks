<?php

namespace App\Enums;

enum EventKind: string
{
    case Status = 'status';
    case ExportCompleted = 'export_completed';
}
