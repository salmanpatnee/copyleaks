<?php

namespace App\Services\Reports;

use App\Exceptions\ReportRenderFailed;
use App\Services\BuiltReport;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Mpdf\Container\SimpleContainer;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Throwable;

/**
 * The one real renderer. Runs the sections in order on one mPDF document, writes the file to
 * `.{name}.tmp` next to the target and moves it into place only when the build is complete.
 */
class MpdfReportRenderer implements ReportRenderer
{
    /** Bump when the look changes. Stored with each report. */
    public const VERSION = '4';

    private const DISK = 'local';

    private const STALE_TEMP_SECONDS = 3600;

    public function __construct(
        private readonly FilesystemFactory $files,
        private readonly ReportFonts $fonts,
        private readonly ReportAssets $assets,
    ) {}

    public function render(ReportDocument $document, string $targetPath): BuiltReport
    {
        $target = $this->files->disk(self::DISK)->path($targetPath);
        $folder = dirname($target);
        if (! is_dir($folder) && ! @mkdir($folder, 0775, true) && ! is_dir($folder)) {
            throw ReportRenderFailed::writeFailed();
        }

        $this->deleteStaleTemps($folder);
        $temp = $folder.DIRECTORY_SEPARATOR.'.'.basename($target).'.tmp';

        try {
            $pages = $this->build($document, $temp);

            if (! @rename($temp, $target)) {
                throw ReportRenderFailed::writeFailed();
            }

            return new BuiltReport(
                path: $targetPath,
                sha256: (string) hash_file('sha256', $target),
                sizeBytes: (int) filesize($target),
                pageCount: $pages,
                rendererVersion: self::VERSION,
            );
        } catch (ReportRenderFailed $e) {
            @unlink($temp);
            throw $e;
        } catch (Throwable) {
            // The message may hold manuscript text, so it is dropped on purpose.
            @unlink($temp);
            throw ReportRenderFailed::failed();
        }
    }

    private function build(ReportDocument $document, string $temp): int
    {
        $tempDir = (string) config('report.temp_dir');
        if (! is_dir($tempDir) && ! @mkdir($tempDir, 0775, true) && ! is_dir($tempDir)) {
            throw ReportRenderFailed::writeFailed();
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => [Pt::toMm(ReportTheme::PAGE_W), Pt::toMm(ReportTheme::PAGE_H)],
            'margin_left' => 0, 'margin_right' => 0, 'margin_top' => 0, 'margin_bottom' => 0,
            'margin_header' => 0, 'margin_footer' => 0,
            'tempDir' => $tempDir,
            'autoPageBreak' => false,
            'shrink_tables_to_fit' => 0,
            'showImageErrors' => true,
            ...$this->fonts->config(),
        ], new SimpleContainer(['httpClient' => new NullHttpClient]));

        $mpdf->basepathIsLocal = true;
        $mpdf->SetTitle($document->kind->title());
        $mpdf->SetAuthor((string) config('app.name'));
        $mpdf->SetCreator((string) config('app.name'));
        $mpdf->WriteHTML(ReportTheme::css(), HTMLParserMode::HEADER_CSS);

        $total = $document->totalPages();
        $next = 1;
        foreach ($document->sections as $section) {
            $canvas = new SectionCanvas($mpdf, $this->assets, $section->label(), $document->headerId, $total, $next, $section->pageCount());
            $section->write($canvas);
            $canvas->finish();

            if ($canvas->pagesWritten() !== $section->pageCount()) {
                throw ReportRenderFailed::failed('page_count_mismatch');
            }
            $next += $canvas->pagesWritten();
        }

        $mpdf->Output($temp, Destination::FILE);

        return $total;
    }

    /** A fatal error (out of memory, time limit) can leave a temp file, so old ones are cleared on every render. */
    private function deleteStaleTemps(string $folder): void
    {
        foreach (glob($folder.DIRECTORY_SEPARATOR.'.*.tmp') ?: [] as $file) {
            if (is_file($file) && time() - (int) filemtime($file) > self::STALE_TEMP_SECONDS) {
                @unlink($file);
            }
        }
    }
}
