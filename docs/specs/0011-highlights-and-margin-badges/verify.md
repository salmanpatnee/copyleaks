# Verify: Highlights & margin badges · spec 0011 · updated 2026-11-06
_Steps derived from spec 0011 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Open the newest `similarity-*.pdf` from a real scan (original mode), go to the manuscript page → highlight covers exactly the matched words, badge is level with the first line, number matches the source row → AC-1, AC-2, AC-12 (seen by eye 2026-11-06 on the saved scan: "Sample" highlighted, badge 1 level with it); real scan 2026-11-06: 1 badge, highlight covers matched words, 6 pages
- [x] Same check on a scan with several matches on every page → no two badges overlap → AC-4, AC-12 (2026-11-09: real scan, 13 pages, 3 badges, no overlap; plus 67 made up ranges over the same real text: 65 badges, 2 dropped, none overlapping)
- [x] Two matches starting on neighbouring lines → badges sit in slots 0 and 1 (x 7.5 and 44), never on top of each other → AC-4
- [x] A fifth badge close to four busy slots → badge dropped, its highlight stays → AC-5
- [x] A source ranked 100 or more → (2026-11-09: made up ranks 100, 101, 999, 1000, 12345 through the real placer and renderer, all shown whole, 1000 and 12345 in the smaller size) full number inside the pill (6.0 pt for three digits, 4.5 pt for four or more), never cut → AC-6
- [x] A scan that falls back to `text` mode → highlights and badges still line up (page numbers follow the text pages) → AC-12 (2026-11-09: saved low_match scan, 13 made up ranges: 6 placed on the right words, 7 beyond the text counted unplaced, label `Page 3 of 3 - Integrity Submission`)
- [x] Page labels on manuscript pages read `Page n of N - Integrity Submission`, N is cover plus overview plus manuscript pages → AC-7
- [x] Draw order: highlights under the badges, header and footer strip on top → AC-10

## Commands
- [x] `php artisan report:similarity {public_id}` on a completed scan → prints Path, Size, Pages, Sources, Manuscript, Highlights, Badges, Unplaced ranges, Dropped badges, Left out; writes `reports/samples/similarity-*.pdf` → AC-9
- [x] Check that Unplaced ranges + Dropped badges + Badges equals the range count → AC-9
- [x] Run it 6 times → only the newest 5 `similarity-*.pdf` stay, `cover-*`, `overview-*`, `sample-*` untouched → AC-9
- [x] `php artisan report:similarity nope` → prints `no_completed_scan`, exit code 1 → AC-9
- [x] A Word submission or a scan with no saved text → prints `{reason}: {message}` and exits with a failure code → AC-8, AC-9 (2026-11-09: real command and builder, `crawled_text_missing` exit 1 with the saved text reader returning nothing; `word_not_supported` exit 1 with a stand in pages builder, and the real Word check threw the same reason on an unsaved .docx submission)
- [x] A scan with zero ranges → full report still builds, manuscript pages with no overlays → AC-7
- [x] `grep -rnE "Log::|ScanClient|Http::|SubmitScan|DB::" app/Services/Reports/Highlights app/Console/Commands/BuildSimilarityReport.php` → no matches → AC-11
- [x] `MpdfReportRenderer::VERSION` is `4` → AC-10

## Acceptance-criteria coverage
- AC-1, AC-2 … covered by the first UI step · AC-3 … unplaced count in the printed numbers · AC-4 … slot steps · AC-5 … crowded step · AC-6 … big rank step · AC-7 … label and zero ranges steps · AC-8 … failure step · AC-9 … command steps · AC-10 … draw order and VERSION steps · AC-11 … search step · AC-12 … real scan steps
