# Similarity overview pages

## Overview

Draws the overview pages of the similarity report: page 1 is the summary (score, optional filters, match groups, source totals, flag line, info box), the ranked source list follows from page 2 and flows across pages without cutting a row. Decided in spec 0010 (`docs/specs/0010-similarity-overview-pages/index.md`, every position and rule inside). Read it before changing a rule.

## Key files

| File                        | Owns                                                                                                                                               |
| --------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------- |
| `OverviewSection.php`       | The `ReportSection` (label `Integrity Overview`). `pageCount()` is 1 plus the source pages. Max 4 groups and 4 filters, else `overview_too_many_*` |
| `OverviewReportFactory.php` | `make(ReportData): ReportDocument`, cover plus overview. Pure. `LEFT_OUT` names the blocks the real report leaves out for now                      |

Also: `app/Console/Commands/BuildOverviewReport.php` (`report:overview`), `app/Services/Reports/Parts/SourceTotals.php`, `SourceRowPager` (rows per page: 10 on the first row page from 245.5 pt, then 14 from 75 pt).

## Conventions and rules

- A block with no data is left out or drawn as a heading only. Nothing moves up to fill the gap.
- Zero sources: one page after the cover, with `No matching sources found`.
- A source with a null category draws no category pill. Under 1% shows `<1%`. A long name ends in `…`.
- Every value is drawn as escaped plain text. Markup is shown as text.
- Bump `MpdfReportRenderer::VERSION` when the look changes.
- No `Log::`, `ScanClient`, `Http::` or `SubmitScan` in this folder.
- `report:overview` keeps the newest 5 `overview-*` files and prints no source names or manuscript text.

## Commands

```bash
php artisan report:overview {public_id}   # cover plus overview PDF from the newest completed scan, no Copyleaks call
php artisan report:sample --sources=25    # the sample report, its overview uses the real OverviewSection
```

_Drafted by /sync from the introducing change, worth a quick human pass._
