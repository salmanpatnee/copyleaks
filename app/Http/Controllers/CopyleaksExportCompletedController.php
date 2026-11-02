<?php

namespace App\Http\Controllers;

use App\Actions\AcceptExportCompletion;
use App\Models\Scan;
use App\Models\ScanEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public export completion call. Already checked by the middleware. */
class CopyleaksExportCompletedController extends Controller
{
    public function __invoke(Request $request, AcceptExportCompletion $accept): Response
    {
        /** @var Scan $scan */
        $scan = $request->attributes->get('copyleaks_scan');
        /** @var ScanEvent $claim */
        $claim = $request->attributes->get('copyleaks_export_claim');
        /** @var array<string, mixed> $body */
        $body = $request->attributes->get('copyleaks_body');

        $accept($scan, $claim, $body);

        return response('', 200);
    }
}
