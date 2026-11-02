<?php

namespace App\Enums;

/** Why a report uses text pages instead of the original pages. There is no case for "none": the reason is null then. */
enum ManuscriptFallbackReason: string
{
    case LowMatch = 'low_match';
    case ImportFailed = 'import_failed';
    case RotatedPage = 'rotated_page';
}
