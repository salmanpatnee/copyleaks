# Report PDF engine

## Overview

Turns typed report data into one PDF with mPDF (pure PHP, so it runs on Hostinger). A report is a list of sections (cover, overview, manuscript pages). Each section draws its own pages, and the engine adds the shared header and footer. It never calls Copyleaks and never reads the database. Decided in spec 0006 (`docs/specs/0006-report-pdf-engine/index.md`). The design numbers come from `docs/turnitin-report-design.md`. Read both before changing a rule.

## Key files

| File                                                                                 | Owns                                                                                                                                                                                  |
| ------------------------------------------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `ReportRenderer.php`                                                                 | The interface callers use. `render(ReportDocument, targetPath): BuiltReport`. The path is relative to the `local` disk. Callers never touch mPDF                                      |
| `MpdfReportRenderer.php`                                                             | The one real renderer. Writes `.{name}.tmp` next to the target, then moves it into place. Deletes stale temp files older than 1 hour. Holds `VERSION` (bump it when the look changes) |
| `ReportSection.php`, `ReportDocument.php`                                            | A section has `label()`, `pageCount()` (known before drawing) and `write(SectionCanvas)`. Total pages `N` is the sum of every `pageCount()`                                           |
| `SectionCanvas.php`                                                                  | The only class that talks to mPDF. Points in, millimetres out (`Pt::toMm`). Draws the header and footer strip last on every page, including imported ones                             |
| `ReportParts.php` + `resources/views/reports/parts/`                                 | Drawing parts. PHP works out the numbers, Blade holds the structure                                                                                                                   |
| `ReportTheme.php`                                                                    | Every color, size and spacing value, and the CSS block. Views never hold a hex color                                                                                                  |
| `ReportFonts.php`, `ReportAssets.php`                                                | Font setup (static TTF only, R and B per family) and logo and icon paths. Files live in `resources/report/`                                                                           |
| `ImportedPdfSection.php`, `PdfImportErrors.php`                                      | Imports each page of a stored PDF at its own size and draws highlights and badges on it. Import errors become `ReportRenderFailed` with a plain reason                                |
| `SourceRowPager.php`, `PercentFormat.php`, `SourceNameFitter.php`, `SourceColor.php` | Pure helpers: 10 or 14 rows per page, `<1%`, names cut with "…", the 4 color cycle by rank                                                                                            |
| `Parts/`                                                                             | Readonly data objects the engine takes. A null field leaves its spot out cleanly (a null `SourceRow::$category` skips only the pill)                                                  |
| `Sample/`                                                                            | Throwaway sample pages and fake data for `php artisan report:sample`. Real cover, overview and highlights come in features 11, 12 and 13                                              |
| `NullHttpClient.php`                                                                 | Makes mPDF refuse every web request                                                                                                                                                   |

Also: `app/Enums/MatchGroupStyle.php` (group color, icon, icon size) and `config/report.php`.

## Conventions and rules

- Sections never touch mPDF. They use `SectionCanvas`.
- All text is escaped (Blade `{{ }}`, plain text calls). Images are only local files from `resources/report/`.
- Any caught failure throws `ReportRenderFailed` with a short reason code that holds no manuscript text. Nothing else is logged. No finished file or temp file is left behind.
- A section that writes more pages than its `pageCount()` fails with `overflow`. mPDF's own auto page break is off.
- Draw order on an imported page is fixed: the page, highlights (multiply at 80%), badges, then the strip.
- mPDF knows only regular and bold. In views `font-weight: bold` means SemiBold.
- No new `.env` values. Needs PHP `mbstring`, `gd` and `zlib`.

## Commands

```bash
php artisan report:sample                  # one sample PDF, keeps the newest 5
php artisan report:sample --pages=100 --sources=100 --import=path/to/real.pdf
```

## Heads up

- The logo, icons and callout wording currently come from the Turnitin samples (logo as a stand in). AC-10 and the legal check are still open, so no report may go to a customer yet.
- Not tried on real files: encrypted PDFs and pages with a real rotation (AC-8 skipped by choice).

_Drafted by /sync from the introducing change, worth a quick human pass._
