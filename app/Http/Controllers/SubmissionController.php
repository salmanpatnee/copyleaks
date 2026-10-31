<?php

namespace App\Http\Controllers;

use App\Actions\StoreSubmission;
use App\Enums\ScanStatus;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Submission;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\DuplicateScanFinder;
use App\Services\Copyleaks\ResendEligibility;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SubmissionController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('submissions/Create', [
            // A post bigger than PHP allows cannot reach validation, so the notice arrives in the URL.
            'notice' => $request->query('notice') === 'too-large'
                ? 'File is too large or did not finish uploading.'
                : null,
        ]);
    }

    public function store(StoreSubmissionRequest $request, StoreSubmission $store): RedirectResponse
    {
        /** @var array{author: string, title: string, assignment: string} $details */
        $details = $request->safe()->only(['author', 'title', 'assignment']);

        $submission = $store($request->manuscript(), $request->inspected(), $details);

        return to_route('submissions.show', $submission);
    }

    public function show(
        Submission $submission,
        CopyleaksSettings $settings,
        DuplicateScanFinder $duplicateScans,
        ResendEligibility $resend,
    ): Response {
        $scan = $submission->latestScan;

        // The Submit panel shows for a PDF with no scan yet, or whose latest scan failed (AC-1).
        $canSubmit = ! $submission->isWord() && ($scan === null || $scan->status === ScanStatus::Failed);
        $scanDuplicate = $canSubmit && ! $settings->isSandbox() ? $duplicateScans->find($submission) : null;

        $duplicates = Submission::query()
            ->where('file_sha256', $submission->file_sha256)
            ->whereKeyNot($submission->getKey())
            ->latest('id')
            ->limit(5)
            ->get(['public_id', 'original_name', 'created_at']);

        return Inertia::render('submissions/Show', [
            'submission' => [
                'public_id' => $submission->public_id,
                'original_name' => $submission->original_name,
                'file_size' => $submission->file_size,
                'page_count' => $submission->page_count,
                'author' => $submission->author,
                'title' => $submission->title,
                'assignment' => $submission->assignment,
                'created_at' => $submission->created_at?->toIso8601ZuluString(),
                'status' => [
                    'value' => $submission->status->value,
                    'label' => $submission->status->label(),
                ],
                'is_word' => $submission->isWord(),
                'duplicates' => $duplicates->map(fn (Submission $other): array => [
                    'public_id' => $other->public_id,
                    'original_name' => $other->original_name,
                    'created_at' => $other->created_at?->toIso8601ZuluString(),
                ])->all(),
            ],
            'scan' => $scan === null ? null : [
                'status' => $scan->status->value,
                'label' => $scan->status->label(),
                'failure_reason' => $scan->failure_reason,
                'ai_requested' => $scan->ai_requested,
                'submitted_at' => $scan->submitted_at?->toIso8601ZuluString(),
                'can_resend' => $resend->canResend($scan),
                'is_running' => $scan->status->isRunning(),
                'is_polling' => $scan->status->isPolling(),
            ],
            'can_submit' => $canSubmit,
            'estimated_credits' => $submission->isWord() ? null : $settings->estimateCredits($submission->page_count),
            'scan_duplicate' => $scanDuplicate === null ? null : [
                'public_id' => $scanDuplicate->public_id,
                'original_name' => $scanDuplicate->original_name,
                'created_at' => $scanDuplicate->created_at?->toIso8601ZuluString(),
            ],
        ]);
    }
}
