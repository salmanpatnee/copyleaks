# 0007. Manuscript page rendering: rationale

## Context

The design asks for the author's own pages at their own size, with highlights on the exact matched words. Copyleaks returns matches only as character offsets in its own copy of the text (`text.value`). It also gives `text.pages.startPosition`, which the first version of this spec took as the PDF page starts. The proof showed it is not (see Proof findings). It never says where on the page a word is. Something has to turn offsets into rectangles on the page.

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
- **Import failure and rotation fall back** (runner up: paid FPDI parser for import failures). No licence cost; rotation is rare and untested. A page count compare with Copyleaks was dropped on 2026-11-02, because Copyleaks pages are not PDF pages.
- **Empty Copyleaks text stops the build** (runner up: original pages with no boxes). A report with no text has nothing to show.

Changed after the proof (2026-11-02, you can override):
- **Find each PDF page's start inside the Copyleaks text** (runner up 1: use the Copyleaks page starts, rejected because they are ~5000 character chunks; runner up 2: one walk over the whole text with the resync rule, rejected because if the PDF holds more text than Copyleaks saw the walk gets stuck and loses the rest of the file). Anchoring keeps the property that one bad page can't shift the rest, and a throwaway test anchored all 30 Chrome pages exactly.
- **Anchoring details closed after the cross check** (runner up: leave them to the builder): search starts at the last good anchor plus half the page, at most 3 average pages ahead, forward only; shared start text is cut from probes; pages under 8 cleaned characters join the previous region and skip the threshold; blank pages have no region. These keep one false hit or one odd page from damaging the rest, and every failure ends as a text page fallback, never a wrong report.
- **Removed characters add nothing in `lineBoxes()`** (runner up: map them to the next or previous word). A trailing space or full stop could otherwise light up a word outside the range, even on the next page.
- **Text pages are one continuous flow** (runner up: break at each Copyleaks page start). Those starts fall mid sentence every ~5000 characters, so a break there would look broken.

Decided by me while writing (you can override):
- **Offsets in UTF-16 code units** (runner up: Unicode code points). Copyleaks is a .NET service, and .NET strings count UTF-16 units. Only differs for rare characters like emoji; the proof confirms it (AC-10).
- **Resync rule with a 6 character run and a 64 character skip limit** (runner up: a full diff, which is slower on 100 pages). Linear time and enough to step over headers, page numbers and ligatures.
- **Box height 0.9 × font size, top at baseline minus 0.72 × font size** (runner up: the font's own ascent and descent, which many embedded fonts report badly). Matches the design's 10.5 to 11 pt highlight on 12 pt text; tuned in the proof.
- **Text page layout: 60 to 732 pt, 10 pt text on 14 pt lines** (runner up: the design's 7 pt body size, too small for a full manuscript). Clears the header and footer strip with room to spare.
- **A 90% per page floor on top of the 98% overall rule** (added after the cross check; runner up: overall only). Without it one badly read page hides inside a long file.
- **Our own CID width reader** (added after the cross check; runner up: always spread letters evenly across a run). `Font::calculateTextWidth()` reads only simple font `Widths`, and Word and Google Docs use Type0 / CID fonts, so without it most real files would have guessed widths.
- **Extraction error counts as `low_match`** (runner up: its own reason). Keeps the reason list short; the command shows the share as 0.

## Proof findings

Run on 2026-11-02. Four PDFs uploaded and submitted through the app in sandbox mode (all four scans `completed`), then `php artisan report:manuscript` on each. The first four rows below used the first version, which anchored pages with the Copyleaks page starts.

| File | Source | PDF pages | Copyleaks "pages" | Mode | Match share | By eye |
|---|---|---|---|---|---|---|
| `Report PDF engine MS Word.pdf` | MS Word | 1 | 1 | original | 100% | pending |
| `Report PDF engine Google Docs.pdf` | Google Docs | 1 | 1 | original | 100% | pending |
| `Report PDF engine Chrome.pdf` | Chrome | 30 | 11 | original (fallback not built yet) | 23.11% | pending |
| `Report PDF engine Emoji.pdf` | made up (hand built) | 1 | 1 | original | 96.60% | pending |

What the proof showed:
- **Copyleaks page starts are not PDF pages.** The Chrome file has 30 PDF pages but Copyleaks gave 11 starts: 0, 4998, 9999, 15001, 19999, 24998, 29993, 34999, 39987, 45001, 50000. They are about 5000 character chunks. This broke the first version's anchoring and its page count check, and led to AC-13.
- **The new anchoring works on the Chrome file.** A throwaway script (not repo code) searched for each PDF page start inside the whole Copyleaks cleaned text: all 30 pages anchored, and every page's Copyleaks region was exactly as long as the page's own text (40,980 cleaned characters in total).
- **Word and Google Docs read at 100%.** Both are one page with a few words, so they say little about long files. Word needed a fix: its `W` width array is an indirect object. A longer multi page Word PDF would be a better test.
- **Emoji file at 96.6%.** Copyleaks kept both emoji in its text. The missing 3.4% is words at the right end of long lines: the made up PDF uses plain Helvetica with no width table, so guessed widths (0.5 em) push those words past the page edge and the shape check counts them as not found. This is a flaw of the test file, not of the method.
- **`smalot/pdfparser` mangles characters outside the basic plane** (each half of the surrogate pair becomes an invalid byte sequence). The clean up drops them on both sides, so matching is not hurt.
- **`smalot/pdfparser` drops the font and size for text shown with the `'` operator.** The hand made sandbox PDF uses it. The extractor reuses the last font, or the page's first `Tf`. Word, Google Docs and Chrome use `Tj` and `TJ`.
- **The offset unit could not be confirmed.** The sandbox returns the same fixed sample result file (same source id) for every scan, so its match offsets don't relate to our text. See the Follow-up in `index.md`.

Still to do: rerun all four after the re anchoring, the by eye outline check, box factor tuning (now 0.72 and 0.9, untuned), the mPDF box agreement check, and the 100 page run (AC-11).

## Rerun and size results (2026-11-02, after the build)

Rerun of `report:manuscript` after anchoring and the fallback were built:

| File | Mode | Reason | Match share |
|---|---|---|---|
| MS Word | original | none | 100% |
| Google Docs | original | none | 100% |
| Chrome (30 pages) | original | none | 99.55% (worst page 97.61%) |
| Emoji (made up) | text | low_match | 96.60% |

The by eye outline check, the box factor tuning and the mPDF box agreement check are still to do (AC-12).

**AC-11 numbers** (this machine, PHP CLI, Windows): a generated 100 page text PDF (42,000 words). Word positions plus matching: 100% share, 2.3 s, peak 142 MB. Text page layout (73 pages): 1.0 s, peak 228 MB. Rendering those 73 text pages: 1.7 s, same peak. All well inside the 512 MB / 240 s budget. Hostinger has not been measured.
