<?php

namespace App\Services\Copyleaks;

use LogicException;

/**
 * The ScanClient used in every test. It records each call and plays a scripted
 * list of outcomes, one per call. With no script left it answers "created".
 *
 * Script entries: SubmitResult / ResendResult objects, or a Throwable to throw
 * (for example a ScanClientUnavailable for a timeout or a 5xx).
 */
class FakeScanClient implements ScanClient
{
    /** @var list<SubmitRequest> */
    public array $submitted = [];

    /** @var list<string> */
    public array $resent = [];

    /** @var list<SubmitResult|\Throwable> */
    private array $submitScript = [];

    /** @var list<ResendResult|\Throwable> */
    private array $resendScript = [];

    public function willSubmit(SubmitResult|\Throwable ...$outcomes): self
    {
        $this->submitScript = array_values($outcomes);

        return $this;
    }

    public function willResend(ResendResult|\Throwable ...$outcomes): self
    {
        $this->resendScript = array_values($outcomes);

        return $this;
    }

    public function submit(SubmitRequest $request): SubmitResult
    {
        $this->submitted[] = $request;

        $next = array_shift($this->submitScript) ?? SubmitResult::created();

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function resendWebhook(string $scanId): ResendResult
    {
        $this->resent[] = $scanId;

        $next = array_shift($this->resendScript) ?? ResendResult::Accepted;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function assertNothingSent(): void
    {
        if ($this->submitted !== [] || $this->resent !== []) {
            throw new LogicException('The fake ScanClient was called.');
        }
    }
}
