<?php

namespace App\Http\Controllers;

use App\Actions\StoreSubmission;
use App\Http\Requests\StoreSubmissionRequest;
use App\Models\Submission;
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

    public function show(Submission $submission): Response
    {
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
        ]);
    }
}
