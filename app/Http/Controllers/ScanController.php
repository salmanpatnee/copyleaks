<?php

namespace App\Http\Controllers;

use App\Actions\ResendScanResult;
use App\Actions\SubmitScan;
use App\Exceptions\ScanNotAllowed;
use App\Http\Requests\SubmitScanRequest;
use App\Models\Submission;
use Illuminate\Http\RedirectResponse;

/** Submit and resend. The only door that can start a Copyleaks scan (AC-13). */
class ScanController extends Controller
{
    public function store(SubmitScanRequest $request, Submission $submission, SubmitScan $submit): RedirectResponse
    {
        try {
            $submit($submission, $request->includeAi(), $request->confirmDuplicate());
        } catch (ScanNotAllowed $e) {
            return back()->withErrors(['scan' => $e->getMessage()]);
        }

        return to_route('submissions.show', $submission)
            ->with('notice', 'Sent for checking. This page updates by itself.');
    }

    public function resend(Submission $submission, ResendScanResult $resend): RedirectResponse
    {
        try {
            $notice = $resend($submission);
        } catch (ScanNotAllowed $e) {
            return back()->withErrors(['scan' => $e->getMessage()]);
        }

        return to_route('submissions.show', $submission)->with('notice', $notice);
    }
}
