<?php

namespace App\Enums;

enum EventKind: string
{
    case Status = 'status';
    case ExportStarted = 'export_started';
    case ExportCompleted = 'export_completed';
}
