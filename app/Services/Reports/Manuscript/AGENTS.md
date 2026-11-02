# Manuscript page rendering

## Overview

Turns a stored manuscript PDF and the saved Copyleaks `text` artifact into report pages, and answers "which page and box holds characters 120 to 340?". One mode for the whole manuscript: `original` (the real PDF pages) or `text` (plain text pages built from the Copyleaks text). It never calls Copyleaks, never writes to the database and never logs manuscript text. Decided in spec 0007 (`docs/specs/0007-manuscript-page-rendering/index.md`, notes in `rationale.md`). Read it before changing a threshold or a rule.

## Key files

| File                                                   | Owns                                                                                                                   |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `ManuscriptPagesBuilder.php`                           | `build(Scan)`: the fixed check order, then match, then pick the mode. Throws `ReportRenderFailed` for the stop reasons |
| `ManuscriptPages.php`, `ManuscriptOutcome.php`         | The result (mode, reason, section, line boxes) and the mode plus reason that the caller saves on the report row        |
| `CrawledTextReader.php`, `CrawledText.php`             | Loads the saved `text` artifact. Text is kept as UTF-16 code units, so Copyleaks offsets index it directly             |
| `SmalotPdfWordExtractor.php` (`PdfWordExtractor`)      | Word boxes per PDF page with `smalot/pdfparser`. Bound in `AppServiceProvider`                                         |
| `PageTextRuns.php`                                     | Text runs with true page positions. See the first gotcha                                                               |
| `PdfFontWidths.php`, `PdfPageBox.php`                  | Glyph widths (simple fonts and CID `W`/`DW`) and the page box with rotation                                            |
| `PageAnchorer.php`, `TextAligner.php`, `Alignment.php` | Find where each PDF page starts inside the Copyleaks text, then match characters per page. Gives the shares            |
| `TextPageLayout.php`, `TextPagesSection.php`           | `text` mode: Letter pages, x 36 to 576, y 60 to 732, 10 pt, 14 pt lines, 48 lines a page                               |
| `LineBox.php`, `WordBox.php`, `TextRun.php`            | Small readonly data objects                                                                                            |

Also: `app/Console/Commands/CheckManuscriptPages.php` (`report:manuscript`), `app/Enums/ManuscriptMode.php`, `app/Enums/ManuscriptFallbackReason.php`, `app/Services/Reports/MpdfTextMeasurer.php` and the `manuscript` block in `config/report.php`.

## Conventions and rules

- Original mode needs an overall match share of at least `0.98` and every page at least `0.90` (`config/report.php`). Anything lower falls back to `text` with reason `low_match`.
- Copyleaks page starts (`text.pages.startPosition`) are never used. They are about 5000 character chunks, not PDF pages. Pages are anchored by searching the text.
- Fallback reasons: `low_match`, `import_failed`, `rotated_page`. The reason is null exactly when the mode is `original`.
- Stop reasons (nothing is written): `word_not_supported`, `crawled_text_missing`, `no_completed_scan` (command only).
- Save the outcome through `ReportReplacer::replace(..., ?ManuscriptOutcome)`. Without an outcome both report columns stay null.
- No `Log::`, `ScanClient`, `Http::` or database write anywhere in this folder.

## Commands

```bash
php artisan report:manuscript {public_id}   # mode, reason, match shares, and a check PDF with a red outline on every word box
```

## Gotchas

- `smalot/pdfparser` multiplies stacked `cm` matrices in the wrong order, which moves text down the page when a file flips and then scales it (Google Docs does). Never use its `getDataTm()` for positions. Use `PageTextRuns`.
- A match share near 100% does not prove the boxes are in the right place. Check the outlines by eye on the check PDF.
- Box height and top use two constants in `SmalotPdfWordExtractor` (`TOP_FACTOR`, `HEIGHT_FACTOR`), tuned on real files.
- Copyleaks offsets are assumed to be UTF-16 code units. Not yet proven on a real scan with a match after an emoji (AC-10, open).
- A running header repeated on every page only anchors correctly when the Copyleaks text contains it too.

_Drafted by /sync from the introducing change, worth a quick human pass._
