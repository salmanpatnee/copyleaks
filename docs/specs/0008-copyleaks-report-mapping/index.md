# 0008. Copyleaks to report mapping

**Date**: 2026-11-03
**Status**: In Progress

## Summary

This spec decides which Copyleaks field fills which spot in the Turnitin style layout, and builds the step that turns a saved scan into the data the report pages need. It reads only our own saved files, so a report can be rebuilt without a new scan. Spots that Copyleaks cannot fill honestly (match groups, the three source totals, integrity flags, most cover details) are left out for now and wait for Slice 3. Features 11, 12 and 13 then read one typed object and never open a Copyleaks file themselves.

## Requirements

**User stories**:
- As the developer, I want one written table of every report spot and the Copyleaks field behind it (or "left out"), so that the cover, overview and highlight features do not each guess.
- As a staff member, I want a finished scan turned into report data from our saved files alone, so that rebuilding a report never costs a new scan.
- As a staff member, I want the source list and the highlights to agree with each other and with the overall score, so that a customer can follow a margin badge to its row in the list.
- As the developer, I want a damaged or missing saved file to stop the build with a plain reason, so that a report never goes out with a silent gap.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: `ReportDataMapper::map(Scan)` takes a `completed` scan and returns one readonly `ReportData` built only from the saved `summary`, `result` and `text` files and the scan's submission. It makes no Copyleaks call, writes nothing to the database or disk and logs nothing. Mapping the same scan twice gives equal results. A scan in any other status throws `ReportRenderFailed::scanNotCompleted()` (reason `scan_not_completed`). Every reason code in this spec is a named factory on `ReportRenderFailed`, as its other reasons are, never a free string.
- **AC-2**: A new `ScanBundleReader` is the only code that opens the saved `summary` and `result` files for reports (the `text` file stays with `CrawledTextReader`, see Follow-up). It takes an artifact kind and a source ID, never a path. For each file it finds the `scan_artifacts` row, checks that the stored file exists, checks the SHA 256 of the stored `.gz` bytes against the row with `hash_equals`, unzips it and decodes the JSON. Failures throw `ReportRenderFailed` with a fixed reason and no text from the file: `summary_missing`, `result_missing` (no row or no file; this also covers a kept source with no result row), `checksum_mismatch`, `summary_unreadable`, `result_unreadable` (bad gzip or JSON). Nothing is built partially.
- **AC-3**: `ReportData::similarityPercent` is `results.score.aggregatedScore` of the saved summary, read with the existing `ScanSummary`, rounded to 2 decimals. A missing or non numeric value throws `summary_unreadable`. The mapper never reads `scans.similarity_percent`; a test compares the two as floats (the column is a `decimal:2` string).
- **AC-4**: The source list takes every item of `results.internet`, `database`, `batch` and `repositories`. Matched words per item is its `matchedWords`. A missing or non whole number throws `summary_unreadable` (a real source is never dropped silently). Items with 0 matched words are dropped, and dropped sources never have their result file opened. Each kept source gets `percent = matched words / scannedDocument.totalWords * 100`, capped at 100 and not rounded (`PercentFormat` does all display rounding, so a tiny share shows `<1%`, never `0%`). A `totalWords` that is missing, not a number or 0 throws `summary_unreadable`. The mapper reads every summary value through `ScanSummary`, which gains `resultItems()` (group plus item, in the group order internet, database, batch, repositories) and `totalWords(): ?int`. An item without a non empty string `id` stays listed with 0 ranges and no file lookup. An item whose `id` repeats keeps only its first appearance, as `ScanSummary::resultIds()` does.
- **AC-5**: Sources are ranked 1 to n with no gaps: most matched words first, ties broken by the order in the summary (the four groups in the order above, then list position). The same files always give the same ranks.
- **AC-6**: A source from `results.internet` gets the category `Internet`. A source from any other group gets no category (null), and its row is drawn without a pill. `SourceRow::$category` becomes nullable and the engine skips only the category pill when it is null. The number pill, the name and the divider stay where they are, so rows stay lined up. `SampleOverviewSection` must still type check.
- **AC-7**: A source name is the host of its `url` (kept as sent, so `www.` stays) when the url parses to a host. Otherwise the trimmed `title`. Otherwise `Untitled source`. Control characters are removed. No date, author or institution is added.
- **AC-8**: For each listed source the mapper reads its `result` file and collects the character ranges of the submitted text from `text.comparison.identical`, `minorChanges` and `relatedMeaning`, each at `suspected.chars.starts[]` and `suspected.chars.lengths[]` (the two arrays are paired by position). A pair is skipped when either value is not a whole number, the start is below 0 or the length is below 1. If the two arrays differ in length, the extra items are ignored. A result with no `text.comparison` gives no ranges and is not an error. The source stays in the list either way. A source's range count in the command is its number of valid pairs before merging, after the AC-15 trim. Result files are read one at a time and only the numbers are kept.
- **AC-9**: Ranges from all listed sources are merged into `HighlightRange(start, length, rank)` items sorted by start. Ranges that overlap (one starts before the other ends) become one range from the lowest start to the highest end. Ranges that only touch stay separate. The rank of a merged range is the lowest rank among the source ranges that cover its first character. Offsets are Copyleaks character offsets, the same unit as `ManuscriptPages::lineBoxes()` in spec 0007. Feature 13 calls `lineBoxes(start, length)` for each range, draws one `Highlight` per box with `highlightStyle`, and puts one `MarginBadge` with the range's rank at the first box (ranks start at 1 and `SourceColor` repeats every 4, so they agree).
- **AC-10**: `ReportData` carries one `highlightStyle`, set to `MatchGroupStyle::NotCitedOrQuoted` for every range until feature 19. It has no field for match groups, the three source totals, integrity flags, the filtered list or AI, so those spots are left out and nothing is guessed. Feature 12 decides how the overview page lays out around the missing blocks (see Follow-up).
- **AC-11**: `ReportData::details` is a `DocumentDetails` with `author`, `title`, `assignment` and `fileName` from the submission (`author`, `title`, `assignment`, `original_name`), `submissionId` from `scans.copyleaks_scan_id` (also the header and footer ID until Slice 3), and `words` from `scannedDocument.totalWords`. `submissionDate`, `downloadDate`, `fileSize`, `pages` and `characters` are null (left out) until feature 21. A blank or whitespace only `author`, `title`, `assignment` or `original_name` also becomes null, so that cover line is left out.
- **AC-12**: `php artisan report:mapping {public_id}` maps the newest `completed` scan of that submission and prints: the score, total words, source count, one line per source (rank, category, name, percent, matched words, range count), the merged range count, the largest merged range length, and a warning for every source that has matched words but 0 ranges (or no `id`), one line `Trimmed ranges: {dropped} dropped, {clipped} clipped` and one line `Largest range end: {n}` against `Text length: {n}` (AC-15). A source whose ranges were all dropped prints `0 ranges ({n} dropped past text end)` instead of the plain 0 ranges warning. It prints no manuscript text, token or secret. It writes nothing. A stop reason prints as the reason code and exits with a failure code. An unknown `public_id` or no completed scan prints `no_completed_scan`, as `report:manuscript` does.
- **AC-13**: Nothing in the mapping folder (which now also holds `ScanBundleReader`) calls `Log::`, `ScanClient`, `Http::` or writes to the database (an architecture test, the same as the folder rule in spec 0007). The mapping never dispatches `SubmitScanJob` or calls `SubmitScan`.
- **AC-14**: Proof on fixtures: Pest tests use small made up summary and result files that follow the documented shape. A test completes a fake scan, takes a `FakeScanClient` snapshot, maps it and shows no new client call. A test with 300 sources keeps the peak memory under 128 MB, and a second test maps one result file of about 10 MB with each decoded array freed before the next and also stays under 128 MB.
- **AC-15**: Ranges that run past the end of the text are trimmed before merging. After the status and summary checks and before the source loop, `ReportDataMapper` reads the saved crawled text once through `CrawledTextReader` and takes its length in UTF-16 code units, the same unit as the offsets (spec 0007). It reads the text even when no source has an id, so a scan with no text always fails the same way. A missing or unreadable text file stops the build with the existing `crawled_text_missing` reason and nothing is built partially. An empty text is allowed (length 0): every range is then dropped and the manuscript step owns whether such a text is usable. The text file has no checksum check yet (see Follow-up). For each valid pair of a source (after the AC-8 checks, in this order): a start at or past the text length is dropped. Otherwise, when `length > textLength - start`, the pair is clipped to `textLength - start` (never below 1). A pair that ends exactly at the text end is left as it is. The merge (AC-9), the ranks and the one badge per merged range do not change. Each source keeps its own counts: `SourceStat` gains `droppedRanges` and `clippedRanges`, and `ReportData` exposes the two totals as methods. A source's range count (AC-8, AC-12) is the number of pairs kept after the trim. `ReportData` also carries `largestRangeEnd`, the largest `start + length` seen before the trim (a number only). The mapper keeps only these numbers, never the text.

## Decision

**Chosen option**: Option 1: A shared `ScanBundleReader` and one `ReportDataMapper` that returns typed `ReportData`.

Read the saved files in one place, map them once into a plain readonly object, and leave every spot Copyleaks cannot fill honestly out of that object. Features 11, 12 and 13 only read `ReportData`.

**Implementation skills**: none. The project has no installed skill for this area (`.agents/skills/` holds only `shadcn-vue`, which is UI only).

## Feature design

**Data model sketch**:

No migration and no new table. Everything comes from tables of spec 0002 and the files of spec 0005.

| Source | What the mapper reads |
| --- | --- |
| `scan_artifacts` (kind `summary`, source_id `''`) | path, `sha256` of the saved summary |
| `scan_artifacts` (kind `result`, source_id = result ID) | path, `sha256` of each saved result file |
| `scan_artifacts` (kind `text`, source_id `''`) | the saved crawled text, read only for its length (AC-15). No checksum check yet |
| `scans` | `status`, `copyleaks_scan_id`, `submission_id` |
| `submissions` | `author`, `title`, `assignment`, `original_name` |

New classes, all small and readonly where they hold data:

| Class | Folder | Owns |
| --- | --- | --- |
| `ScanBundleReader` | `app/Services/Reports/Mapping/` | Opens one saved summary or result file: row, existence, SHA 256, gunzip, JSON (AC-2). Lives with the report code because it throws report exceptions |
| `ReportDataMapper` | `app/Services/Reports/Mapping/` | The only entry point. Checks status, calls the helpers, builds `ReportData` (AC-1) |
| `ReportData`, `HighlightRange` | `app/Services/Reports/Mapping/` | The result: score, details, ranked `SourceRow` list, merged ranges, highlight style |
| `SourceListBuilder` | `app/Services/Reports/Mapping/` | Summary items to ranked sources with percent and category (AC-4 to AC-6) |
| `SourceNamer` | `app/Services/Reports/Mapping/` | Host, title or `Untitled source` (AC-7) |
| `MatchRangeReader` | `app/Services/Reports/Mapping/` | One result file to a list of start and length pairs (AC-8) |
| `RangeMerger` | `app/Services/Reports/Mapping/` | Overlap merge and best rank (AC-9) |
| `DetailsMapper` | `app/Services/Reports/Mapping/` | `DocumentDetails` from the scan and submission (AC-11) |
| `RangeTrimmer` | `app/Services/Reports/Mapping/` | Drops and clips the pairs of one source against the text length and returns both counts (AC-15). Pure, no file access |
| `MapReportData` command | `app/Console/Commands/` | `report:mapping` (AC-12) |

Existing code that changes: `SourceRow::$category` becomes `?string` and the source row drawing leaves out the pill when it is null (engine, spec 0006).

**The mapping table** (every spot in `docs/turnitin-report-design.md`, the Copyleaks field behind it, and who builds the page):

| Spot in the layout | Copyleaks field or our field | Status in this slice |
| --- | --- | --- |
| Cover: author | `submissions.author` | mapped (feature 11) |
| Cover: document title | `submissions.title` | mapped (feature 11) |
| Cover: assignment or class | `submissions.assignment` | mapped (feature 11) |
| Cover: Submission ID | `scans.copyleaks_scan_id` | mapped, replaced by our own ID in feature 21 |
| Cover: Submission Date, Download Date | none | left out, feature 21 |
| Cover: File Name | `submissions.original_name` | mapped (feature 11) |
| Cover: File Size | none | left out, feature 21 |
| Cover: stats Pages and Characters | none | left out, feature 21 |
| Cover: stats Words | `scannedDocument.totalWords` of the summary | mapped (feature 11) |
| Header and footer: Submission ID | `scans.copyleaks_scan_id` | mapped, replaced in feature 21 |
| Header and footer: page label, logo | the report engine | not mapping (spec 0006) |
| Overview: `N% Overall Similarity` | `results.score.aggregatedScore` | mapped (feature 12) |
| Overview: Filtered from the Report | none (scans run with no exclusions, spec 0004) | left out |
| Overview: Match Groups (four rows) | none (Copyleaks has identical, minor changes, related meaning only) | left out, feature 19 |
| Overview: Top Sources summary (Internet, Publications, Student Papers) | none yet | left out, feature 20 |
| Overview: Integrity Flags count and info box | `notifications.alerts[]` exist but what counts as a flag is open | left out, feature 22 |
| Source row: rank number and color | position after sorting by matched words | mapped (feature 12) |
| Source row: category pill | group `internet` gives `Internet`, other groups give none | mapped for Internet, rest left out, feature 20 |
| Source row: name | host of `url`, else `title`, else `Untitled source` | mapped (feature 12) |
| Source row: percent | `matchedWords` / `scannedDocument.totalWords`, unrounded | mapped (feature 12) |
| Manuscript pages: highlight rectangles | `text.comparison.*.suspected.chars` of each result file | mapped (feature 13) |
| Manuscript pages: margin badge circle (group color and icon) | one fixed style, `NotCitedOrQuoted` | mapped, real group in feature 19 |
| Manuscript pages: margin badge number | merged range rank | mapped (feature 13) |
| AI Writing report: all spots | `Ai` value and AI result | left out, feature 23 |

**State transitions**: none. The mapper reads a `completed` scan and changes nothing.

**API surface**:

| Entry | Kind | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `ReportDataMapper::map(Scan)` | PHP method | a `completed` `Scan` | `ReportData` | none (internal service) | `ReportRenderFailed` with a reason code (AC-1, AC-2, AC-3, AC-4, AC-15 `crawled_text_missing`) |
| `ScanBundleReader::summary(Scan)` and `::result(Scan, string)` | PHP methods | scan, result ID | decoded array | none | `ReportRenderFailed` (AC-2) |
| `php artisan report:mapping {public_id}` | command | submission `public_id` | printed summary | local only | reason code and a failure exit code |

No web route and no new queue job.

**Value sourcing**:

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Map | `similarityPercent` | `results.score.aggregatedScore` in the saved summary (`ScanSummary`) |
| Map | Source list items | `results.internet`, `database`, `batch`, `repositories` in the saved summary |
| Map | Matched words of a source | item `matchedWords` through `ScanSummary::resultItems()` (field name from the Copyleaks docs, to confirm in build step 7) |
| Map | Source percent | matched words / `ScanSummary::totalWords()`, capped at 100, not rounded |
| Map | Source rank | position after sorting by matched words, ties by summary order |
| Map | Source category | the group the item sits in (`internet` gives `Internet`) |
| Map | Source name | item `url` host, else `title`, else the fixed text `Untitled source` |
| Map | Highlight ranges | `text.comparison.{identical,minorChanges,relatedMeaning}.suspected.chars.starts` and `.lengths` in each saved result file, keyed by the item `id` (= `scan_artifacts.source_id`) |
| Map | Text length for the trim | `CrawledText::length()` of the saved `text` artifact through `CrawledTextReader` (UTF-16 units), AC-15 |
| Map | Dropped and clipped counts | per source from `RangeTrimmer`, kept on `SourceStat`, totals on `ReportData` |
| Map | Largest range end | the largest `start + length` seen before the trim, on `ReportData` |
| Map | Badge rank | lowest rank among the source ranges covering the merged range's first character |
| Map | Highlight style | fixed `MatchGroupStyle::NotCitedOrQuoted` (decided in this spec, replaced by feature 19) |
| Map | Document details | the submission columns and `copyleaks_scan_id` named in AC-11 |
| Map | Total words | `ScanSummary::totalWords()` (`scannedDocument.totalWords`) |
| Command | Which scan to map | newest `completed` scan of the submission with the given `public_id` (as `report:manuscript` does) |

**Key invariants**:
- The mapper never calls Copyleaks, never starts a scan and never writes. Raw files are read, not changed.
- A source is in the list only if it has matched words, and a badge rank always exists in the list (ranks come from the same sort).
- Source ranks run 1 to n with no gaps and do not change between rebuilds of the same files.
- A damaged or missing file stops the whole mapping. No partial `ReportData` exists.
- Manuscript text never appears in an exception message, a log or the command output. Only numbers, source names and hosts do.
- Offsets are never converted: Copyleaks offsets go straight to `lineBoxes()` (spec 0007). They are only trimmed to the text length (AC-15), never moved.
- No range handed to `lineBoxes()` starts past the text end or runs past it.

**Security model**: Internal tool, no staff rules yet (Slice 1). Not a web feature, so there is no new public surface. The saved files hold manuscript text, which is personal data of authors, and stay on the private disk. The mapper keeps only numbers from result files and never prints text. Source names and url hosts are third party data and are escaped by the report engine when drawn (spec 0006).

**Configuration required**: none. No new `.env` key and no new config entry.

**Critical test scenarios** (each maps to an acceptance criterion above):
- Happy path: a completed fake scan with two sources maps to the right score, ranked rows, percents, names, merged ranges and details, and a second run gives equal data, verifies **AC-1**, **AC-3**, **AC-4**, **AC-5**, **AC-11**
- Happy path: a `database` source and an `internet` source in one summary give a pill only on the Internet row, verifies **AC-6**
- Edge case: a url with `www.`, a url with no host, no url but a title, and neither give the four expected names, verifies **AC-7**
- Edge case: two sources with equal matched words keep summary order, and a source with 0 matched words is dropped, verifies **AC-4**, **AC-5**
- Edge case: overlapping ranges from two sources become one range with the lower rank, touching ranges stay two, ranges are sorted, verifies **AC-9**
- Edge case: odd range data (negative start, zero length, text instead of numbers, arrays of different length, no `text.comparison`) is skipped or ignored and the source stays listed, verifies **AC-8**
- Failure case: a missing summary row, a missing result row, a missing file on disk, a wrong checksum, bad gzip and bad JSON each throw the matching reason and build nothing, verifies **AC-2**
- Failure case: `totalWords` of 0, a missing `aggregatedScore` and a scan in `fetching` stop with `summary_unreadable` and `scan_not_completed`, verifies **AC-1**, **AC-3**, **AC-4**
- Edge case: a range starting past the text end is dropped, one running past the end is clipped to `textLength - start`, one inside is untouched, an emoji counts as two units, and both counts are right, verifies **AC-15**
- Edge case: a range ending exactly at the text end is untouched, and an empty saved text drops every range without an error, verifies **AC-15**
- Failure case: a scan with no saved text stops with `crawled_text_missing` and builds nothing, also when no source has an id, verifies **AC-15**
- Edge case: a source whose ranges are all dropped prints `0 ranges (n dropped past text end)`, verifies **AC-12**, **AC-15**
- Command: `report:mapping` prints `Trimmed ranges: n dropped, n clipped`, verifies **AC-12**, **AC-15**
- Integrity: after a `FakeScanClient` snapshot, mapping makes no client call, and no class in the folder uses `Log::`, `ScanClient`, `Http::` or a database write, verifies **AC-13**, **AC-14**
- Performance: 300 sources of fixture data map with a peak under 128 MB, verifies **AC-14**
- Command: `report:mapping` prints the lines and the zero range warning and no text, and a stop reason gives the failure exit code, verifies **AC-12**

## Build plan

Approach: Tracer Bullet. Build the thinnest real thread first (one fixture scan to a score and details), then add one strand at a time. No migration. Each step carries its own Pest tests.

1. Thin thread: `ScanBundleReader` for the summary, `ReportData`, `ReportDataMapper` and `DetailsMapper` returning the score and details for a completed fixture scan, plus `ScanSummary::resultItems()` and `totalWords()` and a fixture helper that writes the summary file and artifact row, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-11**
2. Source list: `SourceListBuilder` and `SourceNamer` give ranked rows with percent, category and name. Make `SourceRow::$category` nullable and leave out the pill in the source row drawing, satisfies **AC-4**, **AC-5**, **AC-6**, **AC-7**
3. Highlight ranges: `MatchRangeReader` and `RangeMerger` read the result files one at a time and produce merged ranges with the best rank, and `ReportData` gets the fixed highlight style, satisfies **AC-8**, **AC-9**, **AC-10**
4. Thicken the failures: every reader reason (missing row, missing file, checksum, bad gzip, bad JSON), the status check and the `totalWords` rules, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**
5. The `report:mapping` command, satisfies **AC-12**
6. Pest tests for every scenario above, the architecture test, the `FakeScanClient` snapshot proof and the 300 source memory run, satisfies **AC-13**, **AC-14**
7. One look at a real saved sandbox scan, no new scan and no credits: run `report:mapping` on one of the four scans from spec 0007 and record in this spec which field names the real summary and result file use (`matchedWords`, `url`, `title`, `text.comparison.*.suspected.chars`), then fix the names if they differ, satisfies **AC-4**, **AC-8**
8. Trim out of range offsets: `RangeTrimmer`, `SourceStat` gets per source counts, `ReportData` gets the totals and `largestRangeEnd`, the mapper reads the text length through `CrawledTextReader` before the source loop and trims each source before merging, `report:mapping` prints the new lines, the mapper fixture helper writes a default text artifact long enough for every fixture range (so the existing mapper tests keep passing), and the tests above, satisfies **AC-12**, **AC-15**. Check on saved scan `01m6r5zeknm76rjnwzhvtss60e` (text 3287 units): the 10 merged ranges become 1 range starting at 80 with length 3207, and the dropped and clipped counts are printed

## Consequences

**Positive**:
- Features 11, 12 and 13 read one typed object. The unzip, checksum and field name code lives in one place, which closes the open question in spec 0005.
- Nothing is invented. Every spot is either filled from a named field or left out on purpose, and the table says which.
- Rebuilds are cheap and repeatable: same files, same data, no scan.
- The margin badge number always has a matching row in the source list.

**Negative / tradeoffs**:
- Without match groups, the overview and the pages look thinner than the Turnitin design until Slice 3. Every highlight is coral and every badge carries the "Not Cited or Quoted" icon, which says something the data does not prove. We accept this until feature 19, and the sample PDFs and any customer report must wait for it (the engine already blocks customer use, spec 0006).
- The field names come from the Copyleaks docs, not from a real result. The sandbox returns one fixed sample result for every scan (spec 0007), so match positions cannot be proven against our own text yet. Build step 7 checks the names, and the positions stay an open follow-up.
- If a real summary names the matched words field differently, the mapping stops with `summary_unreadable` instead of guessing. Build step 7 catches this on the first real run.
- A source whose name is only a host (no title) tells staff less than a title would. Chosen to match the design's Internet rows.
- Merging overlaps hides the second source's badge on shared text. The first word's best rank wins.
- Percents are per source and rounded, so they do not add up to the overall score. The same is true in the Turnitin design (overlap is counted per source).
- Result files are decoded one at a time, but a 10 MB file still uses a lot of memory while decoded. The 300 source test and the Hostinger limit from spec 0005 (`memory_limit` 256 MB) are the guard.
- Mapping now also needs the saved text file, so a scan with no saved text stops at the mapper (`crawled_text_missing`) instead of later at the manuscript step. Accepted: such a scan cannot make a report anyway.
- The Mapping folder now depends on `CrawledTextReader` in the Manuscript folder. AC-13 still holds (that reader only reads the database and the disk, with no `Log::`, `Http::` or write).
- A range that Copyleaks reports past the end of the text is cut or dropped, not repaired. A dropped range loses its highlight, and we do not know why Copyleaks sends them (8 of 38 sources on the first real scan). The two counts keep it visible.
- The mapper decodes every result file again on every rebuild. Fine for a staff tool, and a cache would be a later change.

**Neutral**:
- `SourceRow::$category` becomes nullable, a small change to spec 0006's engine.
- `ReportRenderFailed` gains named factories for the mapping reason codes (`scan_not_completed`, `summary_missing`, `result_missing`, `checksum_mismatch`, `summary_unreadable`, `result_unreadable`).
- A new folder `app/Services/Reports/Mapping/` needs its own `AGENTS.md` once built (the sync step owns that file).

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Follow-up

- [x] Build step 8 (AC-15): trim ranges to the text length. Found on the first real scan (`01m6r5zeknm76rjnwzhvtss60e`): 8 of 38 sources gave offsets up to 10,324 in a 3,287 unit text, so 9 of 10 merged ranges were unplaced in the similarity report (spec 0011). Ask Copyleaks why some sources report positions past the end.
- [ ] Clipped ranges are suspect too: if Copyleaks counted those 8 sources' offsets in a different text (for example the original file), the part inside our text is also wrong. Use `Largest range end` against `Text length` on the next real scans to see how often this happens.
- [ ] Spec 0011: notes and expected numbers that mention `Unplaced ranges: 9` should expect 0 after this build. No code change in 0011.
- [ ] Optional build choices, not part of the rule: a length only path for the text (no UTF-16 array), and a large text in the AC-14 memory test.
- [ ] Build step 7: confirm the real summary and result field names on a saved sandbox scan, and record them here. Until then the names in AC-4 and AC-8 are the documented ones.
- [ ] Match positions are still unproven against our own text for the same reason as spec 0007 AC-10 (the sandbox returns the same fixed result). One real scan with a match is owed, and spending credits on it is the owner's call.
- [ ] Feature 12 must decide the overview page layout when Filtered from the Report, Match Groups, the Top Sources summary and Integrity Flags are all absent (does the source list start higher up, and does the repeat of the summary block on the second page still exist). The design doc only describes the full page.
- [ ] Feature 19 replaces `highlightStyle` with a style per match, feature 20 fills the category for non internet groups and the Top Sources summary, feature 21 fills the cover dates, size, pages and characters (the `submissions` table already holds `file_size`, `page_count`, `word_count` and `character_count` from the upload, so that may be cheap), and feature 22 adds the flag count. Each of them updates the mapping table in this spec.
- [ ] Add `app/Services/Reports/Mapping/AGENTS.md` and a pointer line in root `AGENTS.md` once built (the sync step owns it).
- [ ] Move `CrawledTextReader` onto `ScanBundleReader` so the `text` file also gets the checksum check (today it opens the file without one).
- [ ] Decide later whether a `ScanBundleReader` result cache is worth it, if report rebuilds get slow on Hostinger.
