<?php

namespace App\Exceptions;

use RuntimeException;

/** A file the upload screen will not accept. The message is safe to show to the person. */
class ManuscriptRejected extends RuntimeException
{
    public static function unsupportedType(): self
    {
        return new self('Only PDF (.pdf) and Word (.docx) files are allowed.');
    }

    public static function encrypted(): self
    {
        return new self('This PDF is password protected. Remove the password and upload it again.');
    }

    public static function unreadable(): self
    {
        return new self('This PDF could not be read. It may be damaged or empty.');
    }

    public static function tooManyPages(int $limit): self
    {
        return new self("This PDF has more than {$limit} pages, which is the limit.");
    }
}
