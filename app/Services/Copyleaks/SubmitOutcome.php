<?php

namespace App\Services\Copyleaks;

enum SubmitOutcome: string
{
    /** 201: Copyleaks took the file. */
    case Created = 'created';

    /** 409: Copyleaks already has this scan ID, so an earlier try got through. */
    case Conflict = 'conflict';

    /** A clear 4xx refusal (no credits, rate limit, bad request). */
    case Refused = 'refused';
}
