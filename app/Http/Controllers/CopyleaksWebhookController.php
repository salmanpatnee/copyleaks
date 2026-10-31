<?php

namespace App\Http\Controllers;

use App\Actions\AcceptWebhook;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public webhook. VerifyCopyleaksWebhook has already checked the call. Calls nothing at Copyleaks. */
class CopyleaksWebhookController extends Controller
{
    public function __invoke(Request $request, string $scanId, string $token, string $status, AcceptWebhook $accept): Response
    {
        /** @var Scan $scan */
        $scan = $request->attributes->get('copyleaks_scan');
        /** @var array<string, mixed> $body */
        $body = $request->attributes->get('copyleaks_body');

        $accept($scan, $status, $request->getContent(), $body);

        return response('', 200);
    }
}
