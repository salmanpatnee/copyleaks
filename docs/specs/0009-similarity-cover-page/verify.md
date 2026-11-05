# Verify: similarity cover page · spec 0009 · updated 2026-11-05
_Steps derived from spec 0009 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [ ] Open the newest `sample-*.pdf` page 1 → author, title, assignment (8 pt, muted, clipboard icon), divider, `Document Details`, five field pairs, stats box with three lines at the design positions; strip reads `Page 1 of N - Cover Page` → AC-1, AC-3, AC-9
- [ ] In the sample cover, the assignment `Sample class <b>one</b>` shows as plain text → AC-6
- [ ] Cover of a real saved scan shows only Submission ID and File Name, plus a one line `N Words` box, and no blank labels or gaps → AC-2, AC-3
- [ ] A scan with a very long title (300 characters) shows 2 lines ending in `…`, and everything below, including the stats box, sits 20 pt lower → AC-5
- [ ] Long author, assignment and File Name end in `…` and do not touch the stats box → AC-4
- [ ] Vary the data: blank assignment drops the clipboard and line only; a 0 count is left out; whitespace and line breaks in the title show as single spaces → AC-2, AC-3, AC-4

## Commands
- [ ] `php artisan report:sample` → builds, still shows all five fields and three counts → AC-9
- [ ] `php artisan report:cover {public_id}` (completed scan) → prints Path, Size, `Pages: 1`, and a `Left out:` line using the fixed names; file is in `reports/samples/cover-{Ymd-His}.pdf`; no author, title or assignment is printed → AC-8
- [ ] `php artisan report:cover unknown-id` → prints `no_completed_scan`, exit code 1 → AC-8
- [ ] Scan with a damaged saved file → prints `{reason}: {message}`, exit code 1, no cover file left behind → AC-8
- [ ] Run `report:cover` 6 times, then `report:sample` → only 5 `cover-*.pdf` remain, no `sample-*.pdf` removed by the cover run, no `cover-*.pdf` removed by the sample run → AC-8
- [ ] Scan whose submission ID is null or blank → `submission_id_missing` → AC-7
- [ ] `grep -rnE "Log::|ScanClient|Http::|SubmitScan" app/Services/Reports/Cover` → no matches; `report:cover` starts no scan → AC-10
- [ ] `MpdfReportRenderer::VERSION` is `'2'` → AC-9

## Value sourcing
- [ ] Author, title, assignment come from the submission row (change them, rebuild, cover changes) → AC-1
- [ ] Submission ID is the scan's `copyleaks_scan_id`, in the strip and the field → AC-7
- [ ] File Name is `original_name`; Words is the saved summary's `totalWords` → AC-1, AC-3
- [ ] Dates, File Size, Pages, Characters are null today and left out → AC-2

## Acceptance-criteria coverage
- AC-1 … cover and sample steps · AC-2 … left out steps · AC-3 … stats box steps · AC-4 … long text steps · AC-5 … long title step · AC-6 … markup step · AC-7 … blank ID step · AC-8 … command steps · AC-9 … sample and version steps · AC-10 … grep step
