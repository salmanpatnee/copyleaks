<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;

/** Paths of the logo and the icons. Only local files from resources/report/ are ever used. */
final class ReportAssets
{
    public function logoPath(): string
    {
        return $this->existing((string) config('report.logo'));
    }

    public function iconPath(string $name): string
    {
        return $this->existing(rtrim((string) config('report.icons_dir'), '/\\').DIRECTORY_SEPARATOR.basename($name));
    }

    private function existing(string $path): string
    {
        if (! is_file($path)) {
            throw ReportRenderFailed::failed('missing_asset');
        }

        return $path;
    }
}
