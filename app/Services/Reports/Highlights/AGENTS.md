# Highlights and margin badges

## Overview

Turns the saved match ranges into highlight rectangles and numbered margin badges on the manuscript pages, and builds the full similarity report (cover, overview, manuscript). Decided in spec 0011 (`docs/specs/0011-highlights-and-margin-badges/index.md`, every rule inside). Read it before changing a rule. All position work stays in `ManuscriptPages::lineBoxes()` (spec 0007); this folder only copies boxes and picks badge spots.

## Key files

| File                                             | Owns                                                                                                                                                                      |
| ------------------------------------------------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `HighlightPlacer.php`                            | `place(ranges, ManuscriptPages, style): OverlayPlan`. One highlight per line box, one badge per range at its first box. Ranges with no boxes are counted as unplaced      |
| `BadgeSlotter.php`, `BadgeCandidate`             | Pure. Four left margin slots at x 7.5, 44, 80.5, 117. A badge slides right to the first free slot, never up or down. A fifth close badge is dropped (its highlight stays) |
| `OverlayPlan.php`                                | Readonly result: highlights and badges keyed by page, `unplacedRanges`, `droppedBadges`                                                                                   |
| `SimilarityReportFactory.php`                    | Data, pages and plan to the full `ReportDocument`. Writes nothing, no database, no Copyleaks call                                                                         |
| `SimilarityReportBuilder.php`, `SimilarityBuild` | Scan to document, plan, outcome and data. Order: map, pages, place, make. The first `ReportRenderFailed` passes through unchanged                                         |

Also: `app/Console/Commands/BuildSimilarityReport.php` (`report:similarity`). The badge number size by digit count (7.75, 6.0 for three digits, 4.5 for four or more) lives in `SectionCanvas::drawBadge` and `ReportTheme`.

## Conventions and rules

- A slot is free when `lastY + 16.5 <= newY`. Badges are never clamped, so one near the top can sit under the header strip. That is accepted.
- Ranges always equal badges plus dropped plus unplaced. The command prints all three.
- Overlay page numbers follow the manuscript section as drawn: the PDF page in `original` mode, the text page in `text` mode.
- No `Log::`, `ScanClient`, `Http::`, `SubmitScan` or database write in this folder or the command. `report:similarity` never starts a scan.
- Bump `MpdfReportRenderer::VERSION` when the look changes.
- Nothing is saved yet. Feature 24 must pass `ManuscriptPages::outcome()` to `ReportReplacer` when it saves the report.
- `report:similarity` keeps the newest 5 `similarity-*` files and prints no author, title, source name or manuscript text.

## Commands

```bash
php artisan report:similarity {public_id}   # full report PDF from the newest completed scan, no Copyleaks call
```

_Drafted by /sync from the introducing change, worth a quick human pass._
