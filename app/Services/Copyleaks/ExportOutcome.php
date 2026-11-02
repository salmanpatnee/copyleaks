<?php

namespace App\Services\Copyleaks;

enum ExportOutcome: string
{
    /** 204: Copyleaks started the export. */
    case Started = 'started';

    /** 409: this export ID is already known, so an earlier call got through. Treated as started. */
    case Conflict = 'conflict';

    /** 404: Copyleaks no longer has the scan. */
    case NotFound = 'not_found';

    /** Any other clear 4xx refusal. */
    case Refused = 'refused';

    public function wasAccepted(): bool
    {
        return $this === self::Started || $this === self::Conflict;
    }
}
