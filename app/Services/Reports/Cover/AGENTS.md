# Similarity cover page

## Overview

Draws the one page cover of the similarity report from the typed `DocumentDetails` that the mapping already builds (spec 0008). Any detail with no value is left out, never blank and never guessed. Decided in spec 0009 (`docs/specs/0009-similarity-cover-page/index.md`, every position and rule inside). Read it before changing a rule. Features 12, 21 and 24 reuse this section.

## Key files

| File                     | Owns                                                                                                                                                               |
| ------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `CoverSection.php`       | The `ReportSection` (label `Cover Page`, 1 page). Draws author, title, assignment, field pairs and stats box at the design positions, through `SectionCanvas` only |
| `CoverTextFitter.php`    | Pure fitting rules: collapse whitespace, cut one line with `…`, wrap the title to 2 lines. Takes a width function per call                                         |
| `CoverReportFactory.php` | `make(ReportData): ReportDocument`. Pure. Header ID is `details.submissionId`; blank throws `submission_id_missing`                                                |
| `CoverLeftOut.php`       | The fixed names of the spots with no value, for the `Left out:` line of the command                                                                                |

Also: `app/Console/Commands/BuildCoverReport.php` (`report:cover`), `app/Services/Reports/SampleFilePruner.php` (keeps the newest 5 files per prefix, shared with `report:sample`), `ReportParts::statsBox` (shrinking height, movable top).

## Conventions and rules

- A null, blank or 0 value leaves its own spot out. Field pairs close up into the freed 36 pt slot. The stats box lists only counts above 0.
- Every text is one `nowrap` div per line, so mPDF never re-wraps a fitted line. Collapse whitespace first, then measure in the font and size that draws it, against 98% of the space.
- A title on 2 lines moves everything below it down 20 pt, including the stats box.
- Every value is drawn as escaped plain text. Markup is shown as text.
- Bump `MpdfReportRenderer::VERSION` when the look of the cover changes.
- No `Log::`, `ScanClient`, `Http::`, `SubmitScan` or database write in this folder.
- `report:cover` prints no author, title, assignment or manuscript text.
- Names in scripts our fonts lack (Arabic, Urdu, Chinese) fall back to another font and are not laid out right to left. Known limit.

## Commands

```bash
php artisan report:cover {public_id}   # cover only PDF from the newest completed scan, no Copyleaks call
php artisan report:sample              # the sample report, its cover uses the real CoverSection
```

_Drafted by /sync from the introducing change, worth a quick human pass._
