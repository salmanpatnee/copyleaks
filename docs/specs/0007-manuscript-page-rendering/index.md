# 0007. Manuscript page rendering (word positions on the original PDF pages, text pages as the safe fallback)

**Date**: 2026-11-02
**Status**: Accepted

## Summary

This feature turns a scanned PDF manuscript into report pages and works out where each word sits on those pages, so feature 13 can draw highlights on the right words. We read the words and their positions from the PDF in pure PHP (`smalot/pdfparser`, already installed) and match them to the text Copyleaks read. Copyleaks page starts are not PDF pages (the proof showed about 5000 character chunks), so we ignore them and find where each PDF page begins inside the Copyleaks text. If at least 98% of its letters and digits are found (and at least 90% on every page), the report shows the original pages. If not, or if the file can't be imported, the whole manuscript is shown as simple text pages built from the Copyleaks text, so a highlight is never silently lost. Word files stay out for now; they move to their own later feature.

## Requirements

**User stories**:
- As a staff member, I want the report to show the author's own pages, so that the customer recognises their document.
- As a staff member, I want every match Copyleaks found to be highlighted on the right words, so that the report can be trusted.
- As a staff member, I want a report even when a PDF is hard to read, so that one odd file does not block the work.
- As the developer of feature 13, I want to ask "where are characters 120 to 340?" and get rectangles back, so that I only pick colors and draw.

**Acceptance criteria**:
- **AC-1**: For a `completed` scan of a PDF submission whose Copyleaks text is matched at or above the threshold (AC-3), `ManuscriptPagesBuilder::build($scan)` returns `ManuscriptPages` with mode `original`, a null fallback reason, and a page count equal to the PDF's page count. Its section imports every page at its own size (an A4 page stays 595 × 842 pt, a Letter page 612 × 792 pt) through the existing `ImportedPdfSection`.
- **AC-2**: `ManuscriptPages::lineBoxes(int $start, int $length)` takes a range in Copyleaks character offsets (the same offsets as `text.comparison.*.suspected.chars` in a result file) and returns a list of `LineBox` (page number from 1 inside the manuscript section, `x`, `y`, `width`, `height` in points from the top left of the page as shown). Words of the range that sit on the same line join into one box (from the left edge of the first to the right edge of the last); a new box starts at each new line and each new page, so a range that crosses a page gives boxes on both pages. Boxes follow word edges (whole words). Offsets in the range that were not found are skipped, and the found words on that line still join into one box. An offset on a character the clean up removes (a space, punctuation) adds nothing, so a range with no found characters returns an empty list, in both modes. An empty or out of range request also returns an empty list, never an error. `LineBox.page` is the page of the manuscript section as drawn: the PDF page in `original` mode, the text page in `text` mode (the keys of `highlights` and `badges` in `section()` follow the same numbers).
- **AC-3**: The match share is the number of Copyleaks letters and digits that were found on the PDF pages, divided by all Copyleaks letters and digits. Both texts are compared after the same clean up: Unicode NFKC (turns ligatures like "ﬁ" into "fi"), lower case, and only letters and digits kept (`\p{L}` and `\p{N}`). The whole of `text.value` counts; Copyleaks `exclude` ranges are not removed. Two thresholds in `config/report.php` must both hold: the overall share at least `report.manuscript.min_match_share` (default `0.98`), and every PDF page's share at least `report.manuscript.min_page_share` (default `0.90`). A PDF page's share is its letters and digits that matched a Copyleaks character, divided by all letters and digits on that page (a page with none is skipped). A word box that falls outside its page box or has a width of zero or less counts as not found. If either threshold fails, the mode is `text` with reason `low_match`. Any error from the word extractor (including a parser error or running out of memory) also counts as `low_match`. A page count that differs between the parser and mPDF is `import_failed` (AC-4), and the parser error case takes `low_match` only when the extractor itself fails.
- **AC-4**: Before matching, these checks run in this fixed order, and the first that fails makes the mode `text` with its reason: the PDF cannot be imported by mPDF (any `ReportRenderFailed` from the import probe, including `unsupported_pdf`), or the parser and mPDF disagree on the page count → `import_failed`; any page has a rotation other than 0 → `rotated_page`. The Copyleaks page starts (`text.pages.startPosition`) are not used and not compared with the PDF page count (see AC-13).
- **AC-5**: In `text` mode the section is a `TextPagesSection`: Letter pages (612 × 792 pt), text from x = 36 to 576 and from y = 60 to 732, Noto Sans Regular 10 pt in `#191919`, 14 pt line spacing (48 lines a page). The whole Copyleaks text flows as one continuous text (no page break at a Copyleaks page start); a new report page starts only when the 48 lines of the last one are full. Each `\n` in the Copyleaks text starts a new line; long lines wrap at word gaps; a word wider than 540 pt is split by characters. Letters Noto Sans lacks use the fallback fonts set up in spec 0006 (DejaVu Sans), for measuring and drawing alike; letters no font has draw as boxes and still count as found. The page count is known before drawing (layout pass first). `lineBoxes()` works the same way in this mode, with boxes taken from our own layout: each offset maps to the word it was laid out in (both parts of a split word), and spaces and line breaks add nothing (as in AC-2), so every letter and digit is found.
- **AC-6**: The build stops with `ReportRenderFailed` and writes nothing when: the submission is a Word file (`word_not_supported`); the `text` artifact row or file is missing or is not valid JSON (`crawled_text_missing`); the Copyleaks text has no letters or digits at all (`no_text`). These reasons hold no manuscript text and are added as named constructors on `ReportRenderFailed`.
- **AC-7**: The `reports` table gains `manuscript_mode` (string 20, nullable, `original` or `text`) and `manuscript_fallback_reason` (string 40, nullable, one of `low_match`, `import_failed`, `rotated_page`), added by editing the original migration in place. `ReportReplacer::replace()` takes an optional `ManuscriptOutcome` (mode plus reason) and saves both columns with the row. The reason is always null when the mode is `original`. Both are null for a report saved without one.
- **AC-8**: `php artisan report:manuscript {public_id} {--output=}` reads the latest `completed` scan of that submission, prints the mode, the reason, the overall match share and the share per page, and builds a check PDF (default `reports/checks/{public_id}-manuscript.pdf` on the `local` disk) showing the manuscript section with a thin outline around every word box. When a check in AC-4 fails no matching runs, so it prints only the mode and the reason (no shares). It exits with code 1 and the reason when the build stops (AC-6) or the submission has no completed scan.
- **AC-9**: Building `ManuscriptPages` never calls Copyleaks, never writes to the database and never logs manuscript text. It reads only the stored manuscript and the stored `text` artifact.
- **AC-10**: Copyleaks offsets are read in the unit Copyleaks uses. The working assumption is UTF-16 code units (Copyleaks runs on .NET). With a character outside the basic plane (for example an emoji) early in the text, the words after it still map to the right boxes. This is checked with the made up PDF `public/pdf/Report PDF engine Emoji.pdf` (two emoji in the first line, scanned in the sandbox on 2026-11-02; Copyleaks kept both emoji in its text). The sandbox cannot confirm the unit itself, because it returns the same fixed sample result file for every scan, so the unit is confirmed on the first real scan that has a match after an emoji (see Follow-up).
- **AC-11**: (Manual, depends on the machine.) A 100 page text PDF builds its `ManuscriptPages` and a report with it inside the spec 0006 budget (512 MB peak, 240 s). The numbers are written in `rationale.md`.
- **AC-12**: (Manual proof.) Three real PDFs (one from Word, one from Google Docs, one from Chrome) are scanned in the Copyleaks sandbox (no credits). For each, `report:manuscript` is run, and the mode, reason, match share and a by eye check (outlines sit on the words, within about 2 pt, judged for each of the three files on its own) are written in `rationale.md`, along with which fonts needed the width fallback. A file that falls back to text pages is recorded with its reason, not hidden.
- **AC-13**: Page anchoring. The Copyleaks `text.pages.startPosition` is not used at all. It is about 5000 character chunks, not PDF pages (proof on 2026-11-02: a 30 page Chrome PDF gave 11 starts at 0, 4998, 9999, 15001 and so on). The matcher cleans the whole Copyleaks text once and walks the PDF pages in order, finding where each page starts inside the Copyleaks cleaned text:
  - **Probes.** The probe is the first 24 cleaned characters of the page, after cutting off any start text that all pages share (a running header, so a repeated header cannot pull a page's anchor onto the next page). If it is not found, the characters 24 to 48, 48 to 72 and 72 to 96 are tried, and the shift is subtracted from the hit. A page with fewer than 8 cleaned characters has no probe of its own (see below).
  - **Search.** Forward only. It starts at the last good anchor plus half that page's own cleaned length (page 1 starts at 0 and, if it is unanchored, the next page still starts from 0), and goes at most 3 average page lengths ahead (the Copyleaks cleaned length divided by the PDF page count). An anchor must be after the last good anchor; otherwise the page counts as not anchored.
  - **Regions.** A page's region runs from its anchor to the next anchored page's anchor (to the end of the text for the last page). Copyleaks text before page 1's anchor belongs to no region: it stays in the AC-3 denominator, counts as not found, and `lineBoxes()` returns nothing for it.
  - **Pages with no anchor.** A page where no probe is found is unanchored: all its characters count as not found, and the Copyleaks text it should have matched falls into the previous good page's region (which then holds two pages of text, on purpose). A page with fewer than 8 cleaned characters has no probe: it joins the previous region and is left out of the AC-3 per page threshold. A blank or image only page (no cleaned characters) has no region, is skipped by the threshold, is never unanchored and does not affect the pages around it.
  - **Inside a region** the resync rule applies. Leftover characters at the end of a region, on either side, count as not found when the walk runs out. Pages with the same text (a repeated page) are safe because the search never moves backwards.
  - A Copyleaks character belongs to the page whose region holds it, so the page of an offset comes from the PDF, never from Copyleaks.

## Decision

**Chosen option**: Option 1: Pure PHP word positions with `smalot/pdfparser`, matched to the Copyleaks text, with whole file text pages as the fallback.

The PDF's own pages are used when at least 98% of the Copyleaks text is found on them; otherwise the manuscript becomes text pages built from the Copyleaks text, and the reason is saved on the report.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Feature design

**Data model sketch**:

| Table | Change | Type | Notes |
|---|---|---|---|
| `reports` | add `manuscript_mode` | string(20), nullable | `ManuscriptMode` enum: `original`, `text` |
| `reports` | add `manuscript_fallback_reason` | string(40), nullable | `ManuscriptFallbackReason` enum: `low_match`, `import_failed`, `rotated_page`. Null when mode is `original` |

No new tables, no relationship changes (`reports` still belongs to `scans`, at most one per kind). Edit `database/migrations/2026_10_31_000000_create_submission_tables.php` in place (never deployed, same as spec 0003). Add both enums in `app/Enums`, and casts plus doc comments on `Report`. Word positions are never stored; they are worked out at each build.

**State transitions**: none. The mode is decided at each build by the fixed check order:

```
Word file?                      → stop: word_not_supported
text artifact missing/bad JSON? → stop: crawled_text_missing
no letters or digits?           → stop: no_text
import probe fails, or parser and mPDF page counts differ? → text pages: import_failed
any page rotated?               → text pages: rotated_page
overall share < 0.98, any PDF page < 0.90, or extractor error? → text pages: low_match
otherwise                       → original pages
```

**Classes** (all in `app/Services/Reports/Manuscript/`, each one job, constructor injection):

| Class | Job |
|---|---|
| `CrawledText` (readonly) | `value` as a list of UTF-16 code units (a character outside the basic plane is two entries), indexed by Copyleaks offset. It keeps no page starts (AC-13) |
| `CrawledTextReader` | Loads the `text` artifact of the scan it is given (same shape as `ScanSummaryReader`). Null when missing or bad |
| `PdfWordExtractor` (interface) + `SmalotPdfWordExtractor` | Returns one `PdfPageWords` per page: the page box, the rotation, and the list of `WordBox` in content stream order (not sorted into reading order). Uses `getDataTm()` with font info turned on: one entry per `Tj`/`TJ` run with its start matrix, text, font and size. Splits runs into words on space characters, and joins neighbouring runs (Google Docs draws one letter per run) into one word when they sit on the same baseline, the gap is under 0.2 × font size and neither side has a space |
| `PdfPageBox` | Reads `/CropBox` (else `/MediaBox`) and `/Rotate` from the page dictionary through `getDetails()`, walking up the `Parent` chain when the page has no own value. Orders the corners (x1 < x2, y1 < y2) and clips the CropBox to the MediaBox. Must agree with the box mPDF imports (CropBox, spec 0006); the proof checks this |
| `PdfFontWidths` | Glyph widths per font, read once per font per file and cached. Simple fonts: `FirstChar`, `LastChar`, `Widths` (as `Font::calculateTextWidth()` does). Type0 / CID fonts (what Word and Google Docs use): our own reader for the descendant font's `W` and `DW`. All widths are in thousandths of an em. A glyph with no width gives null for its run |
| `WordBox` (readonly) | `x`, `y` (top), `width`, `height` in points from the top left, plus the cleaned characters of the word |
| `TextNormalizer` | NFKC, lower case, letters and digits only. Keeps the map from every cleaned character back to its original offset, including when one character becomes several (a ligature under NFKC, `İ` in lower case). If `intl` is missing, a small ligature table is used instead |
| `TextAligner` | Anchors each PDF page inside the whole cleaned Copyleaks text (AC-13), then matches the characters of each page to its region. Returns an `Alignment`: Copyleaks offset → (page, word index), plus the overall share and the per PDF page share |
| `TextPageLayout` | Lays out `CrawledText` on Letter pages (AC-5). Returns the lines to draw and a `WordBox` per word, so `text` mode gets the same `Alignment` shape with every character found |
| `TextPagesSection` | `ReportSection` that draws the layout with `SectionCanvas::text()` and the given highlights and badges, like `ImportedPdfSection` |
| `ManuscriptPages` (readonly) | `mode`, `fallbackReason`, `pageCount()`, `lineBoxes(start, length)`, `section(label, highlights, badges): ReportSection`, `outcome(): ManuscriptOutcome` |
| `LineBox`, `ManuscriptOutcome` (readonly) | The rectangle handed to feature 13; the mode plus reason handed to `ReportReplacer` |
| `ManuscriptPagesBuilder` | Runs the check order above and returns `ManuscriptPages` |
| `app/Console/Commands/CheckManuscriptPages.php` | `report:manuscript` (AC-8) |

**Interface surface** (no HTTP routes; this is internal):

| Call | Inputs | Output | Errors |
|---|---|---|---|
| `ManuscriptPagesBuilder::build(Scan $scan)` | a `completed` scan; always uses that scan's own `text` artifact, never another scan's | `ManuscriptPages` | `ReportRenderFailed` with `word_not_supported`, `crawled_text_missing`, `no_text` |
| `ManuscriptPages::lineBoxes(int $start, int $length)` | Copyleaks offset and length | `list<LineBox>` | none (empty list) |
| `ManuscriptPages::section(string $label = 'Integrity Submission', array $highlights = [], array $badges = [])` | label (`AI Writing Submission` for feature 23), overlays keyed by page | `ReportSection` | import errors from spec 0006 at draw time |
| `ReportReplacer::replace(Scan, ReportKind, Closure, ?ManuscriptOutcome $manuscript = null)` | as today, plus the outcome | `Report` | as today |
| `php artisan report:manuscript {public_id} {--output=}` | submission public ID | printed numbers and a check PDF path | exit 1 with the reason |

**Value sourcing**:

| Action | Value | Source |
|---|---|---|
| build | manuscript file | `submissions.file_path` on the `local` disk |
| build | is Word | `Submission::isWord()` (spec 0003) |
| build | Copyleaks text | `text` artifact of the scan: `text.value` (spec 0005). `text.pages.startPosition` is not used |
| build | PDF page count, import ok | `mpdf->setSourceFile()` probe, as in `ImportedPdfSection::forFile()` |
| build | page rotation, page box | `PdfPageBox` (`/Rotate`, `/CropBox` else `/MediaBox`, inherited from `Parent`) |
| build | match thresholds | `config('report.manuscript.min_match_share')` 0.98 and `config('report.manuscript.min_page_share')` 0.90, no `.env` values |
| word box | glyph advance | `width / 1000 × Tf size × Tm[0]` (horizontal scale), `width` from `PdfFontWidths`. The division by 1000 is ours |
| word box | glyph advance when a width is unknown | 0.5 em for that glyph; if the run is followed by another run on the same baseline, the run's letters are instead spread evenly between its start x and that next run's start x |
| word box | x and width | `x = run start x − box.x1 + advances of the glyphs before the word`; width = sum of the word's glyph advances |
| word box | y (top) and height | `top = (box.y2 − baseline) − 0.72 × size`, height `0.9 × size`, with `size = Tf size × |Tm[3]|`. Both factors are constants in one place, tuned in the proof to match the design's 10.5 to 11 pt highlight on 12 pt text |
| lineBoxes | page of an offset | the PDF page whose region holds the offset (`Alignment`, AC-13) |
| lineBoxes | which words | `Alignment`: every found offset in the range → its word, in order |
| lineBoxes | same line | same page and top within half the word height of the line's first word |
| text pages | layout numbers | AC-5 (Letter, 36/576, 60/732, Noto Sans 10 pt, 14 pt lines, one continuous flow), widths from `TextMeasurer` |
| report row | mode and reason | `ManuscriptPages::outcome()`, passed by the caller that saves the report |
| command | which scan | latest scan of the submission with status `completed` |

**Matching rule (`TextAligner`)**: first anchor every PDF page inside the whole cleaned Copyleaks text (AC-13), so one bad page can't shift the rest. Then, per page, walk the page's cleaned characters and its Copyleaks region together. Equal characters map and both move on. On a mismatch, look for the nearest point where the next 6 characters agree again, skipping at most 64 characters in total across both streams, and take the smallest skip. If there is none, the Copyleaks character is counted as not found and the walk moves one step on. This is linear in the text length. The PDF side is walked in content stream order on purpose; a file whose stream order differs from reading order (columns, tables, some Google Docs paragraphs) will fail the thresholds and fall back, and the proof records how often that happens. A throwaway test on 2026-11-02 anchored all 30 pages of the Chrome file, with each page's Copyleaks region exactly as long as the page's own text.

**Key invariants**:
- One mode per build for the whole manuscript, never a mix of original and text pages.
- `manuscript_fallback_reason` is null exactly when `manuscript_mode` is `original`.
- In `original` mode at least `min_match_share` of the Copyleaks letters and digits map to a word box, and at least `min_page_share` on every PDF page; in `text` mode all of them do.
- `ManuscriptFallbackReason` has no case for "none"; `ManuscriptOutcome` holds a nullable reason.
- Positions are pure output of the stored files: the same files always give the same boxes and mode.
- `pageCount()` is known before drawing and equals what `section()` writes (the spec 0006 `overflow` rule holds).

**Security model**: no new access. Internal tool, no staff access rules yet (Slice 1). Manuscript text stays in memory and in private files only; reasons and the command output hold numbers and codes, never text. The check PDF lives on the private `local` disk and holds the manuscript, so it is treated like a report.

**Configuration required**: no new `.env` values. Two new config keys in `config/report.php`: `report.manuscript.min_match_share` (0.98) and `report.manuscript.min_page_share` (0.90). Needs PHP `intl` for NFKC (fallback table if missing) and `mbstring`.

**Critical test scenarios** (for `/check verify`; tests are written only when you ask, per your standing preference):
- Happy path: a generated two page PDF (A4 and Letter) with a matching fake `text` artifact gives mode `original`, and `lineBoxes()` for a two line range gives two boxes on the right lines, verifies **AC-1**, **AC-2**, **AC-3**
- Fallback: the same PDF with a `text` artifact whose words are shuffled gives `text` mode with `low_match`, and its section has the page count the layout promised, verifies **AC-3**, **AC-5**
- Anchoring: a generated multi page PDF whose `text` artifact has page starts at about every 5000 characters (not at the PDF pages) still gives mode `original` with every page anchored, a page whose text is not in the Copyleaks text gives `low_match`, and a PDF with the same running header on every page still anchors each page on its own, verifies **AC-3**, **AC-13**
- Guards: a rotated page gives `rotated_page`; a Word submission stops with `word_not_supported`; a missing `text` file stops with `crawled_text_missing`, verifies **AC-4**, **AC-6**
- Saving: `ReportReplacer` with an outcome saves both columns, and with none leaves them null, verifies **AC-7**
- Safety: a build with the fake `ScanClient` makes no client call and writes no row, verifies **AC-9**

## Build plan

Approach: Tracer Bullet. First one thin real thread (a stored sandbox scan → word boxes → outlined check PDF), then prove it on real files before thickening, because every later step depends on the proof.

1. [x] Thin thread (built 2026-11-02 with the Copyleaks page starts; step 3 replaces that part): `CrawledTextReader`, `PdfPageBox`, `PdfFontWidths` (simple fonts and CID `W`/`DW`, cached per font), `SmalotPdfWordExtractor` (runs split into words, top left points), a simple `TextAligner` (exact walk, no resync yet), `ManuscriptPages::lineBoxes()`, and `report:manuscript` printing the share and outlining word boxes on the imported pages. Run it on the existing sandbox scan in `storage/app/private/scans/01m694y0hgdy1ay0jh90bh4fq8/`, satisfies **AC-1**, **AC-2**, **AC-8**
2. [x] Proof early (run 2026-11-02: Word 100%, Google Docs 100%, emoji 96.6%, Chrome 23% because of the page start problem; results in `rationale.md`). It found the page start problem that this update fixes. Still to do after step 3: rerun the four scans' `report:manuscript`, the by eye check of the outlines, tune the box factors (0.72 and 0.9), and confirm that mPDF imports the same box `PdfPageBox` reads, satisfies **AC-12**
3. [x] Re anchor and thicken the matching: replace the Copyleaks page starts with page anchoring (AC-13), remove `pageStarts` from `CrawledText` and its reader and `PageCountMismatch` from `ManuscriptFallbackReason`, then add `TextNormalizer` (NFKC, ligature fallback, offset map; first version exists), the resync rule, the shape check on word boxes, both thresholds from config, and the `lineBoxes()` rules for page edges, skipped offsets and removed characters, satisfies **AC-2**, **AC-3**, **AC-13**
4. [x] Checks and fallback: `ManuscriptPagesBuilder` with the fixed check order (no page count compare with Copyleaks) and the three stop reasons, then `TextPageLayout` (one continuous flow) and `TextPagesSection`, and `ManuscriptPages::section()` choosing the section, satisfies **AC-4**, **AC-5**, **AC-6**
5. [x] Save the outcome: edit the migration (two columns), keep `ManuscriptMode` and `ManuscriptFallbackReason` (three reasons), `Report` casts, `ManuscriptOutcome` and the optional `ReportReplacer` argument, satisfies **AC-7**
6. [x] Safety and size: check no log line or event carries text and no Copyleaks call happens, then run a 100 page text PDF through `report:manuscript` and write the memory and time in `rationale.md`, satisfies **AC-9**, **AC-11**

## Consequences

**Positive**:
- Feature 13 only maps results to ranges and colors; all position logic sits in one place behind `lineBoxes()`.
- A report is always built for any readable PDF, with no new server tools and no extra cost.
- The fallback reason is saved, so feature 24 can tell staff why a report looks plain.

**Negative / tradeoffs**:
- Word positions in pure PHP are approximate. Odd fonts, columns or text drawn out of reading order push files to text pages, and the customer then sees plain text instead of their layout.
- Positions are worked out at every build, which adds seconds per report (measured in AC-11).
- Highlights follow whole words, so a match that starts inside a word highlights the whole word.
- Rotated files always use text pages for now.
- A page where none of the four 24 character probes is found in the Copyleaks text (for example a page that opens with text Copyleaks left out) is unanchored, so its share is 0 and the whole file falls back to text pages with `low_match`.
- Known limit: a scanned PDF with a hidden text layer (invisible OCR text over an image) matches 100%, so highlights land where the hidden text sits, which may not line up with the visible image. `smalot` does not report hidden text, so this can't be detected.
- `smalot` parses the whole file into memory. A very heavy PDF can run out of memory; that is caught and becomes `low_match`.
- Word files still cannot reach a report. Staff must save them as PDF until a later feature.

**Neutral**:
- `ReportReplacer` gains one optional argument; existing callers don't change.
- Bump `MpdfReportRenderer::VERSION` when the text page look changes.

## Follow-up

- [x] The proof files were the three samples in `public/pdf/` plus a made up emoji PDF, scanned in the sandbox on 2026-11-02 through the app. Never commit them. A longer multi page Word PDF would be a better test of anchoring (the samples are one page, apart from Chrome).
- [x] Scope: feature 9 "Done when" changed to PDF only, and "Word manuscripts" added to the Deferred list (2026-11-02).
- [ ] Spec 0003 AC-4 and the saved page say Word files can't be checked yet. That stays true; reword it when the Word feature is designed.
- [ ] `/sync` after the build: the Copyleaks context file (spec 0005 export notes) should say `text.pages.startPosition` is a ~5000 character chunk, not a PDF page.
- [ ] Feature 13 must pass `ManuscriptPages::outcome()` to `ReportReplacer` when it saves the similarity report, and feature 23 the same for the AI report.
- [ ] On the first real (not sandbox) scan with a match after an emoji, confirm the offset unit is UTF-16 (AC-10).
- [ ] Hostinger: confirm PHP `intl` is on (the ligature table covers the common cases if not).
- [ ] Later, if many real files fall back with `low_match`, consider handling columns, or a per page fallback (rejected for now, see rationale).
