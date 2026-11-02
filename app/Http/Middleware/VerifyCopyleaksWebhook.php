<?php

namespace App\Http\Middleware;

use App\Services\Copyleaks\CallerVerifier;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every check for the public webhook (AC-8). Any failure answers the same empty
 * 404 and saves nothing, so a caller learns nothing about which check failed.
 *
 * Checks: status word, scan exists, token, secret header, body size (413),
 * valid JSON, and `developerPayload` equal to the scan ID. Only the scan ID is logged.
 */
class VerifyCopyleaksWebhook
{
    public const STATUSES = ['completed', 'error'];

    public function __construct(private readonly CallerVerifier $caller) {}

    public function handle(Request $request, Closure $next): Response
    {
        $scanId = (string) $request->route('scanId');
        $status = (string) $request->route('status');

        if (! in_array($status, self::STATUSES, true)) {
            return $this->notFound();
        }

        $scan = $this->caller->scanFor($request);

        if ($scan === null) {
            return $this->notFound();
        }

        if ((int) $request->header('Content-Length', '0') > (int) config('copyleaks.max_webhook_bytes')) {
            return response('', 413);
        }

        $raw = $request->getContent();
        $body = json_decode($raw, true);

        if (! is_array($body) || ($body['developerPayload'] ?? null) !== $scanId) {
            return $this->notFound();
        }

        $request->attributes->set('copyleaks_scan', $scan);
        $request->attributes->set('copyleaks_body', $body);

        return $next($request);
    }

    private function notFound(): Response
    {
        return response('', 404);
    }
}
