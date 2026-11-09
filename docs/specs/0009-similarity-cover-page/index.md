# 0009. Similarity cover page

**Date**: 2026-11-05
**Status**: In Progress

## Summary

This spec turns the throwaway sample cover into the real cover page of the similarity report. It draws the author, title, assignment, document details and the word count box at the positions in the design spec, using only what the mapping already gives us (spec 0008). Any detail we have no value for is left out cleanly, never blank and never guessed. A small command, `report:cover`, builds a cover only PDF from a saved scan, so you can look at a real cover without a new Copyleaks scan.

## Requirements

**User stories**:
- As a staff member, I want the cover to show the author, title, assignment, file name and word count from the form and Copyleaks, so that the customer sees the right document on page one.
- As a staff member, I want a spot with no data to disappear without leaving a gap or a made up value, so that the cover never looks broken or wrong.
- As the developer, I want one real cover section that features 12, 21 and 24 reuse, so that nobody copies the sample page again.
- As the developer, I want to build a cover from a saved scan with one command, so that I can check the real look without spending a scan.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: A new `CoverSection` (a `ReportSection`, label `Cover Page`, `pageCount()` 1) draws one Letter page from a `DocumentDetails` at the design positions: author (36, 336, Lexend Deca SemiBold 20), title (36, 369, Lexend Deca 17), clipboard icon (36, 397) and assignment (56, 397.4, Noto Sans 8 muted), a divider at 425, the heading `Document Details` (36, 443.3), the field pairs (label muted, value 14 pt below it) from y 472.5 every 36 pt in this order: Submission ID, Submission Date, Download Date, File Name, File Size, and the stats box at x 403. The shared strip reads `Page 1 of 1 - Cover Page` when the cover is built alone. It touches mPDF only through `SectionCanvas`. The assignment is drawn at 8 pt (a new `ReportTheme::SIZE_ASSIGNMENT` and `.assignment` class, because the sample's `body muted` class is 7 pt). Every cover text is drawn as one line per `nowrap` div (the title as one div per fitted line), so mPDF never re-wraps a line the fitter already fitted.
- **AC-2**: A value that is null or blank after trimming leaves its own spot out: no label, no placeholder, no blank line. The field pairs close up (the next present pair moves into the freed 36 pt slot, as the sample does). A missing author, title or assignment leaves only that line out, and every other spot stays where it is. With the data the mapping gives today (no dates, no file size) the list shows Submission ID and File Name only.
- **AC-3**: The stats box lists only the counts that are not null and not 0 (a 0 is left out like null, so `0 Words` never shows), in the order Pages, Words, Characters, written with thousands separators and a singular label for 1 (`1 Word`). Its height is 34 pt for one line, plus 22 pt for each further line (78 pt for three, as in the design). It sits at x 403 with its top at 470 plus the title shift of AC-5, and its lines start 14.5 pt below its top and are 22 pt apart. `ReportParts::statsBox` takes the top as an input (default 470). With no counts, the box is left out. With today's data it shows one line, `N Words`.
- **AC-4**: Author and assignment are always one line. Before any measuring, every run of whitespace (spaces, tabs, line breaks) in a text becomes one space. If the text is wider than the space (author: 540 pt from x 36; assignment: 520 pt from x 56), it is cut at a character with `…` so the whole line fits, measured in the font and size that draw it (author Lexend Deca SemiBold 20, assignment Noto Sans 8) against 98% of the space, as a safety margin for fallback fonts. Submission ID and File Name values are cut the same way at 340 pt (the stats box starts at 403), in Noto Sans SemiBold 7. A text that already fits is never changed.
- **AC-5**: The title (whitespace collapsed as in AC-4, measured in Lexend Deca regular 17 against 98% of the width) wraps at word breaks onto at most 2 lines, 540 pt wide, 20 pt line height. A single word wider than the line is broken at a character. If the text needs more than 2 lines, the second line is cut with `…`. When the title uses 2 lines, every spot below it (clipboard and assignment, divider, `Document Details`, the field pairs, the stats box) moves down 20 pt. With the longest allowed content (2 title lines, five field pairs, three stats lines) nothing passes y 700, well clear of the footer at 748.
- **AC-6**: Every value from the data is drawn as plain text. Markup in it (for example `<b>` or an image tag) is shown as text, never run, and no web request is made. The sample's assignment `Sample class <b>one</b>` proves it.
- **AC-7**: A new `CoverReportFactory::make(ReportData): ReportDocument` returns a `ReportDocument` of kind `Similarity` with one `CoverSection` built from `ReportData::details`, and the header ID set to `details.submissionId` (the Copyleaks scan ID until feature 21). If `submissionId` is null or blank the factory throws `ReportRenderFailed` with the new reason `submission_id_missing` (a named factory like the others), so the strip never shows a `Submission ID` label with no value. A completed scan always has the ID (spec 0004), so this should never fire. The factory is pure: no database, no Copyleaks call, no disk write, no log.
- **AC-8**: `php artisan report:cover {public_id}` maps the newest `completed` scan of that submission (`latest('id')`, the same pick as `report:mapping`) with `ReportDataMapper`, builds the cover through the factory and the existing `ReportRenderer`, and writes it to `reports/samples/cover-{Ymd-His}.pdf` on the `local` disk. It prints the full path, the size, the page count and one `Left out:` line naming each cover spot that had no value (or `none`), using these exact names: Author, Title, Assignment, Submission ID, Submission Date, Download Date, File Name, File Size, Pages, Words, Characters. It keeps only the newest 5 `cover-*.pdf` files and never touches `sample-*.pdf` files (and `report:sample` never touches cover files). A stop reason from the mapper or the renderer prints as `{reason}: {message}` and exits with a failure code. An unknown `public_id`, or no completed scan, prints `no_completed_scan`. It prints no author, title, assignment or manuscript text.
- **AC-9**: `report:sample` uses the real `CoverSection` and `SampleCoverSection` is deleted. The sample cover still shows all five fields and all three counts. `MpdfReportRenderer::VERSION` is bumped because the look of the cover changed.
- **AC-10**: Nothing in the cover folder calls `Log::`, `ScanClient`, `Http::`, `SubmitScan`, or writes to the database, and `report:cover` never starts a scan (an architecture test, the same rule as spec 0008 AC-13).

## Decision

**Chosen option**: Option 1: Promote the sample cover into a real `CoverSection` with a small factory and a `report:cover` command.

Move the cover into its own folder as production code, add the fitting rules (cut, wrap, shift, shrink) there, and prove it on a real saved scan through a command that never calls Copyleaks.

**Implementation skills**: none. The project has no installed skill for this area (`.agents/skills/` holds only `shadcn-vue`, which is UI only).

## Feature design

**Data model sketch**:

No migration and no new table. The cover reads only the typed `DocumentDetails` that spec 0008 already builds.

| Class | Folder | Owns |
| --- | --- | --- |
| `CoverSection` | `app/Services/Reports/Cover/` | Draws the one cover page from `DocumentDetails` (AC-1 to AC-6). Replaces `Sample/SampleCoverSection` |
| `CoverTextFitter` | `app/Services/Reports/Cover/` | Pure fitting rules: collapse whitespace, cut one line with `…`, wrap the title to 2 lines (AC-4, AC-5). Each call takes a width function `fn(string): float` for its font (the section passes the canvas `textWidth` with the family and weight that draw it), because the `TextMeasurer` interface has no font argument and `SourceNameFitter` is Noto only |
| `CoverReportFactory` | `app/Services/Reports/Cover/` | `ReportData` to a one section `ReportDocument` (AC-7) |
| `BuildCoverReport` | `app/Console/Commands/` | The `report:cover` command (AC-8) |
| `SampleFilePruner` | `app/Services/Reports/` | Keeps the newest N files that match a name pattern in `reports/samples`. Used by both commands (AC-8) |

Changes to existing code: `ReportParts::statsBox` gets the shrinking height and a top input (AC-3); `ReportTheme` gets `SIZE_ASSIGNMENT` (8) and the `.assignment` and `nowrap` cover classes (AC-1); `SampleReportFactory` builds the real `CoverSection`; `BuildSampleReport` uses `SampleFilePruner`; `MpdfReportRenderer::VERSION` is bumped. No change to `TextMeasurer`: `SectionCanvas::textWidth` already takes a font family (Lexend Deca is set up as regular Medium and bold SemiBold), so the section measures each text the same way it is drawn and hands the fitter a width function.

**State transitions**: none. The cover is a pure drawing of saved data.

**API surface**:

| Entry | Kind | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `CoverReportFactory::make` | PHP method | `ReportData` (req) | `ReportDocument` | none (internal) | none |
| `ReportRenderer::render` | existing | the document, a path on the `local` disk | `BuiltReport` | none | `ReportRenderFailed` with a reason code |
| `php artisan report:cover {public_id}` | command | `public_id` (req) | file path, size, pages, `Left out:` line | none (staff on the server) | `no_completed_scan`, mapper reasons (`summary_missing`, `checksum_mismatch` and the rest of spec 0008), renderer reasons |

**Value sourcing** (every value the cover shows and where it comes from):

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Draw cover | Author, title, assignment | `submissions.author`, `title`, `assignment` through `DocumentDetails` (spec 0008 AC-11) |
| Draw cover | Submission ID | `scans.copyleaks_scan_id` through `DocumentDetails.submissionId` (spec 0008 AC-11); a completed scan always has it (spec 0004) |
| Draw cover | Submission Date, Download Date, File Size | `DocumentDetails`, null today, left out (feature 21 fills them) |
| Draw cover | File Name | `submissions.original_name` through `DocumentDetails.fileName` |
| Draw cover | Words | `scannedDocument.totalWords` of the saved summary through `DocumentDetails.words` |
| Draw cover | Pages, Characters | `DocumentDetails`, null today, left out (feature 21) |
| Draw cover | Positions, sizes, colors, 20 pt title line, box height | The design spec (`docs/turnitin-report-design.md`) and `ReportTheme`; box height rule decided in AC-3 |
| Draw strip | `Page 1 of 1 - Cover Page` | `ReportDocument::totalPages()` and `CoverSection::label()` (spec 0006) |
| Draw strip | Header and footer ID | `CoverReportFactory` sets it from `details.submissionId`; null or blank throws `submission_id_missing` (AC-7) |
| Draw cover | Title shift | 20 pt per extra title line, from the fitter's line count (AC-5) |
| `report:cover` | Output file name | `date('Ymd-His')` at the time of the run |
| `report:cover` | `Left out:` list | The null or blank fields of `DocumentDetails` (labels only) |

**Key invariants**:
- The cover is exactly 1 page and never writes more than its `pageCount()` (the engine fails with `overflow` otherwise).
- No blank label, placeholder or guessed value is ever drawn.
- Everything is drawn as escaped plain text from local fonts and icons only.
- The cover never changes saved data and never calls Copyleaks.

**Security model**: An internal tool run by staff or on the server. The cover holds the author, title and assignment of a manuscript, so the command prints none of them and no manuscript text, and the code never logs them. Cover files are written to the private `local` disk, never a public folder. No compliance scope beyond the project rules in `AGENTS.md`.

**Configuration required**: none. No new `.env` value (the sample folder and keep count reuse `config('report.sample_keep')`).

**Critical test scenarios** (each maps to an acceptance criterion; tests are only written when you ask for them):
- Happy path: a saved scan with today's data builds a one page cover with author, title, assignment, Submission ID, File Name and a one line stats box, verifies **AC-1**, **AC-3**, **AC-7**, **AC-8**
- Left out: dates, size, pages and characters are null and nothing blank shows; a blank assignment drops the clipboard and the line, verifies **AC-2**
- Long text: a 300 character title wraps to 2 lines and is cut, everything below including the stats box moves 20 pt; a long author and File Name end in `…`; a title with line breaks and tabs is measured as plain spaces, verifies **AC-4**, **AC-5**
- Odd data: a count of 0 is left out; a null Submission ID stops the build with `submission_id_missing`; a right to left or Chinese author is cut with the safety margin and not wrapped, verifies **AC-3**, **AC-4**, **AC-7**
- Escaping: markup in the assignment shows as text, verifies **AC-6**
- Failure: a scan that is not `completed`, a damaged saved file or an unknown `public_id` prints a reason and leaves no file behind, verifies **AC-8**
- Housekeeping: a sixth run deletes the oldest cover and leaves every `sample-*.pdf`, verifies **AC-8**
- Sample and safety: `report:sample` still builds with the real cover; the folder has no `Log::`, client or database write, verifies **AC-9**, **AC-10**

## Build plan

Tracer Bullet: one thin real thread first (a cover from a saved scan through the command), then thicken it one strand at a time. Tests are not in this plan (the project rule is to write them only on request); `/check verify` then `/test similarity cover page` are the closing steps at the Beta tier.

1. [x] Thin thread: create `Cover/CoverSection` by moving the sample cover as is, `CoverReportFactory`, and a first `report:cover` that maps, renders and prints the path; point `SampleReportFactory` at the real section and delete `SampleCoverSection`, satisfies **AC-1**, **AC-7**, **AC-8**, **AC-9**
2. [x] Thicken the left out rules: null, blank and 0 handling for each spot, closing up the field pairs, the shrinking and movable stats box in `ReportParts::statsBox`, the 8 pt assignment class, satisfies **AC-1**, **AC-2**, **AC-3**
3. [x] Thicken the fitting: `CoverTextFitter` with whitespace collapse and the 98% margin, one line cut for author, assignment and the field values, 2 line title drawn one `nowrap` div per line with the 20 pt shift of everything below, escaping check, satisfies **AC-4**, **AC-5**, **AC-6**
4. [x] Finish the command and safety (real scan looked at 2026-11-09, folder rule checked by search, architecture test left for `/test`): `submission_id_missing` in the factory, `Left out:` line with the fixed names, failure codes, `SampleFilePruner` shared by both commands (keep 5 each), bump `MpdfReportRenderer::VERSION`, architecture rule for the folder, then look at the sample cover and at one real saved scan if one exists, satisfies **AC-8**, **AC-9**, **AC-10**

## Consequences

**Positive**:
- Features 12, 21 and 24 get a real cover section to reuse, and the sample no longer carries production layout.
- A real cover can be checked by eye from a saved scan, so no scan is spent on layout work.
- Long or odd text can no longer overlap the divider, the next field or the stats box.

**Negative / tradeoffs**:
- With today's data the cover is thin: two field pairs and a one line box. It looks unfinished until feature 21 adds dates, size, pages and characters.
- The title wrap and the 20 pt shift add one rule that the design spec does not describe, because Turnitin's samples never show a wrapped title.
- The cover still uses the stand in logo and the callout wording from the samples (AC-10 of spec 0006 and the legal check are open), so no report may go to a customer yet.
- Names in scripts our fonts lack (Arabic, Urdu, Chinese) fall back to another font and are not laid out right to left, so they may look wrong. The 98% margin only stops them overrunning the line. This is a known limit for now.
- Two `report:cover` runs in the same second write the same file name, so the second replaces the first. This is harmless.
- `report:cover` reads the full mapping (including result files) only to get the details, which is more work than the cover needs. It is acceptable for a developer command and keeps the single entry point rule of spec 0008.

**Neutral**:
- `SampleFilePruner` is a small refactor of the prune code that lives in `BuildSampleReport` today.
- The mapping table in spec 0008 says `mapped (feature 11)` for these spots; `/sync` should mark them built.

## Rationale

The reasoning and the options are in [rationale.md](rationale.md).

## Follow-up

- [ ] Feature 21 fills the cover dates, file size, pages and characters (the `submissions` table already holds the size and counts from the upload) and the cover then shows them with no change to `CoverSection`.
- [ ] No saved scan exists on this machine, so the real look in build step 4 waits for one saved sandbox scan. Until then the sample cover is the check by eye.
- [ ] The title wrap rule (2 lines, 20 pt shift) is our own, since the design spec has no sample of a wrapped title. Confirm it looks right on a real long title.
- [ ] Feature 12 should read the same `CoverReportFactory` pattern when it joins the cover with the overview into one document, and feature 24 owns the final full report assembly.
- [ ] The logo, icons and wording are still stand ins (spec 0006 AC-10 and the legal check), so the cover cannot go to a customer yet.
