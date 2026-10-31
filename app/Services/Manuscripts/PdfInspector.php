<?php

namespace App\Services\Manuscripts;

use App\Enums\ManuscriptType;
use App\Exceptions\ManuscriptRejected;
use Smalot\PdfParser\Parser;
use Throwable;

/** Checks a PDF once: a cheap page count first, then one full parse. */
class PdfInspector implements ManuscriptInspector
{
    public const MAX_PAGES = 100;

    public function __construct(private readonly Parser $parser) {}

    public function accepts(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 5);
        fclose($handle);

        return $head === '%PDF-';
    }

    public function inspect(string $path): InspectedManuscript
    {
        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            throw ManuscriptRejected::unreadable();
        }

        // Cheap check so a huge PDF is refused before the memory heavy parse.
        if ((int) preg_match_all('/\/Type\s*\/Page[^s]/', $content) > self::MAX_PAGES) {
            throw ManuscriptRejected::tooManyPages(self::MAX_PAGES);
        }

        try {
            $pages = count($this->parser->parseContent($content)->getPages());
        } catch (Throwable $e) {
            throw str_contains($e->getMessage(), 'Secured')
                ? ManuscriptRejected::encrypted()
                : ManuscriptRejected::unreadable();
        }

        if ($pages === 0) {
            throw ManuscriptRejected::unreadable();
        }

        if ($pages > self::MAX_PAGES) {
            throw ManuscriptRejected::tooManyPages(self::MAX_PAGES);
        }

        return new InspectedManuscript(ManuscriptType::Pdf, $pages);
    }
}
