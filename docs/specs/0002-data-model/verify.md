# Verify: data model · spec 0002 · updated 2026-10-31
_Steps derived from spec 0002 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

There are no routes or screens in this feature, so every step is a command or a check in `php artisan tinker`. Nothing here may call Copyleaks. Use factories only.

## UI / manual
None. This feature adds no pages.

## Commands
- [x] `php artisan migrate:status` → `2026_10_31_000000_create_submission_tables` shows `Ran`   → AC-1 to AC-9
- [x] `php artisan db:table submissions` → `public_id` is `char(26)` and unique, `file_sha256` has a plain (non unique) index, `user_id` is nullable with `set null`   → AC-1, AC-8, AC-9
- [x] `php artisan db:table scans` → `copyleaks_scan_id` is unique, `status` is indexed, (`submission_id`, `id`) is indexed, FK to `submissions` is `cascade`   → AC-2, AC-3, AC-7
- [x] `php artisan db:table scan_events` → unique on (`scan_id`, `status`, `kind`), FK is `cascade`, no `created_at` or `updated_at`   → AC-4, AC-7
- [x] `php artisan db:table scan_artifacts` → unique on (`scan_id`, `kind`, `source_id`), `source_id` default is empty text, no `updated_at`   → AC-5, AC-7
- [x] `php artisan db:table reports` → unique on (`scan_id`, `kind`), has `renderer_version` and `page_count`, FK is `cascade`   → AC-6, AC-7
- [x] `php artisan test --filter=DataModel` → all pass (constraints, cascades, transitions, rebuild safety)   → AC-3 to AC-8, AC-11, AC-12
- [x] `php artisan test tests/Unit/ScanStatusTest.php` → all pass (transition table and derived status)   → AC-10, AC-11
- [x] `composer lint:check && composer types:check` → both clean   → build quality

## Value sourcing (one check per row of the spec table)
Run these in `php artisan tinker` against the local database, and delete the test rows afterwards.
- [x] `Submission::factory()->create()->public_id` → 26 character ULID, and a second submission gets a different one   → Upload `public_id`
- [x] Create two submissions with the same `file_sha256` → both save   → Upload `file_sha256` (AC-8)
- [x] `Submission::factory()->create(['user_id' => null])` → saves, `user` is `null`   → Upload `user_id`
- [x] Create a scan twice with the same `copyleaks_scan_id` → second one fails with a unique error   → Create scan `copyleaks_scan_id`
- [x] `Scan::factory()->create()->toArray()` → has no `webhook_token_hash` key   → Create scan `webhook_token_hash` (never exposed)
- [x] Move a new scan with `ScanTransitioner::markWaiting()` → `submitted_at` is set only now, and was `null` before   → `submitted_at`
- [x] Move a fetching scan with `markCompleted()` → `completed_at` is set   → `completed_at`
- [x] Call `markFailed($scan, 'reason')` on a waiting scan, then `markFetching()` on it → the second call returns `false` and the reason stays   → Fail a scan `failure_reason` (AC-11)
- [x] Insert the same (`scan_id`, `status`, `kind`) event twice with `insertOrIgnore` → second returns `0`   → Save webhook (AC-4)
- [x] Create a `result` artifact with `source_id` `a` and another with `b` on the same scan → both save; a third with `a` fails   → Save bundle `source_id` (AC-5)
- [x] Give a submission a failed scan, then a waiting scan → `$submission->status` is `waiting`; `Submission::factory()->create()->status` is `not_sent`   → List page status (AC-10)
- [x] Run `ReportReplacer::replace()` with a closure that throws → old report row and file are still there   → Build report (AC-12)
- [x] Run `DeleteSubmission` on a submission whose latest scan is `waiting` → throws `SubmissionHasRunningScan` and nothing is removed   → AC-7

## Acceptance-criteria coverage
- AC-1 … covered by migration check and `public_id` step · AC-2 … `scans` check and latest scan test · AC-3 … `scans` check and `failure_reason` step · AC-4 … `scan_events` check and `insertOrIgnore` step · AC-5 … `scan_artifacts` check and `source_id` step · AC-6 … `reports` check and rebuild step · AC-7 … cascade checks and `DeleteSubmission` step · AC-8 … `file_sha256` steps · AC-9 … `submissions` and `scans` checks · AC-10 … status step and `ScanStatusTest` · AC-11 … transition steps and tests · AC-12 … rebuild step and `ReportReplacerTest`
