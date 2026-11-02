# 0007. Manuscript page rendering: rationale

## Context

The design asks for the author's own pages at their own size, with highlights on the exact matched words. Copyleaks returns matches only as character offsets in its own copy of the text (`text.value`), plus the offset where each page starts (`text.pages.startPosition`). It never says where on the page a word is. Something has to turn offsets into rectangles on the page.

The app runs on Hostinger shared or Cloud hosting: no command line tools like Poppler or LibreOffice, no always running worker (spec 0001). Spec 0006 already imports PDF pages with mPDF and draws a highlight at given points, and the import worked on three sample files. Spec 0001 named this the biggest open risk and asked for a proof on real manuscripts, with re flowed text as the fallback.

A wrong position is worse than none: a highlight on the wrong words, or a missing one, makes the report untrustworthy. The scope also said Word and PDF, but Word needs a converter that Hostinger can't run, and the scan already refuses Word files.

Quick test on 2026-11-02: `smalot/pdfparser` 2.13.1 `getDataTm()` returned text runs with start points on the MS Word, Google Docs and Chrome samples in `public/pdf/` (Google Docs gives one run per letter, Chrome one per line part). It has `Font::calculateTextWidth()` for widths. The match against Copyleaks text was not tried yet.

## Options considered

### Option 1: Pure PHP positions with `smalot/pdfparser`, text pages as the fallback

Read glyph runs and fonts with the parser we already have, work out word boxes, match them to the Copyleaks text, and use the original pages when 98% or more is found.

**Pros**: no new tool, runs on Hostinger; keeps the original look for clean files; the fallback always lines up.
**Cons**: positions are estimates from font data; files with columns or out of order text fall back; must be proven.

### Option 2: Poppler `pdftotext -bbox`

A command line tool that gives exact word boxes.

**Pros**: very accurate, little code.
**Cons**: can't be installed on Hostinger shared hosting; breaks the "no server tools" rule of spec 0001.

### Option 3: Always text pages

Show every manuscript as text pages from the Copyleaks text.

**Pros**: always lines up, simplest.
**Cons**: loses the original look the design asks for, on every report.

## Rationale

Option 1 is the only one that keeps the original pages and still runs on the hosting we have. Its weak spot (approximate positions) is fenced in by a strict 98% match rule and a fallback that is Option 3, so the worst case equals the simplest option, never a wrong report.

Smaller calls made with you:
- **Word files out** (runner up: ask staff to upload PDF). Keeps the slice thin and sends nothing to a new third party.
- **Whole file fallback, not per page** (runner up: only the failing page as text). One look per report and simpler code; a mixed report would confuse readers.
- **98% on letters and digits** (runner up: 95%). Small extraction quirks pass; real misses don't. Counting only letters and digits ignores spacing and hyphen differences.
- **Worked out at each build, not saved** (runner up: a saved page map file). Nothing goes stale when the matching code improves. Add a cache only if AC-11 shows a real cost.
- **Word boxes, handed over as line boxes** (runner up: raw word boxes to feature 13). Matches the design ("a rectangle for each line") and keeps position logic in one class.
- **Import failure, page count mismatch and rotation fall back** (runner up: paid FPDI parser for import failures). No licence cost; rotation is rare and untested.
- **Empty Copyleaks text stops the build** (runner up: original pages with no boxes). A report with no text has nothing to show.

Decided by me while writing (you can override):
- **Offsets in UTF-16 code units** (runner up: Unicode code points). Copyleaks is a .NET service, and .NET strings count UTF-16 units. Only differs for rare characters like emoji; the proof confirms it (AC-10).
- **Resync rule with a 6 character run and a 64 character skip limit** (runner up: a full diff, which is slower on 100 pages). Linear time and enough to step over headers, page numbers and ligatures.
- **Box height 0.9 × font size, top at baseline minus 0.72 × font size** (runner up: the font's own ascent and descent, which many embedded fonts report badly). Matches the design's 10.5 to 11 pt highlight on 12 pt text; tuned in the proof.
- **Text page layout: 60 to 732 pt, 10 pt text on 14 pt lines** (runner up: the design's 7 pt body size, too small for a full manuscript). Clears the header and footer strip with room to spare.
- **A 90% per page floor on top of the 98% overall rule** (added after the cross check; runner up: overall only). Without it one badly read page hides inside a long file.
- **Our own CID width reader** (added after the cross check; runner up: always spread letters evenly across a run). `Font::calculateTextWidth()` reads only simple font `Widths`, and Word and Google Docs use Type0 / CID fonts, so without it most real files would have guessed widths.
- **Extraction error counts as `low_match`** (runner up: its own reason). Keeps the reason list short; the command shows the share as 0.

## Proof findings

_To be filled in by build step 2 (AC-10, AC-12) and step 6 (AC-11)._

| File | Source | Pages | Mode | Reason | Match share | By eye |
|---|---|---|---|---|---|---|
| | MS Word | | | | | |
| | Google Docs | | | | | |
| | Chrome | | | | | |

Offset unit confirmed: _pending_. Box factors after tuning: _pending_. 100 page run: _pending_.
