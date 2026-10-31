<?php

namespace App\Services\Manuscripts;

use App\Enums\ManuscriptType;
use App\Exceptions\ManuscriptRejected;
use ZipArchive;

/** Reads only the zip file list. Nothing is ever unpacked. */
class DocxInspector implements ManuscriptInspector
{
    public function accepts(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 2);
        fclose($handle);

        return $head === 'PK';
    }

    public function inspect(string $path): InspectedManuscript
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw ManuscriptRejected::unsupportedType();
        }

        $isWord = $zip->locateName('[Content_Types].xml') !== false
            && $zip->locateName('word/document.xml') !== false;

        $zip->close();

        if (! $isWord) {
            throw ManuscriptRejected::unsupportedType();
        }

        return new InspectedManuscript(ManuscriptType::Docx, null);
    }
}
