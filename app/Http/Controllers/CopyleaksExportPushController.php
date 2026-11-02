<?php

namespace App\Http\Controllers;

use App\Actions\AcceptExportPush;
use App\Models\Scan;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Public export push (one result file, or the crawled text when there is no result ID). Already checked by the middleware. */
class CopyleaksExportPushController extends Controller
{
    public function __invoke(Request $request, string $scanId, string $token, string $exportId, AcceptExportPush $accept, ?string $resultId = null): Response
    {
        /** @var Scan $scan */
        $scan = $request->attributes->get('copyleaks_scan');

        $accept($scan, $exportId, $resultId, $request->getContent());

        return response('', 200);
    }
}
