# 0006. Report PDF engine: rationale

The build spec is in [index.md](index.md). This file holds the reasoning. `/develop` does not need it.

## Context

> ⚠️ Premise note: Spec 0001 assumed that mPDF can simply import the pages of the uploaded PDF. The free FPDI parser inside mPDF cannot read PDF 1.5 or newer files that use compressed cross references (its own error says "this PDF document probably uses a compression technique which is not supported by the free parser"), and it cannot read encrypted files at all. Word, Google Docs and Chrome often produce the compressed kind. If most real manuscripts fail, the whole "keep the original page look" promise breaks, and it would break in feature 9, late. The right framing is to measure this now, in the shape proof, and keep the engine independent of the answer.

The report must follow the Turnitin style layout exactly: Letter pages with 36 pt margins, two font families, fixed colors, a header and footer on every page, and strict page break rules. The design spec has measured numbers for almost everything, so the engine is a precise drawing job, not a free form one.

The app runs on Hostinger shared or Cloud hosting. That means no Chromium, no always running worker, and limited memory and time. Spec 0001 therefore chose mPDF, a pure PHP library. mPDF reads HTML and CSS but supports less CSS than a browser. The design also needs things that are hard for it: 80% opacity highlights over imported pages, rounded pills, pages of two sizes in one file, and a header and footer that adapt to each page's size.

Four later features (11 cover, 12 overview, 13 highlights, 23 AI report) and the two report kinds all draw on the same page rules. If those rules are not in one place, each feature repeats them and they drift. If the engine is not proven first, a mPDF limit shows up in the middle of the highlights feature. Not deciding means those features guess.

## Options considered

### Option 1: Section based engine on mPDF with Blade templates, a sample command and a shape proof

A `ReportRenderer` runs a list of `ReportSection` objects. Each section writes its pages through a small canvas, using Blade views for text and shared parts. One `ReportTheme` class holds every design number. A sample command and a proof come with it.

**Pros**:
- New sections and the AI report are added without changing the engine.
- Matches the brief ("our own HTML templates") and keeps the layout in views that others can read.
- The proof finds mPDF limits and the import risk before dependent features start.

**Cons**:
- More classes up front than a single template.
- CSS limits in mPDF may force PHP drawing for some shapes, so some elements use two ways to draw.

### Option 2: One fixed document class with cover, overview and manuscript slots

A single `ReportDocument` with named slots, rendered by one class.

**Pros**:
- Fewest moving parts. Easy to read for a first version.

**Cons**:
- The AI report has a different set of pages, so the engine class would change for it.
- Page numbering, section labels and mixed page sizes end up as special cases inside one class.

### Option 3: Everything drawn with mPDF calls in PHP

Place every text, line and shape by coordinates in PHP.

**Pros**:
- Most exact, and no CSS limits.

**Cons**:
- The layout lives in PHP code, which is harder to read and review.
- Text that flows and wraps (callouts, long names) needs manual work.

### Option 4: A headless Chromium print (for example through a browser PDF package)

Render real HTML and CSS in a browser and print it to PDF.

**Pros**:
- Full CSS support and the easiest way to match a design exactly.

**Cons**:
- Not possible on Hostinger shared or Cloud hosting (spec 0001). It needs a browser installed and a lot of memory.
- It would force a hosting change before the first version.

## Rationale

Option 1 fits the forces. The hosting limit removes Option 4 today, and the design spec is precise enough that the layout must be exact, which a section based engine with one theme class supports. Option 2 is simpler but the second report (AI writing) and the manuscript pages would soon push special cases into it, so we pay the small cost of the section interface now. Option 3 gives the most control but is a poor fit for flowing text and hard for others to change. Option 1 can still use direct drawing for single elements when CSS fails, so it keeps Option 3's escape hatch where it is needed.

The engineer's choices (scope of engine, sample command and proof; Blade into mPDF; typed input objects; section objects; static fonts in the repo; automated checks plus a side by side look) all point the same way, so no choice was overridden. The one thing we added is the import risk, which was not in the engineer's answers. The engine is built so that the answer to that risk does not change it: sections get pages through an interface, so feature 9 can swap the import method.

## Decisions made by the architect (the engineer did not weigh in)

| Decision | Pick | Why, and the runner up |
|---|---|---|
| Namespace | `App\Services\Reports` | Matches `App\Services\Copyleaks` and `App\Services\Manuscripts`. Runner up: `App\Reports` (breaks the folder rule in `AGENTS.md`). |
| Page splits of the source list | `SourceRowPager` decides, with explicit page breaks | Rows are exactly 48 pt, so capacity is a formula: `floor((737 − 38 − top) / 48) + 1`, which gives 10 rows from y = 245.5 and 14 rows from y = 75, matching the samples. Runner up: trust mPDF's automatic break (it cannot give different tops for the first and later pages). |
| Total page count `N` | Each section reports `pageCount()` before drawing, so `N` is known up front | Pages are fixed in size and decided by us, so counting first removes the alias risk and the second pass. Cross check change. Runner up: mPDF page total alias with a second pass as a fallback. |
| Header and footer drawing | Our own positioned strip drawn for each page at that page's own size | One code path for Letter and imported pages, with the exact label, gap and y values from the design. mPDF's own header also follows page size, so this is a choice of control, not a need. Runner up: mPDF's header. |
| Highlight drawing | Rectangle at 80% opacity with a multiply blend, so text shows through | mPDF's `SetAlpha` supports opacity and a blend mode. Runner up: draw the highlight under the text (not possible on imported pages, where the text is already part of the page). |
| Imported page box | CropBox (mPDF's default) | Matches what a viewer shows. Runner up: MediaBox. The proof records any file where they differ. |
| Name cutting | PHP, using mPDF's string width, to 322 pt | Exact. Runner up: CSS `text-overflow` (support in mPDF is uncertain). |
| Sample manuscript pages | Generated by the sample itself (A4 and Letter, fake text), or files given with `--import` | No binary fixtures in the repo and no real manuscript ever committed. Runner up: a committed sample PDF. |
| Icons | Our own SVG drawings, committed with PNG exports at 8 pixels per point | The design says raster icons; SVG keeps a clean source. Runner up: draw PNGs only. |
| Fallback fonts | mPDF's font substitution to its bundled free fonts | No extra weight in the repo. Runner up: ship Noto fonts for Arabic and CJK (very large). |
| Atomic write | Temp file in the same folder, then rename | A crash never leaves a half PDF at the target. Runner up: write straight to the target. |
| PDF metadata | Title by kind, author and creator = app name, no bookmarks, links or annotations | The samples have none. Runner up: leave the mPDF defaults. |
| Roles of the engine | No database and no Copyleaks | Keeps feature 8 and feature 10 apart and the engine testable with fake data. Runner up: engine reads artifacts itself. |

## The shape proof: checklist (items 9 and 11 run in build step 1, the rest in build step 6)

Mark each line pass, or write the fallback that was used. Findings go in the next section. Shapes that need a rounded corner are drawn with mPDF's rounded rectangle call or fixed position HTML, since CSS `border-radius` only works on block boxes.

1. Rounded number and category pills with the right radius and color
2. Circle with a PNG icon inside
3. Info callout with 8 pt corners and wrapped text
4. 0.5 pt divider lines at exact y positions
5. Highlight at 80% opacity with multiply: text readable through it, on an imported page
6. Margin badge (circle plus pill) placed in the left margin of an imported page
7. Letter and A4 pages in one file with the header and footer at the right place on both
8. `Page n of N - Section` with the true total on every page, including imported ones
9. Lexend Deca Medium and SemiBold, Noto Sans Regular and SemiBold embedded as static fonts (variable font files do not work well in mPDF)
10. Fallback font for a letter the main fonts lack
11. CSS variables: confirm they are not supported, so tokens must be written into the CSS by PHP
12. Import of a Word PDF, a Google Docs PDF and a Chrome PDF: pass or the exact error
13. Import of an encrypted PDF and a landscape or rotated page: error text or correct size
14. 100 imported pages and 100 source rows: peak memory and seconds
15. Escaping: an `<img src="http://...">` in a name is printed, not fetched

## Proof findings

_Run 2026-11-02 with `php artisan report:sample`, pages rendered to images and read by eye. Items 12 and 13 still wait for real files._

| # | Result (pass, or fallback used) | Notes |
|---|---|---|
| 1 | Pass | `div` with `position:absolute` and `border-radius` in a Blade partial. Radius 7 and 7.5 look right. |
| 2 | Pass | The circle is a `div`; the PNG icon is placed with mPDF's image call (not inside the HTML, which avoids Windows path problems). |
| 3 | Pass | 8 pt corners and wrapped text work. The blank line between paragraphs needs an empty `div` (`margin-bottom` is ignored). |
| 4 | Pass | A `div` of height 0.5 pt with a background. |
| 5 | Pass (by eye) | `SetAlpha(0.8, 'Multiply')` then back to `Normal`. The text stays readable on an imported page. |
| 6 | Pass | Circle and pill drawn with `RoundedRect`; number drawn as plain text. Lands in the left margin of an A4 page. |
| 7 | Pass | A4 (595.32 × 841.92) and Letter pages in one file. Header and footer are placed from each page's own width and height. |
| 8 | Pass | `N` is the sum of the sections' `pageCount()`, so every page, including imported ones, shows the true total. Text positions read back at the spec numbers (text top 27.6 and page height minus 40.4). |
| 9 | Pass | Static TTFs load as R and B. Embedded names: NotoSans-Regular, NotoSans-SemiBold, LexendDeca-Medium, LexendDeca-SemiBold. |
| 10 | Pass | Greek and Cyrillic fall back to DejaVu Sans and read correctly. Chinese and Arabic show boxes and never crash. |
| 11 | Not tested, avoided | Tokens are written into the CSS by PHP (`ReportTheme::css()`), so the answer does not matter. |
| 12 | Pass | Tried 2026-11-02 with files from `public/pdf/`: MS Word (PDF 1.7, 1 A4 page), Google Docs (PDF 1.4, 1 page), Chrome (PDF 1.4, 30 A4 pages). All three import with no error, keep their own size, and take the highlight, badge, header and footer. The Chrome file (1.1 MB) builds in 3.2 s at 56 MB. A garbage file fails with `unreadable_pdf`, no output file and no temp file. Only three files were tried, so a Word or Google Docs file saved with compressed cross references could still fail; `unsupported_pdf` covers that. |
| 13 | Partly | Landscape: pass (tried 2026-11-02 with `public/pdf/Report PDF engine Landscape.pdf`, 4 pages, MediaBox 883.89 × 637.28, PDF 1.4). All 4 pages keep their landscape size, the strip sits at the page's own width and height (ID right edge at width minus 36, footer at the bottom), and the highlight and badge land at the given points from the top left. That file has `/Rotate 0`, so a page with a real rotation is still not tried. Encrypted files and a real rotation are skipped by choice (2026-11-02); the guard code exists but was never run on such a file. The error mapping for compressed cross references and encrypted files is in `PdfImportErrors` but only reached with real files. |
| 14 | Pass | 100 imported pages and 100 source rows (110 pages in all). Peak memory 58 MB, 4.8 s. Machine: developer laptop, Windows, PHP 8.3, CLI `memory_limit` 1024M. |
| 15 | Pass | An author line `<b>one</b>` and a source name with an `<img src="http://example.com/a.png">` print as plain text. The HTTP client is also replaced with one that refuses every request. |

## Design facts this spec relies on (from `docs/turnitin-report-design.md`)

- Own pages: 612 × 792 pt, content from x = 36 to 576. Header logo y = 24 to 40.5. Footer logo from page height minus 44.
- Type scale 20 / 17 / 10 / 8 / 7.75 / 7 / 6 pt. Line spacing 10 pt for 7 pt text and 8 pt for 6 pt text.
- Colors: text `#191919`, muted `#636363`, callout text `#2d2d2d`, divider `#cccccc`, stats box `#f8f8f8`, callout `#d0e8f9`. Match groups `#f86371`, `#f68e4a`, `#f4e4aa`, `#c1ecdf`. Source cycle `#cb1476`, `#225ec6`, `#007546`, `#7533e7` with tints `#fedce7`, `#d6e5fb`, `#c1ecdf`, `#ecdffe`.
- Source row: 48 pt high, number pill 24.5 × 15 at x = 36, category pill 70 × 15 at x = 66, name at 19.4 pt below the pill top, percent at x = 386, divider from 36 to 400 at 38 pt below the pill top.
- Page rules: cover is one page, each section starts on a new page, source rows are never split, the summary block repeats on the second overview page (feature 12).
- Unknown in the design spec: the AI highlight color and the AI rows for scores of 20% or more (feature 23).
