# Verify: Similarity overview pages · spec 0010 · updated 2026-11-05
_Steps derived from spec 0010 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] `php artisan report:sample`, open the PDF → page 1 shows 18%, 3 filters, 4 group rows, totals, `0 Integrity Flags for Review`; looks the same as before the change → AC-2, AC-3, AC-4, AC-11
- [x] Open the sample's overview page 2 → divider at the design place, 10 rows from 245.5, summary block repeated at the top → AC-5
- [x] `php artisan report:sample --sources=25` → pages 2, 3 and 4 hold 10, 14 and 1 rows, number pill colors keep cycling, no row cut → AC-1, AC-5, AC-6
- [x] Sample rows with `<b>` and an image tag in a source name show as plain text, and no web request is made → AC-8
- [x] A source with 0.4% shows `<1%`, a null category shows no category pill, a very long name ends in `…` → AC-2, AC-6
- [x] `php artisan report:overview {public_id}` on a saved scan, open the PDF → page 1 has the score, empty Match Groups and Top Sources headings, the info box, no filter list and no flag count line; rows start on page 2 with only the score line above the divider → AC-2, AC-3, AC-4, AC-5, AC-9 (waits for a saved scan)
- [x] Overview of a scan with a score and no sources → 1 page after the cover, `No matching sources found` 17.5 pt below the info box, command exits with success → AC-7
- [x] Overview of a scan with at least one source → the `No matching sources found` line is never drawn → AC-7

## Commands
- [x] `php artisan report:overview {public_id}` → prints Path, Size, Pages, Sources and one `Left out:` line ending `Match Groups, Source totals, Filtered list, Integrity flag count` → AC-10
- [x] `php artisan report:overview unknown-id` → prints `no_completed_scan`, exit code 1, no file → AC-10
- [x] Run `report:overview` 6 times for one scan (with a second between runs) → only 5 `overview-*.pdf` remain, every `cover-*` and `sample-*` file is untouched → AC-10
- [x] Run `report:cover` and `report:sample` after an overview exists → no `overview-*` file is deleted → AC-10
- [x] A scan with a blank submission ID → prints `submission_id_missing: …`, exit code 1, no file → AC-9, AC-10
- [x] Build an `OverviewSection` with 5 group rows or 5 filters → throws `overview_too_many_groups` / `overview_too_many_filters`, no file left → AC-3, AC-4
- [x] Build an `OverviewSection` with 0, 1, 10, 11, 25 and 100 sources → `pageCount()` equals the rendered page count (1, 2, 2, 3, 4, 9 pages), no `overflow` → AC-1
- [x] `grep -rn "Log::\|ScanClient\|Http::\|SubmitScan" app/Services/Reports/Overview` → no match → AC-12
- [x] `grep -n "VERSION" app/Services/Reports/MpdfReportRenderer.php` → shows `3` → AC-11
- [x] `ls app/Services/Reports/Sample` → no `SampleOverviewSection.php` → AC-11

## Acceptance-criteria coverage
- AC-1 … page count checks · AC-2 … sample and real page 1 · AC-3 … group and totals rows · AC-4 … filters and flag line · AC-5 … page 2 top · AC-6 … rows and pills · AC-7 … zero sources · AC-8 … markup as text · AC-9 … factory and strip labels · AC-10 … command steps · AC-11 … sample, version, deleted file · AC-12 … grep rule
