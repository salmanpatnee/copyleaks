# 0010. Similarity overview pages

**Date**: 2026-11-05
**Status**: Proposed

## Summary

This spec turns the throwaway sample overview into the real overview pages of the similarity report. It keeps the full Turnitin page shape from the design spec. The overall score and the ranked source list are drawn from the saved Copyleaks result. Blocks we have no data for (Match Groups rows, the Top Sources totals, the Filtered list, the flag count) are drawn as headings only or left out, never guessed. A new `report:overview` command builds the cover and overview from a saved scan, so you can look at a real one without a new scan.

## Requirements

**User stories**:
- As a staff member, I want the overview to show the overall similarity and the ranked sources from the Copyleaks result, so that the customer sees the right numbers.
- As a staff member, I want a block with no data to show only its heading or disappear, so that the page never shows a made up number.
- As the developer, I want one real overview section whose optional blocks features 19, 20 and 22 can fill later without redoing the layout, so that nobody copies the sample page again.
- As the developer, I want to build the cover and overview from a saved scan with one command, so that I can check the real look without spending a scan.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: A new `OverviewSection` (a `ReportSection`, label `Integrity Overview`) draws Letter pages and touches mPDF only through `SectionCanvas`. `pageCount()` is `1 + count(SourceRowPager::split(sourceCount, 245.5, 75))` and is known before drawing. Page 1 never holds source rows (the list always starts on page 2, as in the design spec). Constructor: `float $score, list<SourceRow> $sources, ReportAssets $assets, list<MatchGroupRow> $groups = [], ?SourceTotals $sourceTotals = null, list<string> $filters = [], ?int $flagCount = null`. The four optional blocks default to "no data". The real overview passes only the score, the sources and the assets. `ReportData::sourceStats` is not used for the totals (it holds per source numbers, not totals by type), so nobody fills totals from it by guessing.
- **AC-2**: Page 1 draws the design layout: the score and `Overall Similarity` (score at 36, 55, Lexend Deca; the label 10 pt after the score's right edge), the subtitle at 80, a full width divider, the `Match Groups` (x 36) and `Top Sources` (x 298.5) headings 13.3 pt below that divider, a second divider 129.2 pt below the headings, then `Integrity Flags`. With no filters the first divider is at 148.5, the headings at 161.8 and the second divider at 291. The score is written with the existing `PercentFormat::label` (whole numbers, `<1%` under 1).
- **AC-3**: `Match Groups` and `Top Sources` always keep their headings. With `groups` empty, no group row is drawn. With `sourceTotals` null, no total row is drawn. When given, the rows are drawn exactly as the sample draws them today (group rows 25 pt apart, at most 4, one per `MatchGroupStyle`; three total rows 14 pt apart, with the icon positions and sizes moved over unchanged). More than 4 groups throws `ReportRenderFailed` with the reason `overview_too_many_groups`, because a 5th row would pass the second divider. `SourceTotals` is `(float $internet, float $publications, float $studentPapers)`, none nullable, each written with `PercentFormat::label`.
- **AC-4**: `filters` empty leaves out `Filtered from the Report` and its items. The first divider stays at 148.5 (the same place as with one item), so the band from 100.8 to 148.5 is blank; nothing moves up. When given (at most 4, more throws `overview_too_many_filters`), the heading sits at 100.8, items every 14 pt from 122, and the first divider is at `122 + 14·(n−1) + 26.5` (176.5 for 3). The summary headings are 13.3 pt below the first divider and the second divider 129.2 pt below the headings. `flagCount` null leaves out the count line only; the `Integrity Flags` heading and the info box stay (heading 10.8 pt and box 27.5 pt below the second divider, box 266 × 64 at x 301). A given count (0 is a real value) draws `{n} Integrity Flags for Review` at x 36, 28 pt below the second divider, in `body semi`, with no plural rule.
- **AC-5**: Page 2 has the design positions: divider at 187.5, `Top Sources` heading at 205.8, the description at 224, rows from 245.5, 10 rows at most. At the top it repeats the Match Groups and Top Sources summary block (headings at 53.3) when `groups !== []` or `sourceTotals !== null`. Otherwise it draws only the score and `Overall Similarity` there (same style and 10 pt gap as page 1, top at 55), with no subtitle and nothing else above the divider at 187.5. Later pages draw only rows, from y 75, 14 per page. Apart from the fixed headings (`Match Groups`, `Top Sources` in the summary and above the list, `Integrity Flags`) and the fixed subtitle and description, no page gets a heading, divider or text that has no data behind it.
- **AC-6**: Source rows come from the ranked list in the given order and are drawn by the existing `ReportParts::sourceRow` (number pill, optional category pill, name cut with `…`, percentage, 48 pt row, divider). A row is never split across pages. The number pill colors follow `SourceRow->rank` mod 4 across pages (as `ReportParts::sourceRow` already does); the section never renumbers. A null category skips only the category pill (spec 0008 AC-6). Every percentage and rank on the page is the one in the input, with no rounding or reordering added by this section.
- **AC-7**: With zero sources the overview is exactly 1 page: page 1 as in AC-2 to AC-4, no `Top Sources` list heading or description, and one muted line `No matching sources found` at x 36, 17.5 pt below the bottom of the info box (y 400 with no filters). With one or more sources that line is never drawn. A scan with a score and no sources builds this page and `report:overview` exits with success.
- **AC-8**: Every value is drawn as plain text. Markup in a source name (for example `<b>` or an image tag) shows as text, never runs, and no web request is made.
- **AC-9**: A new `OverviewReportFactory::make(ReportData): ReportDocument` calls `CoverReportFactory::make`, then returns a new `ReportDocument` of kind `Similarity` with the same header ID and the cover's sections followed by one `OverviewSection` built from `ReportData` (score from `similarityPercent`, `sources`; the four optional blocks left at "no data"). The header ID and the `submission_id_missing` stop come from `CoverReportFactory` unchanged. The factory is pure: no database, no Copyleaks call, no disk write, no log. Page labels read `Page 2 of N - Integrity Overview`.
- **AC-10**: `php artisan report:overview {public_id}` maps the newest `completed` scan (same pick as `report:cover`), builds the document through the factory and the existing `ReportRenderer`, and writes `reports/samples/overview-{Ymd-His}.pdf` on the `local` disk. It prints the full path, size, page count, the source count and one `Left out:` line with the cover names (spec 0009 AC-8) plus these overview names, in this order, on the same line: Match Groups, Source totals, Filtered list, Integrity flag count (a fixed constant list, since the real overview always leaves all four out today; the line is never `none`). It keeps only the newest 5 `overview-*.pdf` and never touches `cover-*` or `sample-*` files (and the other two commands never touch overview files). A stop reason prints as `{reason}: {message}` and exits with a failure code. An unknown `public_id`, or no completed scan, prints `no_completed_scan`. It prints no author, title, assignment, source name or manuscript text.
- **AC-11**: `report:sample` uses the real `OverviewSection` with all four optional blocks filled (the sample keeps its full look) and `SampleOverviewSection` is deleted. The sample looks exactly the same as before (3 filters, 4 group rows, the same totals, `flagCount` 0); the sample factory keeps the old category sum ('Internet', 'Publication', 'Student papers') moved over unchanged and passes it as `SourceTotals`. `MpdfReportRenderer::VERSION` is bumped because a new real overview look exists.
- **AC-12**: Nothing in the overview folder calls `Log::`, `ScanClient`, `Http::`, `SubmitScan`, or writes to the database, and `report:overview` never starts a scan (an architecture test, the same rule as spec 0009 AC-10).

## Decision

**Chosen option**: Option 1: Promote the sample overview into a real `OverviewSection` with optional blocks, a factory and a `report:overview` command.

Move the overview into its own folder as production code. Blocks with no data are optional inputs that default to "left out", so features 19, 20 and 22 only pass data later. Prove it on a real saved scan through a command that never calls Copyleaks.

**Implementation skills**: none. The project has no installed skill for this area (`.agents/skills/` holds only `shadcn-vue`, which is UI only).

## Feature design

**Data model sketch**:

No migration and no new table. The overview reads only the typed `ReportData` that spec 0008 already builds.

| Class | Folder | Owns |
| --- | --- | --- |
| `OverviewSection` | `app/Services/Reports/Overview/` | Draws the overview pages (AC-1 to AC-8). Replaces `Sample/SampleOverviewSection` |
| `SourceTotals` | `app/Services/Reports/Parts/` | Readonly data object: Internet, Publications and Student Papers percentages. Null on the section means heading only |
| `OverviewReportFactory` | `app/Services/Reports/Overview/` | `ReportData` to a cover plus overview `ReportDocument` (AC-9) |
| `BuildOverviewReport` | `app/Console/Commands/` | The `report:overview` command (AC-10) |

Changes to existing code: `SampleReportFactory` builds the real `OverviewSection` with all blocks filled and computes the sample totals itself; `SampleFilePruner` is reused with the prefix `overview`; `MpdfReportRenderer::VERSION` is bumped. `SourceRowPager`, `ReportParts` and `ReportTheme` are reused as they are. `SampleOverviewSection::sourceShare` (the totals sum) moves into the sample factory, because the real section never adds up totals itself.

**State transitions**: none. The overview is a pure drawing of saved data.

**API surface**:

| Entry | Kind | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `OverviewReportFactory::make` | PHP method | `ReportData` (req) | `ReportDocument` | none (internal) | `submission_id_missing` |
| `ReportRenderer::render` | existing | the document, a path on the `local` disk | `BuiltReport` | none | `ReportRenderFailed` with a reason code |
| `php artisan report:overview {public_id}` | command | `public_id` (req) | file path, size, pages, source count, `Left out:` line | none (staff on the server) | `no_completed_scan`, mapper reasons (spec 0008), renderer reasons |

**Value sourcing** (every value the overview shows and where it comes from):

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Draw page 1 | Overall score | `ReportData::similarityPercent` (`results.score.aggregatedScore`, spec 0008) written by `PercentFormat::label` |
| Draw page 1 | Subtitle, headings, info box wording | Fixed wording from the design spec (the box wording is a Turnitin stand in, spec 0006 AC-10) |
| Draw page 1 | Match Groups rows | Left out for now (no Copyleaks data, feature 19). The section takes `groups` |
| Draw page 1 | Top Sources totals | Left out for now (feature 20). The section takes `sourceTotals` |
| Draw page 1 | Filtered list | Left out for now (scans run with no exclusions, spec 0004). The section takes `filters` |
| Draw page 1 | Flag count line | Left out for now (feature 22). The section takes `flagCount` |
| Draw rows | Rank, category, name, percent | `ReportData::sources` (`SourceRow`, spec 0008 AC-4 to AC-7), in the given order |
| Draw rows | Pill colors | Rank mod 4, `SourceColor` (spec 0006) |
| Draw rows | Rows per page, positions | `SourceRowPager` and the design spec (10 on page 2, 14 later) |
| Draw strip | `Page n of N - Integrity Overview` | `ReportDocument::totalPages()` and `OverviewSection::label()` |
| Draw strip | Header and footer ID | `CoverReportFactory` from `details.submissionId` |
| No sources | Message `No matching sources found` | Fixed wording, shown only when the source list is empty (AC-7) |
| `report:overview` | Output file name | `date('Ymd-His')` at the time of the run |
| `report:overview` | `Left out:` list | `CoverLeftOut` names plus the fixed overview names constant on `OverviewReportFactory` (labels only) |

**Key invariants**:
- The section never writes more pages than its `pageCount()` (the engine fails with `overflow` otherwise).
- No heading, number or line is drawn without data behind it, except the three headings fixed by the design (`Match Groups`, `Top Sources`, `Integrity Flags`).
- A source row is never split and keeps the rank order it was given.
- Everything is drawn as escaped plain text from local fonts and icons only.
- The overview never changes saved data and never calls Copyleaks.

**Security model**: An internal tool run by staff or on the server. Source names come from Copyleaks and can hold odd text, so the command prints none of them and the code never logs them. Overview files are written to the private `local` disk, never a public folder. No compliance scope beyond the project rules in `AGENTS.md`.

**Configuration required**: none. No new `.env` value (the folder and keep count reuse `config('report.sample_keep')`).

**Critical test scenarios** (each maps to an acceptance criterion; tests are only written when you ask for them):
- Happy path: a saved scan with today's data builds cover plus overview; page 1 has the score, empty Match Groups and Top Sources headings, the info box and no filter or flag line; rows start on page 2, verifies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-9**, **AC-10**
- Paging: 25 sources give 10 on page 2, then 14 and 1; 100 sources build without `overflow`; no row is cut; the color cycle continues, verifies **AC-1**, **AC-5**, **AC-6**
- Page 2 top: without summary data it shows the score line; with groups it repeats the summary block, verifies **AC-5**
- Odd data: a score under 1 shows `<1%`; a null category skips only the pill; a very long name ends in `…`; markup in a name shows as text, verifies **AC-2**, **AC-6**, **AC-8**
- Empty list: zero sources gives 1 page with the message (also with the sample's 3 filters, where it sits lower); one source never shows it; a score with no sources succeeds, verifies **AC-7**, **AC-10**
- Limits: 5 group rows or 5 filters throw their reason code and leave no file, verifies **AC-3**, **AC-4**
- Failure: a scan that is not `completed`, a damaged saved file, a blank submission ID or an unknown `public_id` prints a reason and leaves no file behind, verifies **AC-9**, **AC-10**
- Housekeeping: a sixth run deletes the oldest overview and leaves every `cover-*` and `sample-*`, verifies **AC-10**
- Sample and safety: `report:sample` still shows groups, totals, filters and the flag line; the folder has no `Log::`, client or database write, verifies **AC-11**, **AC-12**

## Build plan

Tracer Bullet: one thin real thread first (an overview from a saved scan through the command), then thicken it one strand at a time. Tests are not in this plan (the project rule is to write them only on request); `/check verify` then `/test similarity overview pages` are the closing steps at the Beta tier.

1. Thin thread: create `Overview/OverviewSection` by moving the sample overview as is, with the four optional inputs; `OverviewReportFactory`; a first `report:overview` that maps, renders and prints the path; point `SampleReportFactory` at the real section (blocks filled) and delete `SampleOverviewSection`, satisfies **AC-1**, **AC-9**, **AC-10**, **AC-11**
2. Thicken the left out rules: heading only Match Groups and Top Sources, closing up the dividers when there is no filter list, no flag line, the `SourceTotals` object, satisfies **AC-2**, **AC-3**, **AC-4**
3. Thicken the pages: page 2 score line versus repeated summary block, page 2 and later positions, zero source page and message, escaping check, satisfies **AC-5**, **AC-6**, **AC-7**, **AC-8**
4. Finish the command and safety: `Left out:` line with the fixed names constant, failure codes, keep 5 `overview-*` through `SampleFilePruner`, bump `MpdfReportRenderer::VERSION`, architecture rule for the folder, then look at the sample and at one real saved scan if one exists, satisfies **AC-10**, **AC-11**, **AC-12**

## Consequences

**Positive**:
- Features 13, 19, 20, 22 and 24 get a real overview section. Features 19, 20 and 22 only pass data in, with no layout change.
- A real overview can be checked by eye from a saved scan, so no scan is spent on layout work.
- The sample keeps its full look, so the full design layout stays proven while the real report is thin.

**Negative / tradeoffs**:
- With today's data page 1 looks unfinished: two empty heading areas, the info box, and a big gap. Page 2 also has a gap between the score line and the list, because you chose to keep the design positions exactly.
- The optional inputs (`groups`, `sourceTotals`, `filters`, `flagCount`) are only used by the sample until Slice 3, so they are code with no real caller yet.
- The info box wording is still the Turnitin stand in (spec 0006 AC-10 and the legal check), so no report may go to a customer yet.
- Every highlight and badge still says `Not Cited or Quoted` until feature 19, and the empty Match Groups heading makes that gap visible.
- Two `report:overview` runs in the same second write the same file name, so the second replaces the first. This is harmless.
- `report:overview` reads the full mapping (including result files), which is more work than the overview needs. It is acceptable for a developer command and keeps the single entry point rule of spec 0008.

**Neutral**:
- The mapping table in spec 0008 marks the score and source rows `mapped (feature 12)`; `/sync` should mark them built.
- `OverviewReportFactory` composes `CoverReportFactory` instead of copying it, so the cover rules stay in one place.

## Rationale

The reasoning and the options are in [rationale.md](rationale.md).

## Follow-up

- [ ] Features 19, 20 and 22 pass `groups`, `sourceTotals`, `filters` and `flagCount` into `OverviewSection`; they should not change its layout code.
- [ ] No saved scan exists on this machine, so the real look in build step 4 waits for one saved sandbox scan. Until then the sample is the check by eye.
- [ ] Look at the thin page 1 and the page 2 gap on a real scan, and decide with feature 13 whether the empty areas should shrink before any report goes out.
- [ ] Feature 24 owns the final full report assembly (cover, overview, manuscript pages). `OverviewReportFactory` is the pattern for it.
- [ ] The logo, icons and wording are still stand ins (spec 0006 AC-10 and the legal check), so the overview cannot go to a customer yet.
