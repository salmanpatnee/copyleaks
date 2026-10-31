# 0002. Data model for submissions, scans, raw results and reports

**Date**: 2026-10-31
**Status**: Accepted

## Summary

We store the work in five MySQL tables: submissions (the upload and its cover details), scans (each try at asking Copyleaks), scan events (each webhook call), scan artifacts (the raw Copyleaks files, never changed) and reports (the finished PDFs). One submission can have many scans, so a retry adds a scan and never a duplicate submission. The model is built once, whole, so later slices (login, cover counts, the AI report) only fill columns that already exist.

## Requirements

**User stories**:
- As a staff member, I want my upload saved with its author, title and assignment so that the cover page can show them.
- As a staff member, I want to retry a failed scan so that I do not create a second submission or lose why the first try failed.
- As a developer, I want raw Copyleaks replies stored unchanged so that any report can be rebuilt without paying for another scan.

**Acceptance criteria**:
- **AC-1**: A submission stores the uploaded file facts (original name, path, size, SHA 256, page count) and the three cover details (author, title, assignment), all three required and at most 255 characters. It gets a unique `public_id` (ULID) at upload.
- **AC-2**: A submission has many scans. A retry adds a new scan row with a new `copyleaks_scan_id` and never a new submission. The latest scan is the one with the highest `id`.
- **AC-3**: A scan has one of five statuses: `submitting`, `waiting`, `fetching`, `completed`, `failed`. `copyleaks_scan_id` is unique. A `failed` scan keeps a `failure_reason`.
- **AC-4**: A webhook delivery is recorded once per (scan, status, kind). Saving the same delivery twice is rejected by the database, so repeats and out of order calls are safe.
- **AC-5**: Raw artifacts (summary, result, text) are insert only, each with a path, SHA 256 and size. There is no update path in the app, and the table has no `updated_at`.
- **AC-6**: A scan has at most one report per kind (`similarity`, `ai`). Rebuilding replaces the row and the file. A report row records the renderer version and page count.
- **AC-7**: Deleting a submission removes its scans, events, artifacts and reports (database cascade). The delete action removes the files on disk after the rows are gone, and refuses while a scan is still running.
- **AC-8**: The same file checksum may appear on many submissions (an indexed lookup, not a unique rule), so staff can override and rescan on purpose.
- **AC-9**: No later slice needs a new column on these tables: `user_id`, the word and character counts, the scores and the `ai` report kind already exist and are nullable or open.
- **AC-10**: Submission status for the list is worked out from the latest scan (no scan = `not_sent`; `submitting`, `waiting`, `fetching` = `waiting`; `completed` = `done`; `failed` = `failed`), not stored. Upload saves only the submission, so `not_sent` is a real state until staff press Submit. The first list shows status and does not filter by it.
- **AC-11**: Every scan status change is one guarded update that only applies when the scan is still in an allowed earlier state. A change that matches no row is skipped quietly. A `completed` or `failed` scan can never move again.
- **AC-12**: A report rebuild writes the new PDF first, then switches the row, then deletes the old file. A failed build leaves the old report untouched.

## Decision

**Chosen option**: Option 1: five normalized tables.

One migration creates `submissions`, `scans`, `scan_events`, `scan_artifacts` and `reports`, with Eloquent models, backed enums and factories.

**Implementation skills**: none used. The shadcn-vue skill does not touch the data layer.

## Feature design

**Data model sketch**:

```
users 1 ──< N submissions 1 ──< N scans 1 ──< N scan_events
 (optional link)                     ├──< N scan_artifacts   (insert only)
                                     └──< N reports          (max 1 per kind)
```

| Table | Columns | Keys and rules |
| --- | --- | --- |
| `submissions` | `id`, `public_id` char(26), `user_id` nullable, `author` 255, `title` 255, `assignment` 255, `original_name` 255, `file_path`, `file_size` unsigned bigint, `file_sha256` char(64), `page_count` unsigned int, `word_count` nullable, `character_count` nullable, timestamps | `public_id` unique. `user_id` FK to `users`, `nullOnDelete`. Plain index on `file_sha256`. `created_at` is the upload date. |
| `scans` | `id`, `submission_id`, `copyleaks_scan_id` string(36), `copyleaks_export_id` string(36) nullable, `status` string(20), `failure_reason` text nullable, `webhook_token_hash` char(64), `ai_requested` bool default false, `similarity_percent` decimal(5,2) nullable, `ai_percent` decimal(5,2) nullable, `is_sandbox` bool, `submitted_at` nullable, `completed_at` nullable, `remote_deleted_at` nullable, timestamps | `copyleaks_scan_id` unique. FK to `submissions`, cascade. Index on `status`. Index on (`submission_id`, `id`), which also serves the foreign key, so no separate index. |
| `scan_events` | `id`, `scan_id`, `kind` string(20) (`status`, `export_completed`), `status` string(30), `payload` json, `received_at`, `handled_at` nullable | Unique on (`scan_id`, `status`, `kind`). FK cascade. No `created_at` or `updated_at`. `handled_at` means the job finished processing the event, so unprocessed events can be found. |
| `scan_artifacts` | `id`, `scan_id`, `kind` string(20) (`summary`, `result`, `text`), `source_id` string(64) not null default `''`, `path`, `sha256` char(64), `size_bytes` unsigned bigint, `created_at` | Unique on (`scan_id`, `kind`, `source_id`). `source_id` is `''` for `summary` and `text`, so the unique rule really works (NULLs would count as different). FK cascade. No `updated_at`. |
| `reports` | `id`, `scan_id`, `kind` string(20) (`similarity`, `ai`), `path`, `sha256` char(64), `size_bytes`, `page_count`, `renderer_version` string(20), `built_at` | Unique on (`scan_id`, `kind`). FK cascade. No `created_at` or `updated_at`. |

**Column conventions**: status and kind columns are plain `string` columns with backed enum casts (no MySQL `ENUM`). `scan_events.status` stays an open string because Copyleaks values are not ours to fix. All times are UTC. `public_id` is made with `HasUlids` limited to that column (`uniqueIds()` returns `['public_id']`) and is the route key, so the numeric `id` never reaches the browser. Submission files live under `submissions/{public_id}/`. The "submission ID" printed on the cover in Slice 3 is `public_id`. `latestScan` uses `latestOfMany()` (highest `id`).

**State transitions** (scan):

Allowed moves (anything else is refused):

| From | To | Set by |
| --- | --- | --- |
| `submitting` | `waiting` | Submit job, when Copyleaks accepts (also when it replies "duplicate scan ID", meaning it was already sent) |
| `submitting` | `fetching` | Webhook job or "fetch results" recovery, when the completion arrives before `waiting` was saved or the scan is stuck |
| `waiting` | `fetching` | Webhook job or "fetch results" recovery |
| `fetching` | `completed` | Bundle job, after all artifacts are saved |
| `submitting`, `waiting`, `fetching` | `failed` | Whichever job hit the error |

- `completed` and `failed` are terminal. A retry creates a new scan.
- `submitting` is saved before the Copyleaks call, by the Submit action (not by upload). `submitted_at` is set in the same update that moves to `waiting`, never before the call. `completed_at` is set with `completed`.
- Every move is one conditional update: `UPDATE scans SET status = ? WHERE id = ? AND status IN (allowed from states)`, then check the changed row count. Zero rows means another job got there first, so skip. One `ScanTransitioner` class owns this, with the table above as a `canMoveTo()` map on `ScanStatus`.
- A scan stuck in `submitting` is never retried blindly. Staff use the read only "fetch results" action from spec 0001.

**API surface**: none. This spec adds no routes. Features 4, 6 and 7 add them.

**Value sourcing** (where each stored value comes from):
| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Upload | `public_id` | Generated by the app (ULID) at upload |
| Upload | `file_sha256`, `file_size` | Computed from the uploaded bytes |
| Upload | `page_count` | PDF import library, the check spec 0001 already requires before submit |
| Upload | `author`, `title`, `assignment` | Form input (feature 5) |
| Upload | `original_name` | The client file name, stored as text only, never used as a path |
| Upload | `file_path` | Generated by the app on the private `local` disk under `submissions/{public_id}/`, never from user input |
| Upload | `user_id` | Signed in user. `null` in Slice 0, filled from Slice 1 |
| Create scan | `copyleaks_scan_id` | Generated by the app, 3 to 36 characters, lowercase (Copyleaks may reject uppercase, confirm in feature 6), once per scan |
| Create scan | `ai_requested` | Whether the Submit action asked Copyleaks for the AI check |
| Start export | `copyleaks_export_id` | Generated by the app. A recovery re-export gets a fresh one |
| After bundle saved | `remote_deleted_at` | Set when the Copyleaks delete call succeeds, so the retention job does not repeat it |
| Submit page | Estimated credit cost | `page_count` times an estimate for now (word count stays empty until Slice 3). Not stored. The staff override for a repeated file is not recorded |
| Create scan | `webhook_token_hash` | SHA 256 of a random token of 32 bytes or more. The plain token goes only into the webhook URL sent to Copyleaks and is never stored or logged |
| Create scan | `is_sandbox` | `COPYLEAKS_SANDBOX` config at submit time |
| Fail a scan | `failure_reason` | Copyleaks error code and message, or our own error text. Never manuscript text or secrets |
| Save webhook | `kind`, `status`, `payload` | The callback route and request body. Only status webhooks and the final "export completed" call become events. Payload is status data only. Allowed `status` values per kind are fixed in feature 6. Duplicates are ignored with `insertOrIgnore` or by catching the unique violation |
| Save bundle | `path`, `sha256`, `size_bytes` | Path generated by the app (`scans/{copyleaks_scan_id}/…`). Checksum and size are of the compressed file as stored. Export result pushes (they carry manuscript text) write straight to `scan_artifacts`, never to `scan_events` |
| Save bundle | `source_id` | The Copyleaks result ID for `result` files, otherwise `''` |
| Complete a scan | `similarity_percent`, `ai_percent` | Copied from the saved completion summary. Exact field names are fixed in the Copyleaks scan spec (feature 6) |
| Build report | `path`, `sha256`, `size_bytes`, `page_count` | Output of the PDF engine |
| Build report | `renderer_version` | A constant in the PDF engine, bumped when the layout changes |
| List page | Submission status | Derived from the latest scan (AC-10) |
| Slice 3 | `word_count`, `character_count` | Counted by us from the PDF text, or from Copyleaks if it supplies them. Decided in feature 21 |

**Key invariants**:
- Raw artifacts are never updated by anything. Only the delete action and the retention job remove them.
- Reports are built only from artifacts, never from a live Copyleaks call.
- A scan whose status is `completed` has a `summary` artifact and a non null `similarity_percent`. `ai_percent` is required only when `ai_requested` is true.
- `webhook_token_hash` is never exposed to the browser or logs.
- The delete action refuses while the latest scan is `submitting`, `waiting` or `fetching`. It deletes the rows in a transaction (cascade does the child rows), then deletes the folders `submissions/{public_id}/` and `scans/{copyleaks_scan_id}/` after commit. A leftover file is harmless and the retention job sweeps it. Database cascade alone does not remove files.
- Scan jobs set `$deleteWhenMissingModels = true`, so a job for a deleted scan is dropped quietly.
- A report is rebuilt to a new path (`reports/{scan_id}/{kind}-{renderer_version}-{timestamp}.pdf`), the row is switched with `updateOrCreate` on (`scan_id`, `kind`), and the old file is deleted only after commit.
- Money safety: nothing in the model, tests or factories may call Copyleaks. Factories create rows only.

**Security model**: Internal staff tool. The model has no access rules of its own. Slice 1 adds who may see what. Files live on the private disk and are served only through a signed in route. Manuscript text is personal data of authors. Do not log it, and keep artifacts only for the retention period in spec 0001.

**Configuration required**: none new. Uses `COPYLEAKS_SANDBOX` from spec 0001.

**Critical test scenarios**:
- Happy path: create a submission with a scan, three artifacts and a report; relations load, latest scan is the highest `id`, derived status is correct, verifies **AC-1**, **AC-2**, **AC-10**
- Failure case: inserting a duplicate (`scan_id`, `status`, `kind`) event, a duplicate `copyleaks_scan_id`, or a second report of the same kind fails, verifies **AC-3**, **AC-4**, **AC-6**
- Failure case: a `completed` scan cannot move back to `fetching`, and a second job's move is skipped with no error, verifies **AC-11**
- Failure case: a rebuild that throws midway leaves the old report row and file intact, verifies **AC-12**
- Failure case: inserting a second `summary` artifact for the same scan fails, verifies **AC-5**
- Failure case: two submissions with the same `file_sha256` can be saved, verifies **AC-8**
- Integrity: deleting a submission removes all child rows; the artifact model offers no update path and has no `updated_at`, verifies **AC-5**, **AC-7**
- Permission: not applicable in this feature (no routes). Covered by Slice 1.

## Build plan

Approach: Tracer Bullet. The model is small and the tables depend on each other, so it is one migration, built whole, so the first thin thread (upload, scan, save, PDF) never waits on a later migration.

1. Create one migration for the five tables, with the keys, unique rules, indexes and cascades above, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-9**
2. Add backed enums `ScanStatus` (with the `canMoveTo()` map), `EventKind`, `ArtifactKind`, `ReportKind`, and a derived `SubmissionStatus`, plus a `ScanTransitioner` that does the guarded update, satisfies **AC-3**, **AC-10**, **AC-11**
3. Add Eloquent models with typed relations, casts and factories (`Submission`, `Scan`, `ScanEvent`, `ScanArtifact`, `Report`). Add a `latestScan` relation and a status accessor on `Submission`. `ScanArtifact` has no `updated_at` and blocks updates, satisfies **AC-2**, **AC-5**, **AC-10**
4. Write Pest tests for constraints, cascades, guarded transitions, relations and derived status using the in memory sqlite database. Include a cascade test that fails if foreign keys are off, satisfies **AC-4**, **AC-5**, **AC-6**, **AC-7**, **AC-8**, **AC-11**, **AC-12**
5. Check the finished schema against Slices 1 to 5 (login, counts, AI report) and confirm none needs a new column, satisfies **AC-9**

## Consequences

**Positive**:
- Retries keep full history, and the raw bundle plus saved PDFs mean old reports never need a new scan.
- Later slices fill existing columns, so no breaking migration on paid data.
- Constraints in the database (unique, cascade) protect the rules even if app code is wrong.

**Negative / tradeoffs**:
- Five tables and a join for the latest scan is more than a flat table. Worth it for retry history and safe webhooks.
- `similarity_percent` and `ai_percent` are copies of values inside the raw bundle. They can go stale if the copy code has a bug. The bundle is the truth, and the numbers can be recomputed from it.
- Hard delete means no undo. The retention job must be careful and delete files before rows.
- Files and rows can drift apart (a file missing on disk). The checksum lets us detect it, but nothing repairs it automatically.

**Neutral**:
- Submission status is worked out at read time, so the list query joins the latest scan.
- JSON payload in `scan_events` is small status data. MySQL stores it natively, sqlite handles it in tests.

## Follow-up

- [ ] Approved difference from spec 0001: it says uploads have a unique SHA 256 checksum. This spec makes it a normal index plus an app check with staff override. Builders follow this spec (no unique index). Reword that line in spec 0001 next time it is touched.
- [ ] In feature 6, fix the allowed `scan_events.status` values per kind, confirm the Copyleaks scan ID character rules (lowercase), and confirm the export callbacks.
- [ ] Confirm the exact Copyleaks field names for the two scores in the Copyleaks scan spec (feature 6), and whether the AI score is per document or per section.
- [ ] Retention (90 days placeholder) needs its own job and a decision on whether reports follow raw bundles. Not in this feature.
- [ ] Decide in feature 21 how word and character counts are produced.
- [ ] Laravel's sqlite config turns foreign keys on by default (`DB_FOREIGN_KEYS`). Keep a cascade test that would fail if that were off.
