<?php

namespace App\Http\Middleware;

use App\Enums\ScanStatus;
use App\Models\Scan;
use App\Services\Copyleaks\CallerVerifier;
use App\Services\Copyleaks\ExportAttempts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every check for the three public export addresses (spec 0005, AC-2, AC-5, AC-6).
 *
 * Used as `VerifyCopyleaksExportPush:result`, `:text` or `:completed`. Order: token and
 * secret (the secret is not needed for `:completed`), a known export ID of this scan, the result ID is expected (result only), the
 * size by header and by real length (413), valid JSON, and last a scan that is no longer
 * `fetching` gets 200 with nothing saved. Any other failure is the same empty 404.
 *
 * A pushed body is checked with `json_validate()` and never decoded, because it can be large.
 */
class VerifyCopyleaksExportPush
{
    private const RESULT_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    public function __construct(
        private readonly CallerVerifier $caller,
        private readonly ExportAttempts $attempts,
    ) {}

    public function handle(Request $request, Closure $next, string $part): Response
    {
        // Copyleaks cannot add headers to the completion call, so only that address skips the secret.
        $scan = $this->caller->scanFor($request, requireSecret: $part !== 'completed');

        if ($scan === null) {
            return $this->notFound();
        }

        $claim = $this->attempts->event($scan, (string) $request->route('exportId'));

        if ($claim === null) {
            return $this->notFound();
        }

        $resultId = $request->route('resultId');

        if ($part === 'result' && (! is_string($resultId) || preg_match(self::RESULT_ID_PATTERN, $resultId) !== 1
            || ! in_array($resultId, $this->attempts->expectedResultIds($claim), true))) {
            return $this->notFound();
        }

        $max = (int) config('copyleaks.max_export_bytes');

        if ((int) $request->header('Content-Length', '0') > $max) {
            return response('', 413);
        }

        $raw = $request->getContent();

        // A chunked push may send no Content-Length header, so the real length counts too.
        if (strlen($raw) > $max) {
            return response('', 413);
        }

        if ($part === 'completed') {
            $body = json_decode($raw, true);

            if (! is_array($body) || ($body['developerPayload'] ?? null) !== $scan->copyleaks_scan_id) {
                return $this->notFound();
            }

            $request->attributes->set('copyleaks_body', $body);
        } elseif (! json_validate($raw)) {
            return $this->notFound();
        }

        if ($scan->status !== ScanStatus::Fetching) {
            return response('', 200);
        }

        $request->attributes->set('copyleaks_scan', $scan);
        $request->attributes->set('copyleaks_export_claim', $claim);

        return $next($request);
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
