# Copyleaks to report mapping

## Overview

Turns one `completed` scan into a typed `ReportData` from our own saved files only (`summary` and `result` artifacts plus the submission). Features 11, 12 and 13 read `ReportData` and never open a Copyleaks file themselves. It never calls Copyleaks, never writes to the database or disk and never logs. Decided in spec 0008 (`docs/specs/0008-copyleaks-report-mapping/index.md`, mapping table of every report spot inside). Read it before changing a rule.

## Key files

| File                                                          | Owns                                                                                                                                                         |
| ------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `ReportDataMapper.php`                                        | The only entry point. `map(Scan): ReportData`. Checks status, then calls the helpers                                                                         |
| `ScanBundleReader.php`                                        | Opens one saved summary or result file: row, file exists, SHA 256 with `hash_equals`, gunzip, JSON. Failures are `ReportRenderFailed` reasons                |
| `ReportData.php`, `HighlightRange.php`                        | The result. No fields for match groups, source totals, integrity flags, filtered list or AI (left out on purpose)                                            |
| `SourceListBuilder.php`, `SourceNamer.php`                    | Ranked sources, percent, category (`Internet` only), name (url host, else title, else `Untitled source`)                                                     |
| `MatchRangeReader.php`, `RangeTrimmer.php`, `RangeMerger.php` | Character ranges from a result file as flat int lists, cut to the saved text length (drop past the end, clip over it), then overlap merge with the best rank |
| `DetailsMapper.php`                                           | Cover details. Dates, file size, pages, characters stay null until feature 21                                                                                |
| `ListedSource.php`, `SourceStat.php`, `WholeNumber.php`       | Small readonly data objects and the whole number check                                                                                                       |

Also: `app/Console/Commands/MapReportData.php` (`report:mapping`), `ScanSummary::resultItems()` and `totalWords()`.

## Conventions and rules

- Reason codes are named factories on `ReportRenderFailed`: `scan_not_completed`, `summary_missing`, `result_missing`, `checksum_mismatch`, `summary_unreadable`, `result_unreadable`. No file text in any message.
- The score is `results.score.aggregatedScore` through `ScanSummary`, never `scans.similarity_percent`.
- Sources with 0 matched words are dropped and their result file is never opened. A kept source with no result row stops the build (`result_missing`).
- Offsets are Copyleaks character offsets, passed straight to `ManuscriptPages::lineBoxes()`. Never convert them.
- The mapper reads the saved text once, only for its UTF-16 length, and trims each source's ranges to it before merging (spec 0008 AC-15). No text is kept. A scan with no saved text stops with `crawled_text_missing`.
- Result files are decoded one at a time and only numbers are kept. Keep ranges as flat int lists, not arrays of arrays (memory).
- No `Log::`, `ScanClient`, `Http::`, `SubmitScan` or database write in this folder (a test checks it).

## Commands

```bash
php artisan report:mapping {public_id}   # score, sources, range counts, warnings. No manuscript text
```

## Gotchas

- Field names (`matchedWords`, `url`, `title`, `text.comparison.*.suspected.chars`) are the documented ones, not yet confirmed on a real saved result. Spec 0008 build step 7 is open.
- Every highlight uses the fixed style `NotCitedOrQuoted` until feature 19.
- `CrawledTextReader` (manuscript folder) still opens the `text` file without a checksum check.

_Drafted by /sync from the introducing change, worth a quick human pass._
