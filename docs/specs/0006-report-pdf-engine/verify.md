# Verify: Report PDF engine · spec 0006 · updated 2026-11-02
_Steps derived from spec 0006 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Open the newest `storage/app/private/reports/samples/sample-*.pdf` next to a Turnitin sample → cover, overview and source list look the same in layout, fonts and colors   → AC-7
- [x] On the A4 page and the Letter page, the highlight sits on the text line and the badge sits in the left margin, and the text stays readable under the highlight   → AC-7, AC-8
- [x] Run `report:sample` with `--import=` for one Word PDF, one Google Docs PDF and one Chrome PDF → each imports, or fails with a plain reason   → AC-8
- [x] Run it on an encrypted PDF and on a landscape or rotated PDF → encrypted gives `encrypted_pdf`; landscape keeps its landscape size   → AC-8 _(run 2026-11-09: encrypted gave `encrypted_pdf` with exit 1 and no temp file; landscape kept 792 × 612; a page with `/Rotate 90` came out 612 × 792 as shown. Highlight position on a rotated page not checked by eye)_
- [x] Run `report:sample --pages=100 --sources=100` on the target machine → under 512 MB and 240 s; write the numbers in `rationale.md`   → AC-12
- [ ] Replace the temporary logo with ours, rebuild, and search the PDF text and metadata for "turnitin" → none found   → AC-10

## Commands
- [x] `php artisan report:sample` → prints path, size, page count, build time and peak memory; file is on the `local` disk; no database row is written   → AC-1
- [x] Run `php artisan report:sample` 7 times → only the newest 5 `sample-*.pdf` files remain   → AC-1
- [x] Read the sample with a PDF reader → page 1 is 612 × 792, the manuscript pages are 595.32 × 841.92 and 612 × 792   → AC-2
- [x] Read the fonts of the sample → NotoSans Regular and SemiBold and LexendDeca Medium and SemiBold are embedded   → AC-3
- [x] Read text positions on every page → `Page n of N - Section` at x = 107, ID right aligned at page width minus 36, `N` equals the file's page count   → AC-5, AC-14
- [x] `report:sample --sources=10` and `--sources=24` → the list is 10 rows on the first list page and 14 on the next   → AC-6
- [x] A source with 0.4% shows `<1%`, a very long name ends with "…" before x = 358   → AC-7
- [x] Author or source names holding `<b>x</b>` or `<img src="http://example.com/a.png">` → printed as plain text and no request goes out   → AC-11
- [x] Greek and Cyrillic names render; Chinese or Arabic show boxes and the build still finishes   → AC-11
- [x] A section that writes more pages than `pageCount()` → `ReportRenderFailed` with reason `overflow`, and no `.tmp` file next to the target   → AC-13
- [x] A garbage file given to `--import=` → exit code 1, reason `unreadable_pdf`, no output file   → AC-8, AC-13
- [x] A cover with null fields → the lines are left out, no blank gap and no placeholder text   → AC-9
- [x] `composer lint` and `composer types:check` → pass   → AC-4

## Acceptance-criteria coverage
- AC-1 … Commands (report:sample, pruning) · AC-2 … page sizes · AC-3 … fonts · AC-4 … lint and theme · AC-5 … text positions · AC-6 … row counts · AC-7 … look checks, `<1%`, cut names · AC-8 … imports, garbage, encrypted, landscape · AC-9 … null cover · AC-10 … logo swap · AC-11 … escaping and scripts · AC-12 … 100 page run · AC-13 … overflow, garbage import · AC-14 … page count and positions · AC-15 … `rationale.md` findings (items 12 and 13 still to fill)
