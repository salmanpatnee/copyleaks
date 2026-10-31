<?php

namespace App\Actions;

use App\Enums\ScanStatus;
use App\Exceptions\ScanNotAllowed;
use App\Jobs\SubmitScanJob;
use App\Models\Scan;
use App\Models\Submission;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\DuplicateScanFinder;
use App\Services\Copyleaks\WebhookToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only code that creates a scan and queues a submit job (AC-2, AC-13).
 *
 * The lock, the running scan check, the duplicate check, the scan insert and the
 * job dispatch all happen in one transaction. The job row (same database queue)
 * commits or rolls back together with the scan.
 */
class SubmitScan
{
    public function __construct(
        private readonly CopyleaksSettings $settings,
        private readonly DuplicateScanFinder $duplicates,
        private readonly WebhookToken $tokens,
    ) {}

    /** @throws ScanNotAllowed */
    public function __invoke(Submission $submission, bool $includeAi, bool $confirmDuplicate): Scan
    {
        if (! $this->settings->isReady()) {
            throw new ScanNotAllowed('Copyleaks is not set up. Ask the developer to add the Copyleaks settings.');
        }

        return DB::transaction(function () use ($submission, $includeAi, $confirmDuplicate): Scan {
            // Two presses at once wait here, so the second one sees the first one's scan.
            $locked = Submission::query()->whereKey($submission->getKey())->lockForUpdate()->firstOrFail();

            $this->guard($locked, $confirmDuplicate);

            $scanId = Str::lower((string) Str::ulid());

            $scan = new Scan([
                'submission_id' => $locked->getKey(),
                'copyleaks_scan_id' => $scanId,
                'webhook_token_hash' => $this->tokens->hashFor($scanId),
                'ai_requested' => $includeAi,
                'is_sandbox' => $this->settings->isSandbox(),
            ]);
            $scan->save();

            SubmitScanJob::dispatch($scan->getKey());

            return $scan;
        });
    }

    /** @throws ScanNotAllowed */
    private function guard(Submission $submission, bool $confirmDuplicate): void
    {
        if ($submission->isWord()) {
            throw new ScanNotAllowed('Word files cannot be checked yet. Upload a PDF instead.');
        }

        $latest = $submission->scans()->latest('id')->first();

        if ($latest?->status->isRunning()) {
            throw new ScanNotAllowed('A check is already running for this file.');
        }

        if ($latest?->status === ScanStatus::Completed) {
            throw new ScanNotAllowed('This file has already been checked.');
        }

        if (! $this->settings->isSandbox() && ! $confirmDuplicate && $this->duplicates->find($submission) !== null) {
            throw new ScanNotAllowed('This file was already checked on another submission. Tick the box to check it again.');
        }
    }
}
