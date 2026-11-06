<?php

namespace App\Services\Reports\Sample;

use App\Enums\MatchGroupStyle;
use App\Enums\ReportKind;
use App\Services\Reports\Cover\CoverSection;
use App\Services\Reports\ImportedPdfSection;
use App\Services\Reports\Overview\OverviewSection;
use App\Services\Reports\Parts\DocumentDetails;
use App\Services\Reports\Parts\Highlight;
use App\Services\Reports\Parts\MarginBadge;
use App\Services\Reports\Parts\MatchGroupRow;
use App\Services\Reports\Parts\SourceRow;
use App\Services\Reports\Parts\SourceTotals;
use App\Services\Reports\ReportAssets;
use App\Services\Reports\ReportDocument;

/** All the made up names and numbers of the sample. Fixed data, no database, no Copyleaks. */
final class SampleReportFactory
{
    public function __construct(
        private readonly ReportAssets $assets,
        private readonly SampleManuscriptWriter $writer,
    ) {}

    /**
     * @param  list<string>  $imports  absolute paths of real PDFs to import instead of the made up pages
     */
    public function make(int $pages, int $sources, array $imports = []): SampleReport
    {
        $temp = [];
        $manuscripts = [];

        if ($imports === []) {
            $path = (string) config('report.temp_dir').DIRECTORY_SEPARATOR.'sample-manuscript-'.bin2hex(random_bytes(4)).'.pdf';
            $this->writer->write($path, max(1, $pages));
            $temp[] = $path;
            $manuscripts[] = ImportedPdfSection::forFile($path, 'Integrity Submission', $this->highlights(max(1, $pages)), $this->badges(max(1, $pages)));
        }

        foreach ($imports as $path) {
            $manuscripts[] = ImportedPdfSection::forFile($path, 'Integrity Submission', [1 => $this->highlights(1)[1]], [1 => $this->badges(1)[1]]);
        }

        $document = new ReportDocument(ReportKind::Similarity, 'trn:oid:::0000:000000001', [
            new CoverSection($this->details($pages), $this->assets),
            new OverviewSection(
                18.0,
                $rows = $this->sources($sources),
                $this->assets,
                $this->groups(),
                $this->totals($rows),
                ['Bibliography', 'Quoted Text', 'Small Matches (less than 8 words)'],
                0,
            ),
            ...$manuscripts,
        ]);

        return new SampleReport($document, $temp);
    }

    private function details(int $pages): DocumentDetails
    {
        return new DocumentDetails(
            author: 'Author 32',
            title: 'Sample manuscript',
            assignment: 'Sample class <b>one</b>',
            submissionId: 'trn:oid:::0000:000000001',
            submissionDate: 'Oct 28, 2026, 4:12 PM GMT+5',
            downloadDate: 'Nov 2, 2026, 1:05 PM GMT+5',
            fileName: 'sample-manuscript.pdf',
            fileSize: '113.9 KB',
            pages: $pages,
            words: 5523,
            characters: 33999,
        );
    }

    /** @return list<MatchGroupRow> includes a group with a 0 count */
    private function groups(): array
    {
        return [
            new MatchGroupRow(MatchGroupStyle::NotCitedOrQuoted, 56, 11.0),
            new MatchGroupRow(MatchGroupStyle::MissingQuotations, 12, 4.0),
            new MatchGroupRow(MatchGroupStyle::MissingCitation, 0, 0.0),
            new MatchGroupRow(MatchGroupStyle::CitedAndQuoted, 3, 0.4),
        ];
    }

    /** @return list<SourceRow> one `<1%` source, one very long name, one name with markup */
    private function sources(int $count): array
    {
        $categories = ['Student papers', 'Internet', 'Publication'];
        $rows = [];
        for ($rank = 1; $rank <= $count; $rank++) {
            $category = $categories[($rank - 1) % 3];
            $name = match ($rank) {
                2 => 'A very long source name that goes on and on to prove it is cut with an ellipsis before the percentage',
                3 => 'Smith <b>x</b> & Co "Quoted title" <img src="http://example.com/a.png">',
                default => $category === 'Internet' ? "www.example-{$rank}.org" : "Sample University on 2026-10-{$this->day($rank)}",
            };
            $rows[] = new SourceRow($rank, $category, $name, $rank === 4 ? 0.4 : max(1.0, 12.0 - $rank * 0.4));
        }

        return $rows;
    }

    /** @param  list<SourceRow>  $rows  the made up totals: a plain sum per sample category */
    private function totals(array $rows): SourceTotals
    {
        $sum = static function (string $category) use ($rows): float {
            $total = 0.0;
            foreach ($rows as $row) {
                if ($row->category === $category) {
                    $total += $row->percent;
                }
            }

            return $total;
        };

        return new SourceTotals($sum('Internet'), $sum('Publication'), $sum('Student papers'));
    }

    private function day(int $rank): string
    {
        return str_pad((string) (($rank % 28) + 1), 2, '0', STR_PAD_LEFT);
    }

    /** @return array<int, list<Highlight>> fixed points, from the top left of each page */
    private function highlights(int $pages): array
    {
        $out = [];
        $groups = [MatchGroupStyle::NotCitedOrQuoted, MatchGroupStyle::MissingQuotations, MatchGroupStyle::MissingCitation, MatchGroupStyle::CitedAndQuoted];
        for ($page = 1; $page <= $pages; $page++) {
            $out[$page] = [new Highlight(72, $this->lineTop(3), 180, 11, $groups[($page - 1) % 4])];
        }

        return $out;
    }

    /** @return array<int, list<MarginBadge>> */
    private function badges(int $pages): array
    {
        $out = [];
        $groups = [MatchGroupStyle::NotCitedOrQuoted, MatchGroupStyle::MissingQuotations, MatchGroupStyle::MissingCitation, MatchGroupStyle::CitedAndQuoted];
        for ($page = 1; $page <= $pages; $page++) {
            $out[$page] = [new MarginBadge(7.5, $this->lineTop(3), $groups[($page - 1) % 4], $page)];
        }

        return $out;
    }

    private function lineTop(int $line): float
    {
        return SampleManuscriptWriter::FIRST_BASELINE + SampleManuscriptWriter::LINE_HEIGHT * $line - 9.5;
    }
}
