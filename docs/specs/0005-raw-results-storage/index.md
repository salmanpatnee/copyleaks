# 0005. Raw results storage (Copyleaks export, safe save and finish)

**Date**: 2026-11-02
**Status**: Proposed

## Summary

After Copyleaks finishes a scan, spec 0004 saves the summary and leaves the scan at `fetching`. This spec covers the rest. One export call asks Copyleaks to push every result file and the crawled text (the manuscript as Copyleaks read it) to protected addresses on our app. We save each file exactly as it arrives, check that nothing is missing, and only then mark the scan `completed` with its two scores. A sweep that runs every 5 minutes rescues stuck scans, and a separate job deletes the scan on Copyleaks afterwards. Once this is built, any report can be rebuilt from our own files with no new scan.

## Requirements

**User stories**:
- As a staff member, I want a finished scan to end up fully saved on our side so that I can rebuild its report later without paying for another scan.
- As the developer, I want every Copyleaks file stored exactly as received, so that a report bug can be fixed and the report rebuilt from the same data.
- As the developer, I want a scan that gets stuck after the summary to fix itself or fail with a clear reason, so that a paid scan is never lost silently.
- As the owner of the tool, I want the manuscript removed from Copyleaks once we hold our own copy, so that the text does not sit on a third party server longer than needed.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: When `ScanResultsArrived` fires, exactly one `StartExportJob(scanId, attempt: 1)` is queued. The caller (the listener, the completion handler or the sweep) works out the attempt number and passes it in. The job never counts attempts itself. The job claims the attempt with `insertOrIgnore` of a `scan_events` row of kind `export_started` whose `status` is the export ID `{copyleaks_scan_id}-{attempt}`, and sets `scans.copyleaks_export_id`. The payload keeps the attempt number and the expected result IDs. The claim counts as **accepted** only when Copyleaks answers `204` (or `409`, AC-11), and then `handled_at` is set. A job may go ahead while the claim exists with `handled_at` empty (this is how a retry after a timeout reaches Copyleaks), and stops if `handled_at` is already set. The job makes one export call that lists every result ID found in the saved summary (`results.internet`, `database`, `batch` and `repositories`, each item's `id`) and asks for the crawled version. It never asks for Copyleaks' own PDF report.
- **AC-2**: Copyleaks pushes each file to `POST /webhooks/copyleaks/{scanId}/{token}/export/{exportId}/result/{resultId}` and `.../export/{exportId}/text`. A new middleware `VerifyCopyleaksExportPush` (the 0004 middleware is not reused, because it allows only the words `completed` and `error`, needs `developerPayload` and caps at 10 MB) reuses the token and secret checks and then runs, in this order: an `export_started` event exists for that export ID (any known export ID of this scan is accepted, not only the latest); for a result push the `resultId` is in that event's expected list; the size is within `max_export_bytes` (10 MB) by `Content-Length` and also by the real body length, because a chunked push may send no header (answered `413` if over); the body is valid JSON (`json_validate()` on the raw string, never `json_decode()`); and last, a scan that is not `fetching` gets `200` with nothing saved (AC-5). Any other failure answers the same empty `404`.
- **AC-3**: An accepted push is saved as `scans/{copyleaks_scan_id}/result-{resultId}.json.gz` or `.../text.json.gz`. The file is written to a temp name and renamed into place. Then one `scan_artifacts` row is inserted with `insertOrIgnore` (kind `result` with `source_id` = the result ID, or kind `text` with `source_id` = `''`). The row holds the path, the SHA 256 and the size of the compressed file. The answer is `200`.
- **AC-4**: The saved file, once unzipped, is byte for byte the body Copyleaks sent. Nothing is parsed, trimmed or re-encoded. A repeated push for a row that already exists answers `200`, changes nothing, and rewrites the file only if it is missing from disk.
- **AC-5**: A push for a scan that is not `fetching` (already `completed` or `failed`) answers `200` and saves nothing, so Copyleaks stops retrying. A push for a scan or export that does not exist answers the empty `404`.
- **AC-6**: The completion call `POST /webhooks/copyleaks/{scanId}/{token}/export/{exportId}/completed` passes the same token, secret and size checks, and `developerPayload` must equal the scan ID. It records one `export_completed` event (status = export ID) with a cleaned payload: the `completed` flag, and per task only `isHealthy` and `httpStatusCode`. Task endpoints are never saved, because they carry the token. Then it runs the finish check (AC-7). If the check fails, only the completion call of the **latest** export ID may start a retry (AC-9). A completion call of an older export ID is recorded and runs the finish check, nothing more. A repeat of the same export ID changes nothing.
- **AC-7**: The finish check looks at our files only. It passes when the `text` artifact exists and an artifact exists for every expected result ID. An unhealthy task in a completion call is logged when it happens, but never blocks a finish when every file is present. When the check passes, one database transaction moves the scan `fetching` to `completed` through `ScanTransitioner` and, in that same update, sets `completed_at`, `similarity_percent` from `results.score.aggregatedScore` of the saved summary, and `ai_percent` (AC-8). `ScanTransitioner::markCompleted()` gains an attributes argument for this. Scores are rounded to 2 decimals for `decimal(5,2)`. `DeleteRemoteScanJob` is queued after the commit, and only when this call's move returned true, so two finishers never queue two deletes (AC-12). A second pass finds the scan already completed and does nothing.
- **AC-8**: `ai_percent` is the `Ai` value times 100 (the key is read without caring about case), taken from the `suspected-ai-text` alert in the saved summary (`notifications.alerts[].additionalData`, a JSON string). If `ai_requested` is true but there is no such alert, or `additionalData` is not valid JSON or has no `Ai` value, the scan still completes with `ai_percent` empty and one warning log with the scan ID. A number is never invented.
- **AC-9**: If the finish check fails after the completion call of the latest export ID (a file is missing), the next attempt starts at once by queuing `StartExportJob` with the next attempt number (the handler works it out, AC-1). Only attempts that Copyleaks accepted (`handled_at` set) count toward the limit of 3. Files already saved are kept, because they are insert only, so a retry only fills the gaps. The scan moves to `failed` with the fixed reason `Export incomplete after 3 tries. The summary is kept.` only when 3 accepted attempts have each ended (a completion call arrived, or 15 minutes passed) and the finish check still fails. The finish check always runs before any fail. The summary artifact is never deleted.
- **AC-10**: `php artisan copyleaks:sweep-fetching` runs every 5 minutes and acts on each `fetching` scan. It never creates a scan and never calls Submit. In this order: (1) if the finish check passes, finish the scan; (2) more than 48 hours since the `completed` status event of 0004 means `failed` with `The files could not be stored in time.`; (3) if no `export_started` event exists, queue attempt 1; (4) if the latest accepted attempt has an `export_completed` event, or was accepted more than 15 minutes ago, queue the next attempt, or fail as in AC-9 after the third; (5) if the latest claim was never accepted (`handled_at` empty) and is older than 15 minutes, queue the same attempt number again, which does not count toward the 3. If nothing was ever accepted, only the 48 hour rule ends the scan. Fifteen minutes always means "start the next attempt", never "fail". Pushes of earlier attempts are still accepted while the scan is `fetching`.
- **AC-11**: The export call is handled by its answer. `204` is started. `409` (the export ID is already known, likely after a timeout that Copyleaks did accept) is also treated as started. `404` (the scan expired or was deleted) moves the scan to `failed` with `Copyleaks no longer has this scan.` A connection error, a timeout, `429` or a `5xx` retries the job up to 3 times with backoff on the same export ID and never moves the scan. Any other `4xx` (including a `401` after the login retry) ends that attempt with a logged code and counts as an accepted attempt that failed, so the normal retry rules apply.
- **AC-12**: `DeleteRemoteScanJob` has `tries = 1`. It calls Copyleaks to delete the scan once, after the scan is `completed`. A `202` sets `scans.remote_deleted_at`. Any other outcome, including `ScanClientUnavailable`, writes one log line and changes nothing, and the scan stays `completed`. The 72 hour expiry we already send is the backup. The job never runs for a scan that is not `completed`. A scan that ends `failed` is not deleted on Copyleaks and relies on the same 72 hour expiry.
- **AC-13**: Export and delete calls go only through `ScanClient` (new methods `startExport()` and `deleteScan()`), with `FakeScanClient` scripts for both, and tests never reach Copyleaks. An architecture test proves that `StartExportJob`, `DeleteRemoteScanJob` and the sweep never dispatch `SubmitScanJob` or call `SubmitScan`.
- **AC-14**: Logs carry only the scan ID, the export ID, the attempt, a part word (`result`, `text`, `completed`) and a status word. Never a push body, a token, a secret, a task endpoint or any manuscript text.
- **AC-15**: Proof of rebuild: a test completes a scan from fake pushes, then takes a snapshot of the fake client's call count (`FakeScanClient` gains a reset or snapshot method, because `assertNothingSent()` counts every call since the start) and fakes the queue for the delete job. It reads the `summary`, `text` and every `result` file back from the artifact rows, with checksums matching, and shows no new Copyleaks call after the snapshot. A report feature can therefore be built from these files alone.
- **AC-16**: The saved scan page keeps refreshing while the latest scan is `fetching` and stops at `completed` or `failed`, so staff see the finished state without a reload. This changes the 0004 rule that stopped refreshing at `fetching`.

## Decision

**Chosen option**: Option 1: Push export, with our own completeness check and a sweep.

Ask Copyleaks to push every result file and the crawled text to protected webhook addresses in one export call. Save each file exactly as received. Finish the scan only after our own check shows every expected file is present, retry the export up to 3 times, and rescue stuck scans with a 5 minute sweep. No new tables or columns are needed.

**Implementation skills**: none. The project has no installed skill for this area (`.agents/skills/` holds only `shadcn-vue`, which is UI only).

## Feature design

**Data model sketch**:

No migration. Everything fits the tables from spec 0002.

| Table | What this feature writes |
| --- | --- |
| `scan_artifacts` | `result` rows (`source_id` = Copyleaks result ID, up to 64 characters) and one `text` row (`source_id` = `''`). Insert only. Unique on (`scan_id`, `kind`, `source_id`). |
| `scan_events` | New `EventKind::ExportStarted` (`export_started`, status = export ID, payload = attempt number and expected result IDs). Existing `export_completed` (status = export ID, cleaned payload). Unique on (`scan_id`, `status`, `kind`), so one claim per attempt and one completion per attempt. |
| `scans` | `copyleaks_export_id` (current export ID), `status`, `completed_at`, `similarity_percent`, `ai_percent`, `failure_reason`, `remote_deleted_at`. All exist already. |

Files on the private `local` disk: `scans/{copyleaks_scan_id}/result-{resultId}.json.gz` and `scans/{copyleaks_scan_id}/text.json.gz`, next to the existing `summary.json.gz`.

**State transitions**:

`fetching` to `completed` (finish check passes, AC-7) or `fetching` to `failed` (AC-9, AC-10, AC-11). `completed` and `failed` never move again (spec 0002). Per export attempt: `export_started`, then pushes, then `export_completed`, then either finish or the next attempt.

**API surface** (all public `POST`, outside the web group, same rules as the 0004 webhook):

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `/webhooks/copyleaks/{scanId}/{token}/export/{exportId}/result/{resultId}` | POST | JSON body, `X-Webhook-Secret` header | `200` empty | token in path plus secret header | `404` any failed check, `413` over 10 MB |
| `/webhooks/copyleaks/{scanId}/{token}/export/{exportId}/text` | POST | JSON body, secret header | `200` empty | same | same |
| `/webhooks/copyleaks/{scanId}/{token}/export/{exportId}/completed` | POST | `completed`, `developerPayload`, `tasks[]` | `200` empty | same | `404`, `413` |
| `php artisan copyleaks:sweep-fetching` | cron, every 5 min | none | log lines | local only | none |

Outbound calls through `ScanClient`:

| Method | Copyleaks call | Meaning |
| --- | --- | --- |
| `startExport(ExportRequest)` | `POST /v3/downloads/{scanId}/export/{exportId}` | `204` started. `404` scan gone. Connection error, timeout or `5xx` raises `ScanClientUnavailable`. The request carries `results[]` (id, verb `POST`, endpoint, headers with the secret), `crawledVersion` (same shape), `completionWebhook`, `maxRetries` 3 and `developerPayload` = scan ID. |
| `deleteScan(string)` | `PATCH /v3.1/scans/delete` with one scan, `purge` false | `202` accepted. Anything else is reported back as not deleted. |

**Value sourcing**:

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Start export | Export ID | Derived: `{copyleaks_scan_id}-{attempt}`. The attempt number is worked out by the caller (listener, completion handler or sweep) from the accepted `export_started` events plus one, and passed into `StartExportJob`. The job never counts |
| Start export | List of result IDs | The saved `summary` artifact: `id` of each item in `results.internet`, `database`, `batch`, `repositories` |
| Start export | Push addresses | Built from `CopyleaksSettings::webhookBase()`, the scan ID, the token from `WebhookToken`, the export ID and the result ID, by joining strings as `WebhookUrlBuilder` does |
| Start export | Secret header | `COPYLEAKS_WEBHOOK_SECRET` through `CopyleaksSettings` |
| Start export | Auth token for Copyleaks | Existing login and cache in `CopyleaksScanClient` |
| Accept a push | Expected result IDs | Payload of the `export_started` event for that export ID (AC-1) |
| Accept a push | File path, SHA 256, size | Path built by the app; hash and size of the compressed bytes after writing |
| Finish | `similarity_percent` | `results.score.aggregatedScore` in the saved summary (already a percent, for example `1.3`) |
| Finish | `ai_percent` | `summary.Ai` times 100 from the `suspected-ai-text` alert's `additionalData` in the saved summary; empty when absent |
| Finish | `completed_at` | `now()` at the finish transaction |
| Sweep | Time the scan entered `fetching` | `received_at` of the scan's `completed` status event (spec 0004). `scans.updated_at` is not used because other writes change it |
| Sweep | Time an attempt started | `received_at` of its `export_started` event |
| Delete | Scan to delete | `scans.copyleaks_scan_id` |

**Key invariants**:
- Raw files are insert only and never edited. A repeat never overwrites an existing row or file.
- A scan is `completed` only when the `summary`, the `text` and every expected `result` artifact exist and `similarity_percent` is set. `ai_percent` may be empty only if no AI alert was returned.
- At most one export attempt per export ID (unique event row). At most 3 attempts per scan.
- Nothing here starts a scan. The export and delete jobs, the sweep and the pushes never reach `SubmitScan`.
- Only `ScanTransitioner` changes `scans.status`.
- Manuscript text appears only in the private files, never in logs, events or the browser.

**Security model**: Internal tool, no staff access rules yet (Slice 1). The three public addresses use the same layers as spec 0004: the token in the path (compared with the stored hash), the secret header, a size check, a throttle, and one identical empty `404` for every failed check. Pushes carry the manuscript text, which is personal data of authors, so files stay on the private disk and are never logged. A new throttle `copyleaks-export` allows 600 calls per minute per scan, because the existing 30 per minute would block a scan with hundreds of results.

**Configuration required**:
- No new `.env` keys. New `config/copyleaks.php` entries: `max_export_bytes` (10 MB, the same as today's webhook cap; confirm the real size in the sandbox check and raise it only if a real file needs it), `export_max_attempts` (3), `export_stuck_minutes` (15), `export_give_up_hours` (48), `sweep_every_minutes` (5).
- Hostinger: `post_max_size` at least 12 MB and `memory_limit` at least 256 MB for the web PHP. Laravel decodes a JSON body into an array before our code runs, so a push can use several times its size in memory. We validate the raw string with `json_validate()` and gzip it, and never call `json_decode()` on it.

**Critical test scenarios** (each maps to an acceptance criterion above):
- Happy path: a `completed` webhook leads to `StartExportJob`, the fake client sees one export call with all result IDs and no PDF report, three pushes (two results and the text) and a healthy completion call finish the scan with both scores and queue the delete, verifies **AC-1**, **AC-3**, **AC-6**, **AC-7**, **AC-12**
- Failure case: the completion call arrives with one result missing, attempt 2 starts with a new export ID, and a third failure moves the scan to `failed` while the summary file stays, verifies **AC-9**
- Failure case: a push with a wrong token, a wrong secret, an unknown result ID, an unknown export ID or invalid JSON gets the empty `404`, and a push over 10 MB (by header, or by real length when the header is missing) gets `413`; nothing is saved in any case, verifies **AC-2**, **AC-14**
- Failure case: a retry of the export job after a timeout reaches Copyleaks again on the same export ID, a `409` is treated as started, a `429` retries, and a short outage never uses up the 3 attempts, verifies **AC-1**, **AC-11**
- Failure case: a late completion call from attempt 1 after attempt 2 started does not queue another attempt, and a scan with every file at hour 49 finishes instead of failing, verifies **AC-9**, **AC-10**
- Integrity: an unhealthy task in the completion call with every file present still finishes the scan, verifies **AC-7**
- Failure case: the same push sent twice leaves one row and one file; a push after the scan is `completed` answers `200` and saves nothing, verifies **AC-4**, **AC-5**
- Failure case: a scan stuck at `fetching` with no export, or with a stale attempt, is picked up by the sweep; one older than 48 hours fails, verifies **AC-10**
- Failure case: Copyleaks answers `404` on the export call, the scan fails with the fixed reason; a `5xx` retries without moving the scan, verifies **AC-11**
- Edge case: a summary with no AI alert and `ai_requested` true completes with an empty `ai_percent` and one warning, verifies **AC-8**
- Edge case: the delete call fails or the scan is not completed; the scan stays as it was, verifies **AC-12**
- Integrity: byte for byte round trip of a pushed body, checksums match, and the rebuild proof with no client call, verifies **AC-4**, **AC-15**
- Architecture: no code path from the export, delete or sweep code to `SubmitScan` or `SubmitScanJob::dispatch`, verifies **AC-13**

## Build plan

Approach: Tracer Bullet. Build the thinnest real thread first on the fake client (listener, export job, one push, completion, finished scan), then thicken one strand at a time. No migration is needed. Each step carries its own Pest tests.

1. Client seam: add `startExport()` and `deleteScan()` to `ScanClient` with request and result classes, the real calls in `CopyleaksScanClient` (guarded in `testing` like the others), scripting in `FakeScanClient`, the `ExportStarted` event kind, and the new config entries, satisfies **AC-13**
2. Thin thread on the fake client: a listener for `ScanResultsArrived` queues `StartExportJob`, which claims attempt 1 and calls the client; one `text` push, one `result` push and the `completed` call are saved and the finish check marks the scan `completed` with both scores, satisfies **AC-1**, **AC-3**, **AC-6**, **AC-7**, **AC-8**
3. Thicken the pushes: the new `VerifyCopyleaksExportPush` middleware with the full ordered set of checks, the size rule (header and real length), the new throttle, exact byte storage, repeats, and pushes for scans that are not `fetching`, satisfies **AC-2**, **AC-4**, **AC-5**, **AC-14**
4. Thicken the failures: the claim accepted only on `204` or `409`, attempt numbers passed in by the caller, only the latest export ID starting a retry, the 3 accepted attempts rule, the fixed failure reason, and every export call answer (`404`, `409`, `429`, `5xx`, other `4xx`), satisfies **AC-1**, **AC-9**, **AC-11**
5. The sweep command and its schedule entry (add the one line to `routes/console.php`; also check the queue worker line from spec 0001 is there and add it if not), finish check first, then the 48 hour deadline, satisfies **AC-10**
6. `DeleteRemoteScanJob` (`tries = 1`) queued after the finish only when the completed move returned true, with `remote_deleted_at` and log only failures, satisfies **AC-12**
7. Page polling: keep refreshing during `fetching` and stop at `completed` or `failed`, satisfies **AC-16**
8. Pest tests for every scenario above, the architecture test, the `FakeScanClient` snapshot method and the rebuild proof, satisfies **AC-13**, **AC-14**, **AC-15**
9. One sandbox check by hand with a one page PDF that has real text (no credits spent): confirm the export is accepted in the sandbox, the export ID format is allowed, Copyleaks sends our secret header on pushes, and what a result and the crawled version really look like, then record the findings in this spec, satisfies **AC-1**, **AC-3**, **AC-6**

## Consequences

**Positive**:
- A finished scan holds everything a report needs. Reports can be rebuilt any number of times with no new scan.
- No schema change, and no new infrastructure beyond one cron line already planned.
- A crash, a lost call or a bad push never loses a paid scan silently: it self heals or fails with a reason, and the summary is always kept.
- The manuscript leaves Copyleaks soon after we hold our own copy.

**Negative / tradeoffs**:
- Pushes carry the manuscript, so the web PHP limits on Hostinger must be raised (`memory_limit` 256 MB). A file over the 10 MB cap fails every retry and ends in `failed`. We accept this because manuscripts are limited to 100 pages by spec 0003, and the sandbox check confirms the real sizes.
- Shared hosting limits how many requests run at once, so Copyleaks' parallel pushes may sometimes get a `503`. The export's own retries cover it. The push throttle is keyed on the scan ID in the path and runs before the token check, so anyone who knows a scan ID could use up that scan's push allowance.
- A scan that ends `failed` is not deleted on Copyleaks. The manuscript there goes away at the 72 hour expiry.
- The scan page now keeps refreshing during `fetching` (AC-16), so it makes a few more requests than before.
- A scan can wait up to a minute for the next cron run at several steps (queue start, retry, sweep), so finishing can take a few minutes.
- We trust our own completeness check instead of only Copyleaks' flag. It needs the result IDs from the summary, so a changed summary shape would stop scans from finishing. The sandbox check (step 9) guards this.
- The delete step has no retry: a failed delete is only logged and the scan expires on its own at 72 hours. The `remote_deleted_at` column would allow a later retry sweep.
- No reader class: each report feature reads and checks the files itself. That repeats the unzip and checksum code unless a later spec adds a shared reader.
- The export may cost credits. This is unconfirmed (see Follow-up).

**Neutral**:
- `EventKind` gains one case. `ScanResultsArrived` finally gets its first listener.
- The 48 hour give up time leaves 24 hours before Copyleaks removes the scan at 72 hours.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Follow-up

- [ ] Sandbox check by hand (build step 9). Also confirm the real size of the files against the 10 MB cap. Confirm: the export call works in the sandbox; the export ID `{scan_id}-{n}` (28 characters) is accepted; the `pdfReport` field can be left out; Copyleaks sends the custom `X-Webhook-Secret` header on pushes; the real size and shape of a result and of the crawled version; whether the export or the delete costs credits.
- [ ] Confirm the Hostinger PHP limits (`post_max_size`, `upload_max_filesize`, `memory_limit`) for the web PHP, not only the CLI one.
- [ ] Decide later whether a shared `ScanBundle` reader is worth adding once features 10 to 13 repeat the same unzip and checksum code.
- [ ] Retention (90 days placeholder from spec 0001) still needs its own job. This feature only deletes the remote copy.
- [ ] Spec 0004 Follow-up items for feature 7 are closed here: listener on `ScanResultsArrived`, own export ID, the AI value read from the alert, and the sweep for scans stuck at `fetching`. Update `app/Services/Copyleaks/AGENTS.md` with the new jobs, routes and the sweep once built (the sync step owns that file).
- [ ] The `Done when` line of feature 7 says "a PDF can be rebuilt from them". The rebuild proof (AC-15) shows the data is there; the PDF itself comes with features 8 to 13.
- [ ] If a real scan shows `cheatDetection` still off, feature 22 has its own check. Not part of this spec.
