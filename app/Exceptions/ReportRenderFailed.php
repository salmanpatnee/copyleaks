<?php

namespace App\Exceptions;

use RuntimeException;

/** A report PDF could not be built. The reason is a short code and never holds manuscript text. */
class ReportRenderFailed extends RuntimeException
{
    private function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function overflow(): self
    {
        return new self('overflow', 'A report section wrote more pages than it promised.');
    }

    public static function unreadablePdf(): self
    {
        return new self('unreadable_pdf', 'The manuscript PDF could not be read.');
    }

    public static function encryptedPdf(): self
    {
        return new self('encrypted_pdf', 'The manuscript PDF is password protected.');
    }

    public static function unsupportedPdf(): self
    {
        return new self('unsupported_pdf', 'The manuscript PDF uses a format the report engine cannot import (compressed cross references).');
    }

    public static function wordNotSupported(): self
    {
        return new self('word_not_supported', 'Word manuscripts cannot be turned into report pages yet. Save the file as PDF.');
    }

    public static function crawledTextMissing(): self
    {
        return new self('crawled_text_missing', 'The saved Copyleaks text is missing or unreadable.');
    }

    public static function scanNotCompleted(): self
    {
        return new self('scan_not_completed', 'Only a completed scan can be turned into report data.');
    }

    public static function summaryMissing(): self
    {
        return new self('summary_missing', 'The saved Copyleaks summary is missing.');
    }

    public static function resultMissing(): self
    {
        return new self('result_missing', 'A saved Copyleaks result file is missing.');
    }

    public static function checksumMismatch(): self
    {
        return new self('checksum_mismatch', 'A saved Copyleaks file does not match its checksum.');
    }

    public static function summaryUnreadable(): self
    {
        return new self('summary_unreadable', 'The saved Copyleaks summary is damaged or lacks a value the report needs.');
    }

    public static function resultUnreadable(): self
    {
        return new self('result_unreadable', 'A saved Copyleaks result file is damaged.');
    }

    public static function noText(): self
    {
        return new self('no_text', 'The Copyleaks text has no letters or digits.');
    }

    public static function writeFailed(): self
    {
        return new self('write_failed', 'The finished PDF could not be written to storage.');
    }

    public static function submissionIdMissing(): self
    {
        return new self('submission_id_missing', 'The scan has no submission ID, so the report strip cannot be drawn.');
    }

    public static function failed(string $reason = 'render_failed'): self
    {
        return new self($reason, 'The report PDF could not be built.');
    }
}
