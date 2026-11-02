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

    public static function noText(): self
    {
        return new self('no_text', 'The Copyleaks text has no letters or digits.');
    }

    public static function writeFailed(): self
    {
        return new self('write_failed', 'The finished PDF could not be written to storage.');
    }

    public static function failed(string $reason = 'render_failed'): self
    {
        return new self($reason, 'The report PDF could not be built.');
    }
}
