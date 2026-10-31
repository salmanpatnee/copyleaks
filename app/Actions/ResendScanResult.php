<?php

namespace App\Actions;

use App\Exceptions\ScanNotAllowed;
use App\Models\Submission;
use App\Services\Copyleaks\CopyleaksSettings;
use App\Services\Copyleaks\ResendEligibility;
use App\Services\Copyleaks\ResendResult;
use App\Services\Copyleaks\ScanClient;
use App\Services\Copyleaks\ScanClientUnavailable;
use App\Services\ScanTransitioner;

/**
 * Asks Copyleaks to send a missed result again (AC-12). Never creates a scan.
 * Works once per 10 minutes per scan.
 */
class ResendScanResult
{
    public const NOT_FOUND_REASON = 'Copyleaks has no record of this scan (never received or already expired).';

    public function __construct(
        private readonly ScanClient $client,
        private readonly ResendEligibility $eligibility,
        private readonly ScanTransitioner $transitioner,
        private readonly CopyleaksSettings $settings,
    ) {}

    /**
     * @return string a short notice for staff
     *
     * @throws ScanNotAllowed
     */
    public function __invoke(Submission $submission): string
    {
        $scan = $submission->scans()->latest('id')->first();

        if ($scan === null || ! $this->eligibility->isDue($scan)) {
            throw new ScanNotAllowed('It is too early to ask Copyleaks to send the result again.');
        }

        if ($this->eligibility->isLimited($scan)) {
            throw new ScanNotAllowed('We already asked Copyleaks a moment ago. Please wait about 10 minutes before asking again.');
        }

        if (! $this->settings->isReady()) {
            throw new ScanNotAllowed('Copyleaks is not set up. Ask the developer to add the Copyleaks settings.');
        }

        $this->eligibility->markUsed($scan);

        try {
            $result = $this->client->resendWebhook($scan->copyleaks_scan_id);
        } catch (ScanClientUnavailable) {
            // The call never got an answer, so give the allowance back.
            $this->eligibility->release($scan);

            throw new ScanNotAllowed('Could not reach Copyleaks. Please try again in a moment.');
        }

        if ($result === ResendResult::NotFound) {
            $this->transitioner->markFailed($scan, self::NOT_FOUND_REASON);
        }

        return match ($result) {
            ResendResult::Accepted => 'We asked Copyleaks to send the result again.',
            ResendResult::NotReady => 'Copyleaks says the result is not ready yet.',
            ResendResult::NotFound => 'Copyleaks has no record of this scan. You can submit it again.',
        };
    }
}
