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

    /** @var list<ExportRequest> */
    public array $exported = [];

    /** @var list<string> */
    public array $deleted = [];

    /** @var list<ExportResult|\Throwable> */
    private array $exportScript = [];

    /** @var list<bool|\Throwable> */
    private array $deleteScript = [];

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

    public function willExport(ExportResult|\Throwable ...$outcomes): self
    {
        $this->exportScript = array_values($outcomes);

        return $this;
    }

    public function willDelete(bool|\Throwable ...$outcomes): self
    {
        $this->deleteScript = array_values($outcomes);

        return $this;
    }

    public function startExport(ExportRequest $request): ExportResult
    {
        $this->exported[] = $request;

        $next = array_shift($this->exportScript) ?? ExportResult::started();

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function deleteScan(string $scanId): bool
    {
        $this->deleted[] = $scanId;

        $next = array_shift($this->deleteScript) ?? true;

        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    /** Every call made so far. Take it before a step and compare after to prove no new call (spec 0005, AC-15). */
    public function callCount(): int
    {
        return count($this->submitted) + count($this->resent) + count($this->exported) + count($this->deleted);
    }

    public function assertNothingSent(): void
    {
        if ($this->callCount() !== 0) {
            throw new LogicException('The fake ScanClient was called.');
        }
    }
}
