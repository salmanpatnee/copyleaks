<?php

namespace App\Services\Copyleaks;

enum ResendResult: string
{
    /** 202: Copyleaks will send the webhook again. */
    case Accepted = 'accepted';
    /** 400: the scan is not ready yet. */
    case NotReady = 'not_ready';
    /** 404: Copyleaks has no record of this scan. */
    case NotFound = 'not_found';
}
