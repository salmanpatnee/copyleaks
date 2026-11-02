# Verify: manuscript page rendering · spec 0007 · updated 2026-11-02
_Steps derived from spec 0007 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Open the check PDF for the Chrome file (`report:manuscript 01m6dyt2btn68wezv3r9rp8d4f`) → every outline sits on its word within about 2 pt, on pages 1, 15 and 30 → AC-12
- [x] Same by eye check for the MS Word and Google Docs files, each judged on its own → AC-12  (Google Docs file was 66.5 pt off until /debug fixed the matrix order, now on the words)
- [x] Open the check PDF of the emoji file (text pages) → Letter pages, text from x 36 to 576, 48 lines a page, emoji shown or as boxes → AC-5
- [x] Vary the input: a PDF with a running header on every page still anchors each page → AC-13

## Commands
- [x] `php artisan report:manuscript 01m6dyt2btn68wezv3r9rp8d4f` → mode original, reason none, 30 pages, share at least 98% and every page at least 90% → AC-1, AC-3
- [x] `php artisan report:manuscript 01m6dyv652jpxn7862xg4fhby5` → mode text, reason low_match, shares printed → AC-3, AC-4, AC-8
- [x] `php artisan report:manuscript 01m68xkv7cpcvahcxr1r8ktrp3` (no completed scan) → exit 1 with `no_completed_scan` → AC-8
- [x] Run the command on a Word submission with a completed scan → exit 1 with `word_not_supported` → AC-6
- [x] Remove a scan's `text` artifact file, run the command → exit 1 with `crawled_text_missing` → AC-6
- [x] Call `ReportReplacer::replace()` with and without a `ManuscriptOutcome` → columns saved, or both null; reason null when mode is original → AC-7
- [x] Search `app/Services/Reports/Manuscript` for `Log::`, `ScanClient`, `Http::` and database writes → no hits → AC-9
- [ ] Offset after an emoji maps to the right word (first real scan with a match after an emoji) → AC-10  _(skipped by choice 2026-11-02: the real scan of the emoji file had no matches, so no offset to check)_
- [x] 100 page text PDF through the builder and renderer → under 512 MB and 240 s (measured: 142 to 228 MB, under 5 s) → AC-11

## Acceptance-criteria coverage
- AC-1, AC-3 … Chrome command · AC-2 … `lineBoxes()` checked by the outline pass · AC-4 … emoji and rotated file · AC-5 … text page look · AC-6 … three stop commands · AC-7 … replacer step · AC-8 … three command steps · AC-9 … search step · AC-10 … real scan (open) · AC-11 … size step · AC-12 … by eye steps · AC-13 … running header step
