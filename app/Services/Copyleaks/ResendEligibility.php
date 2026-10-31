<?php

namespace App\Services\Copyleaks;

use App\Enums\ScanStatus;
use App\Models\Scan;
use Illuminate\Support\Facades\RateLimiter;

/**
 * One place that works out whether "Resend result" is allowed, for the saved page
 * and for the action (AC-12).
 *
 * Due: a `waiting` scan that has waited long enough, or a `submitting` scan whose
 * submit job deadline has passed (so an old job can no longer send behind our back).
 * Limited: one call per 10 minutes per scan.
 */
class ResendEligibility
{
    public const LIMIT_SECONDS = 600;

    public function __construct(private readonly CopyleaksSettings $settings) {}

    public function isDue(Scan $scan): bool
    {
        return match ($scan->status) {
            ScanStatus::Waiting => ($scan->submitted_at ?? $scan->created_at)
                ?->addMinutes($this->settings->resendWaitMinutes())
                ->isPast() ?? false,
            ScanStatus::Submitting => $scan->created_at
                ?->addMinutes($this->settings->deadlineMinutes())
                ->isPast() ?? false,
            default => false,
        };
    }

    public function isLimited(Scan $scan): bool
    {
        return RateLimiter::tooManyAttempts($this->key($scan), 1);
    }

    public function canResend(Scan $scan): bool
    {
        return $this->isDue($scan) && ! $this->isLimited($scan);
    }

    /** Uses up the once per 10 minutes allowance. Call only when Copyleaks is really called. */
    public function markUsed(Scan $scan): void
    {
        RateLimiter::hit($this->key($scan), self::LIMIT_SECONDS);
    }

    /** Gives the allowance back, for when the call never reached Copyleaks. */
    public function release(Scan $scan): void
    {
        RateLimiter::clear($this->key($scan));
    }

    private function key(Scan $scan): string
    {
        return 'scan-resend:'.$scan->id;
    }
}
