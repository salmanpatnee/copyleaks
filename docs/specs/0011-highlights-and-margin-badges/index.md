# 0011. Highlights and margin badges

**Date**: 2026-11-06
**Status**: Accepted

## Summary

This spec connects the pieces we already have so a report shows highlighted text and numbered margin badges on the manuscript pages. It turns each saved match range into colored rectangles on the right words, and puts one badge in the left margin at the first line of each match. When badges would land on top of each other, they slide right into the next free spot instead of overlapping. A new `report:similarity` command builds the full report (cover, overview and manuscript pages) from a saved scan, with no new Copyleaks call, so you can look at a real one.

## Requirements

**User stories**:
- As a staff member, I want every match to be highlighted on the right words, so that the report can be trusted.
- As a staff member, I want a numbered badge in the margin at the start of each match, so that I can find its row in the source list.
- As a staff member, I want badges that never sit on top of each other, so that every source number can be read.
- As the developer, I want one command that builds the whole similarity report from a saved scan, so that I can check the real look without spending a scan.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: A new `HighlightPlacer::place(list<HighlightRange> $ranges, ManuscriptPages $pages, MatchGroupStyle $style): OverlayPlan` calls `ManuscriptPages::lineBoxes($range->start, $range->length)` once per range. Each returned `LineBox` becomes one `Highlight` with the same `x`, `y`, `width` and `height`, on the page number of the box, with the given style (`ReportData::$highlightStyle`). The placer does no position work of its own and never converts offsets (spec 0008, spec 0007 AC-2). Ranges are handled in the order given.
- **AC-2**: Each range with at least one box gets exactly one `MarginBadge`: page and `y` from its first box, `rank` from `HighlightRange::$rank`, the same style as the highlight. A range that crosses lines or pages still gets only one badge, at its first box. Two ranges that start on the same line each get their own badge.
- **AC-3**: A range with no boxes (nothing could be placed) adds no highlight and no badge. It is counted in `OverlayPlan::$unplacedRanges`. This never stops the build.
- **AC-4**: Badge slots are the left margin spots `x = 7.5 + 36.5 × k` for `k` = 0, 1, 2, 3 (7.5, 44, 80.5, 117). A new `BadgeSlotter::place(list<BadgeCandidate> $candidates): array{badges: array<int, list<MarginBadge>>, dropped: int}` picks the lowest `k` that is free for that page (`BadgeCandidate` is a readonly `page, y, start, rank, style`). `HighlightPlacer` gets the slotter through its constructor and passes it one candidate per range. A slot is free when the last badge placed in it on the same page ends above the new one: `lastY + 16.5 <= newY` (15.5 high plus a 1 pt gap). The slotter sorts the candidates itself, per page by `y`, then range start. A badge is never moved up or down, so it always stays level with the first line of its match. A badge in a later slot may sit over page text. That is accepted. In `text` mode even slot 0 reaches x 41.7 while text starts at x 36, so it covers about 5.7 pt of the first letters. That is accepted too (moving the text edge is spec 0007's layout). There is no clamping: a badge on a first line near the top can be covered by the header strip, and one near the bottom of a short page can run into the footer strip. Clamping would break "level with the line".
- **AC-5**: When all four slots are busy for a badge, the badge is dropped (the highlight stays) and `OverlayPlan::$droppedBadges` goes up by one. Nothing else moves.
- **AC-6**: The badge number is written centered in the 17.7 pt pill. One or two digits keep the measured 7.75 pt size. Three digits use 6.0 pt. Four or more digits use 4.5 pt. The number is always the whole rank, never cut and never `99+`. Both the text width measure and the vertical centering offset in `drawBadge` use the chosen size (today they use `ReportTheme::SIZE_BADGE`). The two new sizes are named constants in `ReportTheme` (`SIZE_BADGE_3 = 6.0`, `SIZE_BADGE_4 = 4.5`), with no magic numbers in the canvas.
- **AC-7**: A new `SimilarityReportFactory::make(ReportData $data, ManuscriptPages $pages, OverlayPlan $plan): ReportDocument` reads the stored PDF to count pages (in `original` mode, through `ManuscriptPages::section()`), writes nothing and can throw an import `ReportRenderFailed`. It makes no database or Copyleaks call and no log. It takes the sections of `OverviewReportFactory::make($data)` and adds `$pages->section('Integrity Submission', $plan->highlights, $plan->badges)`. Kind `Similarity`, same header ID. Total pages `N` is cover plus overview plus manuscript pages, and page labels read `Page n of N - Integrity Submission` on the manuscript pages. A scan with no ranges still builds, with manuscript pages and no overlays.
- **AC-8**: A new `SimilarityReportBuilder::build(Scan $scan): SimilarityBuild` runs in this order: map the scan (`ReportDataMapper`), build the manuscript pages (`ManuscriptPagesBuilder`), place the overlays, make the document. The first failure wins. `SimilarityBuild` holds the document, the `OverlayPlan` and the `ManuscriptOutcome`. Every `ReportRenderFailed` from the earlier specs (mapping, Word file, missing text, import, overflow) passes through unchanged, so nothing is hidden. It never calls Copyleaks.
- **AC-9**: `php artisan report:similarity {public_id}` picks the newest `completed` scan (the same pick as `report:overview`), builds through `SimilarityReportBuilder` and the existing `ReportRenderer`, and writes `reports/samples/similarity-{Ymd-His}.pdf` on the `local` disk. It prints: `Path`, `Size`, `Pages`, `Sources`, `Manuscript: original` or `Manuscript: text (reason)` (reason is the `ManuscriptFallbackReason` value: `low_match`, `import_failed` or `rotated_page`), `Highlights: n rectangles` (the total over all pages), `Badges: n` (badges drawn), `Unplaced ranges: n`, `Dropped badges: n` (so ranges always equal badges plus dropped plus unplaced), and one `Left out:` line with the same names as `report:overview` (cover names, then Match Groups, Source totals, Filtered list, Integrity flag count). It keeps only the newest 5 `similarity-*.pdf` and never touches `cover-*`, `overview-*` or `sample-*` (and the other commands never touch similarity files). A stop reason prints as `{reason}: {message}` and exits with a failure code. An unknown `public_id`, or no completed scan, prints `no_completed_scan`. It prints no author, title, assignment, source name or manuscript text.
- **AC-10**: The draw order on a manuscript page stays as spec 0006 fixed it: page, highlights (multiply at 80%), badges, then the header and footer strip. This feature does not change `SectionCanvas::overlay` apart from the badge number size in AC-6. `MpdfReportRenderer::VERSION` goes from `3` to `4` because the real report look changes.
- **AC-11**: Nothing in the new `Highlights` folder or in the command calls `Log::`, `ScanClient`, `Http::` or `SubmitScan`, or writes to the database. `report:similarity` never starts a scan. It is checked with a search at `/check verify`, not with a test file.
- **AC-12**: On one real small scan (a person's own upload), the report shows highlights covering exactly the matched words on every page, a badge level with the first line of each match, and no two badges overlapping. This is checked by eye at `/check verify`, and also in `text` mode if that fallback happens.

## Decision

**Chosen option**: Option 1: Add a small placer, a badge slot rule, a factory and a builder, plus one `report:similarity` command.

Highlights come straight from `lineBoxes()` (all position logic stays in spec 0007). The only new rule is where badges go: level with their match, sliding right into one of four fixed margin slots when the first one is busy.

**Implementation skills**: none. The project has no installed skill for this area (`.agents/skills/` holds only `shadcn-vue`, which is UI only).

## Feature design

**Data model sketch**:

No migration and no new table. Nothing is saved to the database (the finished report row and the download are feature 24).

| Class | Folder | Owns |
| --- | --- | --- |
| `HighlightPlacer` | `app/Services/Reports/Highlights/` | Ranges to highlights and badges (AC-1 to AC-3) |
| `BadgeSlotter`, `BadgeCandidate` | `app/Services/Reports/Highlights/` | The four slots, sorting and the free slot rule (AC-4, AC-5). Pure |
| `OverlayPlan` | `app/Services/Reports/Highlights/` | Readonly: `highlights` and `badges` keyed by page, `unplacedRanges`, `droppedBadges` (numbers only) |
| `SimilarityReportFactory` | `app/Services/Reports/Highlights/` | Data, pages and plan to the full document, no writes (AC-7) |
| `SimilarityReportBuilder`, `SimilarityBuild` | `app/Services/Reports/Highlights/` | Scan to document, plan and outcome (AC-8) |
| `BuildSimilarityReport` | `app/Console/Commands/` | The `report:similarity` command (AC-9) |

Changes to existing code: `SectionCanvas::drawBadge` picks the number size by digit count (AC-6) and `ReportTheme` gains two size constants; `SampleFilePruner` is reused with the prefix `similarity`; `MpdfReportRenderer::VERSION` is bumped. `ManuscriptPages`, `ReportData`, `OverviewReportFactory` and the section classes are used as they are. `report:sample` is left alone.

**State transitions**: none. The report is a pure drawing of saved data.

**API surface**:

| Entry | Kind | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `php artisan report:similarity {public_id}` | Console command | `public_id` of a submission | PDF in `reports/samples`, numbers and mode on screen | Local machine only, no web route | `no_completed_scan`, any `ReportRenderFailed` reason |
| `HighlightPlacer::place(...)` | Method | ranges, `ManuscriptPages`, style | `OverlayPlan` | none | none (unplaced ranges are counted) |
| `SimilarityReportBuilder::build(Scan)` | Method | a completed `Scan` | `SimilarityBuild` | none | `ReportRenderFailed` |

**Value sourcing**:

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Draw a highlight | page, x, y, width, height | `ManuscriptPages::lineBoxes()` (spec 0007), unchanged |
| Draw a highlight | color | `ReportData::$highlightStyle`, fixed `NotCitedOrQuoted` until feature 19 (spec 0008) |
| Draw a badge | page and y | the first `LineBox` of the range |
| Draw a badge | x | `BadgeSlotter` (AC-4) |
| Draw a badge | number | `HighlightRange::$rank`, the lowest rank covering the first character (spec 0008) |
| Draw a badge | circle color and pill color | `MatchGroupStyle` color, `SourceColor::forRank` (spec 0006) |
| Header ID, total pages | text and `N` | `CoverReportFactory` and the sum of `pageCount()` (spec 0009) |
| Command output | counts and mode | `OverlayPlan` and `ManuscriptOutcome` |

**Key invariants**:
- Every highlight and badge comes from a range of the saved scan. Nothing is guessed or invented.
- The badge number always equals the rank of its row in the source list.
- Two badges on one page never overlap (same slot needs 16.5 pt between tops, different slots are 36.5 pt apart).
- A badge is always level with the first line of its match. It moves only sideways.
- Each range gives at most one badge. A dropped badge never removes its highlight.
- Overlay page numbers follow the manuscript section as drawn: the PDF page in `original` mode, the text page in `text` mode.

**Security model**:

Internal staff tool, a console command only, no web route and no new access path. It reads saved files and writes one PDF under the private `local` disk. It never logs or prints manuscript text, author, title, assignment or source names (project rule).

**Configuration required**: none.

**Critical test scenarios** (checked at `/check verify`, no test files are written unless you ask):
- Happy path: a real scan builds the report, highlights sit on the matched words and each badge shows the right source number, verifies **AC-1**, **AC-2**, **AC-9**, **AC-12**
- Overlap: two matches that start on neighbouring lines show their badges in slots 0 and 1, never on top of each other, verifies **AC-4**
- Crowded line: a fifth badge near four busy slots is dropped, its highlight stays, and the command prints `Dropped badges: 1`, verifies **AC-5**
- Unplaced range: a range with no found words adds nothing and is counted, verifies **AC-3**
- Big rank: a source ranked 100 or more shows its full number inside the pill, verifies **AC-6**
- Zero ranges: a scan with no matches still builds the full report, verifies **AC-7**
- Failure: a Word file or a missing text file stops with its reason and exits with a failure code, verifies **AC-8**, **AC-9**
- Safety: a search finds no `Log::`, `ScanClient`, `Http::`, `SubmitScan` or database write in the new folder and command, verifies **AC-11**

## Build plan

Order follows Tracer Bullet: a thin real thread from saved scan to a PDF with highlights and one badge per match first, then the badge rules, then the finishing and the real scan look.

1. Thin thread: `HighlightPlacer` (one highlight per box, one badge per range at slot 0, no collision rule yet), `OverlayPlan`, `SimilarityReportFactory`, `SimilarityReportBuilder` and a first `report:similarity` that writes the PDF and prints path and pages, satisfies **AC-1**, **AC-2**, **AC-7**, **AC-8**
2. Badge slots: `BadgeSlotter` with the four slots, the free slot rule, sorting per page, and dropped badges, satisfies **AC-4**, **AC-5**
3. Unplaced ranges and big numbers: count ranges with no boxes, and size the badge number by digit count in `SectionCanvas::drawBadge`, satisfies **AC-3**, **AC-6**
4. Command finish and safety: all printed numbers, mode and reason, `Left out:` line, failure codes, keep newest 5 with the `similarity` prefix, `VERSION` bump, the search check for forbidden calls, satisfies **AC-9**, **AC-10**, **AC-11**
5. One look at a real small scan, in `original` mode (and `text` mode if it happens), findings written in `rationale.md` and `verify.md`, satisfies **AC-12**

## Consequences

**Positive**:
- All position work stays in one place (`lineBoxes()`). This feature only picks colors, slots and sizes.
- Every source number stays readable, because badges slide instead of being dropped (up to four slots).
- The command lets you rebuild and re look at a report from a saved scan any number of times for free.

**Negative / tradeoffs**:
- A badge in slot 2 or 3 can sit over page text, and a badge near the page bottom can be partly covered by the footer strip. This is accepted over losing a source number.
- A fifth close badge is dropped, so a very crowded line shows a highlight with no number. The command counts these so the gap is visible.
- Two ranges that touch (one starts exactly where the last ended) are not merged by spec 0008. If they meet inside a word, that word is highlighted twice, looks darker, and gets two badges. Accepted.
- The header strip can cover a badge on a first line near the top of a page, and in `text` mode every badge covers about 5.7 pt of the first letters. Accepted (see AC-4).
- Highlights follow whole words, and a scanned PDF with a hidden text layer may highlight where the hidden text sits (known limit from spec 0007).
- Sandbox scans are mock data and cannot prove alignment. The real look needs one real scan (one credit), taken from a person's own upload.

**Neutral**:
- Every highlight and badge still says `Not Cited or Quoted` until feature 19.
- Nothing is saved to the database. Saving the report and the download come with feature 24, which must pass `ManuscriptPages::outcome()` to `ReportReplacer` (open item from spec 0007).
- `report:sample` keeps its hand placed highlights.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Follow-up

- [x] One real small scan to prove alignment (AC-12). Done 2026-11-09 on a real 13 page scan: highlights on the matched words, badges level with the first line, no overlap.
- [ ] Feature 24 passes `ManuscriptPages::outcome()` to `ReportReplacer` when it saves the report (open from spec 0007).
- [ ] After feature 19, the style per range replaces the fixed `NotCitedOrQuoted` (the placer already takes a style, so it will need one per range).
- [x] First real scan (2026-11-06) showed `Unplaced ranges: 9` of 10 because Copyleaks sent offsets past the end of the text. Spec 0008 AC-15 trims them in the mapper. After that build, the same scan should print `Ranges: 1` and `Unplaced ranges: 0`. No code change in this spec.
- [ ] Add `app/Services/Reports/Highlights/AGENTS.md` and a pointer in root `AGENTS.md` (`/sync` owns this).
