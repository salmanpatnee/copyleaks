# 0006. Report PDF engine (mPDF, Blade parts and the shape proof)

**Date**: 2026-11-02
**Status**: Proposed

## Summary

This feature builds the part of the app that turns report data into a PDF. It is a small engine on top of mPDF (a pure PHP PDF library, so it runs on Hostinger). A report is a list of "sections" (cover, overview, manuscript pages). Each section writes its own pages, and the engine adds the shared header, footer, page numbers, fonts and colors from the design spec. We also build the small drawing parts the later features need (pills, callouts, group rows, source rows, margin badges, highlights) and a `report:sample` command that builds a sample PDF from made up data. Along the way we prove that mPDF can draw the hard shapes and that it can import real PDFs. The real cover, overview and highlights come later, in features 11, 12 and 13.

## Requirements

**User stories**:
- As a developer, I want one engine that owns page setup, fonts, colors, header, footer and page numbers so that every later report page looks the same without repeating the rules.
- As a developer, I want a command that builds a sample PDF so that I can open it next to the Turnitin samples and check the layout by eye.
- As a developer, I want the hard shapes and the PDF import proven on real files before the highlights feature starts, so that a mPDF limit is found now and not late.
- As staff, I want a report built from saved data, with no new scan and no Turnitin branding, so that I can send it to a customer.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: `php artisan report:sample` builds one PDF on the private `local` disk (by default at `reports/samples/sample-{Ymd-His}.pdf`, keeping only the newest 5) and prints its path, size, page count, build time and peak memory. It makes no Copyleaks call and writes no database rows.
- **AC-2**: Pages we create are US Letter (612 × 792 pt) with 36 pt side margins. Imported manuscript pages keep their own size, so a sample with one A4 and one Letter manuscript page has pages of both sizes.
- **AC-3**: Lexend Deca (Medium, SemiBold) and Noto Sans (Regular, SemiBold) are embedded in the PDF and used at the design spec type scale (20, 17, 10, 8, 7.75, 7, 6 pt). Another font appears only as a fallback for letters these fonts do not have. This is checked on the pages we create; imported manuscript pages keep their own fonts.
- **AC-4**: Every color, size and spacing value from the design spec sits in one `ReportTheme` class and reaches the pages through it. No hex color is written twice in the views.
- **AC-5**: Every page, including imported ones, has the header and the footer: our logo (55 × 16.5 pt) at x = 36, the text `Page {n} of {N} - {Section}` at x = 107, and the label `Submission ID`, a 5 pt gap and the report ID, right aligned at page width minus 36. The header logo is at y = 24 and its text top at y = 27.6. The footer logo is at page height minus 44 and its text top at page height minus 40.4. `N` counts the whole report. This holds on both Letter and A4 pages.
- **AC-6**: Each section starts on a new page. A source row is 48 pt high and is never split. `SourceRowPager` puts 10 rows on a page whose list starts at y = 245.5 and 14 rows on a page whose list starts at y = 75, with the last divider never below y = 737.
- **AC-7**: The shared parts match the design spec: divider (0.5 pt, `#cccccc`), match group circle, number pill and category pill, info callout (8 pt corners), square stats box, match group row, source row, margin badge, and highlight (match group color at 80% opacity; that the text stays readable is checked by eye). Source rank colors follow `(rank − 1) mod 4`, with rank 1 as magenta. A source name that is too long is cut with "…" so it ends before x = 358. A percentage shows as a whole number, and as `<1%` when it is above 0 and below 1.
- **AC-8**: Imported pages work or fail clearly. The sample imports an A4 page and a Letter page, and a highlight and a margin badge placed at given points (measured from the top left of the page as shown, after any rotation) land on the right spot on both. A landscape page keeps its landscape size. The same import is tried on 3 real PDFs (from Word, Google Docs and Chrome). A file that cannot be imported (compressed cross references, encrypted, unreadable) raises `ReportRenderFailed` with a plain reason.
- **AC-9**: The engine takes typed objects only. A spot with no data (a null field, an empty list) is left out cleanly: no blank line, no placeholder text and no guessed value.
- **AC-10**: (Holds once our own logo replaces the temporary stand in, see Follow-up.) The PDF contains only our logo and name. The text "turnitin" appears nowhere in the text of the pages we create, the metadata or the image names (imported manuscripts are not checked, a paper may cite Turnitin).
- **AC-11**: All text is escaped. An author name of `<b>x</b>` or `<img src="http://example.com/a.png">` is printed as plain text and nothing is fetched. Latin, Greek and Cyrillic render correctly. Other scripts use a fallback font or show a box, never a crash.
- **AC-12**: (Manual, depends on the machine.) A sample with 100 imported pages and 100 source rows builds within 512 MB of peak memory and 240 seconds on the local machine, and the numbers are written in `rationale.md`.
- **AC-13**: Any failure that PHP can catch throws `ReportRenderFailed` with a short reason that holds no manuscript text. No finished file and no output temp file (`.{name}.tmp` next to the target) is left behind, so `ReportReplacer` keeps the old report. A fatal error that cannot be caught (out of memory, time limit) may leave a temp file, so every render first deletes output temp files older than one hour in the target folder. A section that writes more pages than its `pageCount()` promised throws `ReportRenderFailed('overflow')`.
- **AC-14**: `BuiltReport` carries the true path (relative to the `local` disk), SHA 256 (read from the finished file), size in bytes and page count (the final count), and a renderer version taken from one constant. The PDF title follows the report kind and the author and creator are the app name.
- **AC-15**: The proof findings are written in `rationale.md`: for each hard shape, pass or the fallback used, the result of each real PDF import, and the memory and time numbers. The font licence texts are committed with the font files.

## Decision

**Chosen option**: Option 1: A section based engine on mPDF with Blade templates, a sample command and a shape proof.

The engine is a `ReportRenderer` that takes a `ReportDocument` (a list of `ReportSection` objects) and writes one PDF. Blade views and one `ReportTheme` class draw the shared parts. Spec 0001 already chose mPDF and the fonts. This spec chooses the shape of the engine.

**Implementation skills**: none apply (the shadcn-vue skill is for screens, not PDFs).

## Feature design

**Where the code goes** (new namespace `App\Services\Reports`, small classes, each with one job):

| Class or file | Job |
|---|---|
| `ReportRenderer` (interface) | `render(ReportDocument, string $targetPath): BuiltReport`. `$targetPath` is relative to the `local` disk, and `BuiltReport::path` is the same string (because `ReportReplacer` deletes it on that disk). Callers depend on this, never on mPDF. |
| `MpdfReportRenderer` | The one real implementation. Creates mPDF, runs the sections in order, adds header and footer, writes to a temp file, moves it into place, returns `BuiltReport`. Holds the `VERSION` constant. |
| `ReportDocument` | Readonly: `ReportKind $kind`, `string $headerId`, `list<ReportSection> $sections`. |
| `ReportSection` (interface) | `label(): string` (for example `Cover Page`), `pageCount(): int` (known before drawing: 1 for a cover, from `SourceRowPager` for a list, the file's page count for imported pages) and `write(SectionCanvas $canvas): void`. New reports add sections and never change the engine. |
| `SectionCanvas` | A thin wrapper over mPDF that sections talk to: start a Letter page, write a Blade view, place an imported page, draw a highlight or badge at points. Sections and callers think in points; only the canvas converts to the millimetres mPDF uses (one `Pt::toMm()` helper). Pills, circles and badges are drawn with mPDF's rounded rectangle call or fixed position HTML, because CSS `border-radius` and `position:absolute` only work on block boxes. Text drawn directly uses plain text calls, never HTML. Sections never touch mPDF directly. |
| `ImportedPdfSection` | A section that imports the pages of a stored PDF and draws given `Highlight` and `MarginBadge` overlays on them. |
| `ReportTheme` | Every color, font size, spacing and page number from the design spec, as constants. Also renders them into one CSS block, because mPDF does not read CSS variables as far as we know (the proof confirms). |
| `ReportFonts`, `ReportAssets` | mPDF font setup and the paths of the logo and icons. mPDF knows only regular and bold per family, so: `notosans` R = Regular, B = SemiBold; `lexenddeca` R = Medium, B = SemiBold (views use `font-weight: bold` for SemiBold). Our font folder is added to mPDF's default `fontDir` list (not a replacement), substitution is on, and a backup font is named (for example DejaVu Sans). |
| `MatchGroupStyle` (enum), `SourceColor` | The four group colors, icons and descriptions, and the four color source cycle by rank. Looks only. Which match belongs to which group is decided in features 10 and 19. |
| `SourceRowPager` | Splits fixed height rows over pages from the list's top y and the page limit. |
| `PercentFormat`, `SourceNameFitter` | `<1%` and whole number rule. Cuts a name to a pt width using mPDF's string width. |
| Part data objects | `MatchGroupRow`, `SourceRow`, `Callout`, `Highlight`, `MarginBadge`, plus the sample's `DocumentDetails`. Readonly, typed, nullable where the design leaves a spot out. |
| `resources/views/reports/` | Blade partials for each part, and the throwaway sample pages. |
| `resources/report/` | `fonts/` (static TTF and the OFL texts), `logo.png`, `icons/` (SVG source and 8 pixels per point PNG). |
| `app/Console/Commands/BuildSampleReport.php` | The `report:sample` command and its fake data (`SampleReportFactory`). |
| `app/Exceptions/ReportRenderFailed.php` | The one domain exception, with a short reason code. |
| `config/report.php` | Logo path, fonts dir, temp dir (`storage/app/mpdf`). No new `.env` values. |

**Data model sketch**: no new tables or columns. The engine returns the existing `BuiltReport` (`path`, `sha256`, `sizeBytes`, `pageCount`, `rendererVersion`), and `ReportReplacer` from spec 0002 stores it. `rendererVersion` is `MpdfReportRenderer::VERSION`, bumped when the look changes.

**State transitions**: none. A render either returns a `BuiltReport` or throws.

**Interface surface** (no HTTP routes in this feature):

| Entry point | Key inputs | Key outputs | Key errors |
|---|---|---|---|
| `ReportRenderer::render` | `ReportDocument`, `targetPath` | `BuiltReport` | `ReportRenderFailed` |
| `ReportSection::write` | `SectionCanvas` | none | `ReportRenderFailed` |
| `php artisan report:sample` | `--pages=3` (imported pages), `--sources=30` (source rows), `--import=*` (paths of real PDFs), `--output=` | prints path, size, pages, seconds, peak MB | exit code 1 and the reason |
| `SourceRowPager::split` | row count, first page list top y, later page list top y | list of row counts per page | none |

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Header and footer | Report ID (right aligned) | `ReportDocument::headerId`, given by the caller (the sample uses a fake ID; feature 10 gives the Copyleaks scan ID until feature 21) |
| Header and footer | `n` in `Page n of N` | mPDF page counter |
| Header and footer | `N` | the sum of every section's `pageCount()`, worked out before drawing, so the strip is drawn with the final total (no page total alias and no second pass) |
| Header and footer | Label text and text y | the text `Submission ID`; `ReportTheme` holds y = 27.6 (header) and page height minus 40.4 (footer) |
| Header and footer | Section name | `ReportSection::label()` of the section that owns the page |
| Header and footer | Logo | `config('report.logo')` (file in `resources/report/logo.png`) |
| Page size | Own pages | `ReportTheme::PAGE` (Letter, 612 × 792 pt) |
| Page size | Imported pages | the source page's CropBox (mPDF's default); size and orientation read from FPDI's template size, so a landscape page stays landscape; recorded in the proof |
| Source row | Number pill color and category tint | `SourceColor::forRank($rank)`, `(rank − 1) mod 4`, ranks start at 1 |
| Source row | Percent text | `PercentFormat::label()` from the number in `SourceRow` |
| Source row | Fitted name | `SourceNameFitter` using the section font and the 322 pt limit (x 36 to 358) |
| Match group row | Circle color, icon, description | `MatchGroupStyle` |
| Match group row | Count and percent | `MatchGroupRow` (filled by feature 10) |
| Rows per page | Page limit | `ReportTheme::LIST_BOTTOM` (737 pt), row height 48 pt, list top y given by the section |
| Highlight and badge | Position | points from the page's top left corner, given by the caller (fixed numbers in the sample; feature 13 computes them) |
| Highlight | Color and opacity | `MatchGroupStyle` color, `ReportTheme::HIGHLIGHT_OPACITY` (0.8) |
| Output | Target path | `targetPath` argument, relative to the `local` disk (the caller picks it; spec 0002 describes the real path). The renderer works out the absolute path itself. |
| Sample | Output path | `--output`, default `reports/samples/sample-{Ymd-His}.pdf` |
| Output | PDF title | `ReportKind` (Similarity Report or AI Writing Report) |
| Output | Author and creator | `config('app.name')` |
| Sample | Manuscript pages | generated by the sample itself with mPDF (one A4 and one Letter page, fake text), or the files given with `--import` |
| Sample | All names and numbers | `SampleReportFactory` (fixed fake data: a 0 count group, a `<1%` source, a very long source name) |

**Key invariants**:
- The engine never calls Copyleaks and never reads the database. It gets typed objects and returns a file.
- Styling comes from `ReportTheme` only. Views hold structure, not colors.
- Source rows are fixed height, so the engine decides page splits itself with `SourceRowPager`. mPDF's own automatic page break is only a safety net.
- Output is written to `.{name}.tmp` next to the target and moved into place only when the build is complete. This is separate from mPDF's own font cache in `storage/app/mpdf`, which stays on purpose.
- Draw order on an imported page is fixed: the page, then highlights (multiply blend at 80%, then opacity reset to normal), then badges, then the header and footer strip. The strip has no background, so it can sit over manuscript text in the margin. This matches Turnitin and is accepted on purpose.
- mPDF's automatic page break is off. A section that writes more pages than it promised fails with `overflow`.
- Text is always escaped: Blade `{{ }}` for views, plain text calls for drawn text. Images are always local files from `resources/report/` or a stored path, and mPDF is pointed at a local only base path. Nothing is fetched from the internet.
- The sample deletes the made up A4 and Letter source PDFs it generates after the build.
- The encrypted PDF case in AC-8 is only a guard, because spec 0003 already rejects encrypted files at upload.
- Nothing is logged except the reason code of a failure. No manuscript text, names or file paths of manuscripts.

**Security model**: the engine has no routes and no users. It is called only from our own code. It reads the private disk and writes to the private disk. Manuscript text is private data, so it never reaches the logs or the exception message. Compliance scope: none beyond the project rule on secrets and manuscript text.

**Configuration required**: none in `.env`. `config/report.php` holds the logo path, fonts dir and temp dir. mPDF needs the PHP extensions `mbstring`, `gd` and `zlib` (present locally, to confirm on Hostinger).

**Critical test scenarios** (each maps to an acceptance criterion; the Pest checks are written by `/test`):
- Happy path: the sample builds, the parser reads page sizes, embedded font names, the header text on every page and the `Page n of N` numbers, verifies **AC-1**, **AC-2**, **AC-3**, **AC-5**, **AC-14**
- Happy path: the pager gives 10 rows for a list top of 245.5 and 14 for a top of 75, and a row is never split, verifies **AC-6**
- Happy path: `PercentFormat` gives `<1%`, `0%`, `1%`, `18%`, and a long name is cut with "…", verifies **AC-7**
- Happy path: `<img src="http://example.com/a.png">` as an author name prints as text and no request is made, verifies **AC-11**
- Failure case: an encrypted PDF and a PDF with compressed cross references both raise `ReportRenderFailed` with a plain reason and leave no file, verifies **AC-8**, **AC-13**
- Failure case: a section that throws midway leaves no temp file and the old report row and file stay, verifies **AC-13**
- Edge case: a null cover field leaves its line out with no blank, verifies **AC-9**
- Edge case: the PDF text and metadata do not contain "turnitin", verifies **AC-10**
- Manual: the 100 page sample, and the side by side look against the Turnitin sample PDFs, verifies **AC-7**, **AC-12**, **AC-15**

## Build plan

Approach: Tracer Bullet. Build the thinnest real thread first (one Letter page with the right fonts, written safely to disk and described by `BuiltReport`), then thicken one strand at a time. No migration is needed. The Pest checks are left for `/test`, so the steps below build code only.

1. Setup: `composer require mpdf/mpdf` (it brings FPDI), `config/report.php`, the static TTF files for the 4 weights and their OFL texts in `resources/report/fonts/`, the temporary logo (the SVG from `public/images/placeholder-logo.svg` converted once to a PNG in `resources/report/logo.png`), and the SVG and PNG icons (match groups, sources, clipboard, robot, small triangle). Proof items 9 (static fonts load as R and B) and 11 (CSS variables) are checked here, because they shape the next steps, satisfies **AC-3**, **AC-15**
2. Thin thread: `ReportRenderer`, `MpdfReportRenderer`, `ReportDocument`, `ReportSection`, `SectionCanvas`, `ReportRenderFailed`, `.tmp` file then move, old temp cleanup, `BuiltReport` from the real file (path relative to the `local` disk), `Pt::toMm()`, the PDF title and author, and a `report:sample` command that renders one Letter page with a Lexend Deca heading and Noto Sans text, satisfies **AC-1**, **AC-3**, **AC-13**, **AC-14**
3. Shell: `ReportTheme` and its CSS, page setup and margins, the header and footer with `Page n of N - Section` for two sample sections (`N` from the sections' `pageCount()`, automatic page break off, the `overflow` check), the logo, and the check that "turnitin" appears nowhere, satisfies **AC-2**, **AC-4**, **AC-5**, **AC-10**
4. Parts and paging: the Blade partials (divider, pills, circle, callout, stats box, group row, source row), `SourceRowPager`, `PercentFormat`, `SourceNameFitter`, the sample cover and overview pages with edge data, null fields left out, escaping, and fallback fonts, satisfies **AC-6**, **AC-7**, **AC-9**, **AC-11**
5. Imported pages and overlays: `ImportedPdfSection`, the highlight and margin badge on A4 and Letter pages, the `--import` option, landscape pages, the fixed draw order, and the clear reasons for a file that cannot be imported, satisfies **AC-2**, **AC-5**, **AC-7**, **AC-8**, **AC-13**
6. Benchmark and proof: the `--pages` and `--sources` options, a run at 100 and 100, the three real PDF imports and the side by side look done by hand, and the findings written into `rationale.md`, satisfies **AC-12**, **AC-15**

## Consequences

**Positive**:
- Features 11, 12, 13 and 23 build on shared, tested parts and never repeat page setup, fonts or header and footer rules.
- A report is rebuilt from saved data with no browser, no new scan and no server tools.
- The risky parts (hard shapes, PDF import, memory) are measured before the highlights feature depends on them.
- Because rows are fixed height, page splits are predictable and the same on every run.

**Negative / tradeoffs**:
- mPDF supports less CSS than a browser, so some shapes may need PHP drawing instead of CSS. The proof records each one, and a mix of both is allowed per element.
- The free FPDI parser in mPDF cannot read PDF 1.5 or newer files with compressed cross references, or encrypted files. Many Word and Google Docs exports are such files. If the proof shows many failures, feature 9 must choose a fix (the paid setasign add on, a pure PHP rewrite of the file, or the re flowed text fallback from spec 0001) before manuscript pages can ship.
- Fonts: other scripts (Arabic, Chinese and so on) may show a box. Ship extra fonts later only if staff need them.
- The 100 page target is checked on a developer machine. Hostinger may be slower or have a lower memory limit.
- Icons are our own drawings, not Turnitin's, so they will look slightly different.

**Neutral**:
- New composer packages: `mpdf/mpdf` and its dependency `setasign/fpdi`.
- A later move to a VPS would allow a Chromium based option without changing the callers, because they only know `ReportRenderer`.

## Rationale

Reasoning, options, the premise note and the proof findings: see [rationale.md](rationale.md).

## Follow-up

- [ ] **You:** give us our own logo file (SVG or a PNG at least 1392 × 417 pixels). The file added at `public/images/placeholder-logo.svg` on 2026-11-02 is the Turnitin logo. By your decision it is used as a temporary stand in during development only. While it is in use, the AC-10 logo check is expected to fail, and no report made with it may be sent to a customer. When our logo arrives, put it in `resources/report/` (not `public/`, where anyone can fetch it), convert an SVG to PNG once, commit it, and remove the Turnitin file.
- [ ] **You:** keep 3 real PDFs ready for the proof (one from Word, one from Google Docs, one from Chrome). Real manuscripts must never be committed. Pass them with `--import=`.
- [ ] Confirm the Lexend Deca and Noto Sans licences (both are SIL Open Font License on Google Fonts, the OFL texts are committed with the files). Already open in the brief and in spec 0001.
- [ ] After the proof, decide the PDF import fix in feature 9 (`/architect manuscript page rendering`) from the findings in `rationale.md`.
- [ ] Confirm on Hostinger: the PHP extensions `mbstring`, `gd` and `zlib`, the CLI `memory_limit` of 512M and a writable `storage/app/mpdf`. This extends the Hostinger check already listed in spec 0001.
- [ ] The legal check on copying the Turnitin layout too closely is still open (brief, design spec section 6). The icons, logo and wording are ours, but the layout is copied by design.
- [ ] Feature 23: the AI highlight style for scores of 20% or more is still unknown. The engine takes the highlight color as a parameter so this can be added without changing it.
- [ ] After the build, add a context file `app/Services/Reports/AGENTS.md` and a pointer in root `AGENTS.md` (run `/sync`).
