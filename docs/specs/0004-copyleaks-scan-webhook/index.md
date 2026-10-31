# 0004. Copyleaks scan submit and result webhook

**Date**: 2026-10-31
**Status**: In Progress

## Summary

When staff press Submit on a saved PDF, the app creates one scan, and a background job sends the file to Copyleaks (asking for the similarity check, the AI check and the integrity alerts in that one scan). Copyleaks later calls our webhook (a web address it calls when work finishes). The webhook saves the exact result summary first, then records the call and moves the scan forward in one database step, so a slow or repeated call cannot cost credits twice. This feature ends once the summary is saved and the scan is marked as fetching. Feature 7 downloads the full files and finishes the scan.

## Requirements

**User stories**:
- As a staff member, I want to press Submit on a saved manuscript so that Copyleaks checks it exactly once.
- As a staff member, I want to see the scan move from waiting to done or failed without reloading, so that I know when the result is ready.
- As a staff member, I want a way to ask Copyleaks to send a missed result again, so that a lost call never means a lost paid scan.
- As the developer, I want no scan to start from anywhere except a person pressing Submit, so that limited credits are never spent by a loop, a test or a retry bug.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: The saved page shows a Submit panel only for a PDF submission that has no scan, or whose latest scan is `failed`. The panel shows an estimated credit cost, an "AI check" checkbox ticked by default, and, when a duplicate exists, a link to the newest earlier submission plus an override checkbox. A Word submission shows no panel (its `estimated_credits` is null), and the server refuses it with a clear message.
- **AC-2**: One Submit press creates exactly one `scans` row (status `submitting`) and queues exactly one submit job. The lock on the submission, the running scan check, the duplicate check, the scan insert and the job dispatch all happen inside one database transaction, so the job row (same database queue) commits or rolls back together with the scan. A submission whose latest scan is `submitting`, `waiting` or `fetching` is refused.
- **AC-3**: A duplicate means a scan on any submission with the same `file_sha256` whose status is not `failed` and whose `is_sandbox` is false. Submit is refused until the override box is ticked. The override tick is not stored. When the new scan is a sandbox scan (it costs nothing), the duplicate check is skipped.
- **AC-4**: The scan gets a lowercase scan ID of 26 characters made from a ULID (it can never match the pattern Copyleaks rejects, because a ULID has no `L`), a webhook token of 32 bytes derived from the scan ID and `APP_KEY` (never stored, only its SHA 256 hash is), `ai_requested` from the checkbox and `is_sandbox` from config.
- **AC-5**: The submit job carries only the scan's numeric id and re-reads the scan, its submission and the file. It exits quietly if the scan is no longer `submitting`. It sends one `PUT` with the file as base64, the file name, the options in AC-6, the status webhook address, a secret header and a `developerPayload` equal to the scan ID. On `201` the scan moves to `waiting` and `submitted_at` is set in the same update. On `409` (already sent) it moves to `waiting` too. If the file is missing or unreadable, the scan fails with the fixed reason "The saved file could not be read." and nothing is sent.
- **AC-6**: Every scan sends these options and no others: `sandbox` from config, `aiGeneratedText.detect` from the checkbox with `sensitivity` 2 and `explain` off, `cheatDetection` on, `scanning.internet` on, no shared database search, nothing indexed, no `exclude` flags, `expiration` 72 hours, no `pdf.create`. They live in one config class and one test asserts the exact body.
- **AC-7**: Retry rules for the submit job. It has `tries = 3`, a wait of 60 then 120 seconds, and a hard deadline of 15 minutes after the scan was created (`retryUntil`), after which it never sends. It retries only for a connection error, a timeout or a `5xx`, always with the same scan ID. A `401` triggers one fresh login and one repeat of the same try. A clear refusal (`402` no credits, `429`, other `4xx`) on the first try moves the scan to `failed` at once with the reason `"{code}: {message}"` from Copyleaks, and is never retried. A refusal on a later try (2 or 3) does not fail the scan, because an earlier try may have been accepted: the scan stays `submitting`, a warning is logged with the scan ID, and the job stops. If all tries end without an answer, the scan also stays `submitting` and the job lands in `failed_jobs`. That is expected, and resend (AC-12) decides what happened. If a worker dies after the call, the job runs again after `retry_after`, sends the same ID, gets `409` and moves to `waiting`.
- **AC-8**: The webhook route is a public `POST /webhooks/copyleaks/{scanId}/{token}/{status}` outside the web group (no session, no CSRF), limited to 30 calls a minute per scan ID, where `status` is only `completed` or `error`. A wrong token, a wrong secret header, an unknown scan, invalid JSON, or a `developerPayload` different from the scan ID all return the same empty `404` and save nothing. A body larger than 10 MB (checked from `Content-Length` after the token passes) returns `413`. Only the scan ID is logged.
- **AC-9**: A valid `completed` call is handled in this order: (1) if the scan has no `summary` file yet, write the exact body, gzip compressed, to a temp file and rename it into place; (2) in one database transaction, `insertOrIgnore` the `summary` artifact row and the `scan_events` row, apply the scan move (AC-10), and set `handled_at`; (3) after the commit, fire `ScanResultsArrived` once and answer `200`. A repeat of the same (scan, status) answers `200`, changes nothing, and writes the file again only if it is missing. Nothing in this request calls Copyleaks.
- **AC-10**: `completed` moves the scan from `waiting` or `submitting` to `fetching`. `error` moves it from `submitting`, `waiting` or `fetching` to `failed` with `"{code}: {message}"` from the error body. A call for a scan that is already `completed` or `failed` is saved as an event but moves nothing. A `completed` call for a scan that is already `failed` keeps the summary and logs a warning with the scan ID, because credits were spent. A scan that goes straight from `submitting` to `fetching` keeps `submitted_at` empty.
- **AC-11**: The saved page refreshes itself every 10 seconds while the latest scan is `submitting` or `waiting`, and stops from `fetching` on. A failed scan shows its reason. The status label comes from a new `ScanStatus::label()`. No similarity or AI score is shown anywhere by this feature.
- **AC-12**: A "Resend result" button shows only when `can_resend` is true: the latest scan is `waiting` and its `submitted_at` (or `created_at` if empty) is at least 10 minutes old, or it is `submitting` and the 15 minute job deadline has passed. It works once per 10 minutes per scan and never creates a scan. The Copyleaks resend call answers `202` ("asked Copyleaks to resend"), `400` ("not ready yet") or `404`. On `404` the scan moves to `failed` with the reason "Copyleaks has no record of this scan (never received or already expired).", so staff can submit again.
- **AC-13**: No code path other than the Submit action queues `SubmitScanJob` (an architecture test enforces it). The real client refuses to run in the `testing` environment. Every test uses a fake `ScanClient`. Submit shows "Copyleaks is not set up" and creates no scan when `COPYLEAKS_EMAIL`, `COPYLEAKS_API_KEY` or `COPYLEAKS_WEBHOOK_SECRET` is empty, or when the webhook base address does not start with `https://`.
- **AC-14**: Keys, webhook tokens, the secret header and manuscript text never appear in logs, error messages or pages. Logs carry only the scan ID and a status word. The Copyleaks login token is cached for 47 hours.

## Decision

**Chosen option**: Option 1: queued submit job plus a token guarded webhook that records the call and moves the scan in one step.

Staff press Submit, a job sends the file once (safe to repeat because the scan ID is fixed), and a locked down webhook saves the exact summary, records the event and moves the scan in one database transaction. Feature 7 takes over at `fetching`.

**Implementation skills**: `shadcn-vue` (`unovue/shadcn-vue`, `.agents/skills/shadcn-vue/`) for the Submit panel, checkboxes, alert and resend button.

## Feature design

**Data model sketch**: no new tables or columns. Uses the five tables of spec 0002 as they are.

| Table | Used for |
| --- | --- |
| `scans` | New row at Submit. Columns used: `copyleaks_scan_id`, `status`, `failure_reason`, `webhook_token_hash`, `ai_requested`, `is_sandbox`, `submitted_at`. |
| `scan_events` | One row per accepted webhook. `kind = status`, `status` is `completed` or `error`, `payload` is a small status copy (see Key invariants), `received_at`, `handled_at` (set in the same transaction). |
| `scan_artifacts` | One `summary` row per completed scan, `source_id = ''`, path `scans/{copyleaks_scan_id}/summary.json.gz`. |
| `submissions` | Read only: `file_path`, `file_sha256`, `page_count`, `original_name`, file type. |

**State transitions** (all through `ScanTransitioner`, spec 0002):

| Move | Triggered by |
| --- | --- |
| (new row) `submitting` | Submit action, before any Copyleaks call |
| `submitting` to `waiting` | Submit job got `201` or `409` |
| `submitting` or `waiting` to `fetching` | Webhook, on `completed` |
| `submitting`, `waiting` or `fetching` to `failed` | First try refusal or unreadable file in the submit job, `error` webhook, or resend `404` |

`fetching` to `completed` belongs to feature 7.

**Code layout** (SOLID, small classes, injected through constructors):
- `app/Services/Copyleaks/ScanClient.php`: interface with `submit(SubmitRequest): SubmitResult` and `resendWebhook(string $scanId): ResendResult`.
- `app/Services/Copyleaks/CopyleaksScanClient.php`: the real client. It wraps the official SDK. It throws in the `testing` environment. It caches the login token for 47 hours. If the SDK does not install cleanly on Laravel 13 or cannot send `statusHeaders`, `cheatDetection` and `aiGeneratedText`, use Laravel's `Http` client behind the same interface (three calls: login, submit, resend). Nothing else changes.
- `app/Services/Copyleaks/FakeScanClient.php`: for tests. It records each request and plays a scripted list of outcomes (created, conflict, refused with code and message, timeout, server error, unauthorized).
- `app/Services/Copyleaks/ScanOptions.php`: builds the options body from config and the checkbox (AC-6).
- `app/Services/Copyleaks/WebhookUrlBuilder.php`: builds the status address by joining strings (not `route()`, which would encode `{STATUS}`), and `WebhookToken.php` derives the token.
- `app/Services/Copyleaks/CopyleaksSettings.php`: reads config and answers `isReady()` (AC-13).
- `app/Services/Copyleaks/ResendEligibility.php`: one place that works out `can_resend` for the page and for the action.
- `app/Actions/SubmitScan.php`: checks, lock, scan insert, job dispatch, all in one transaction (AC-1 to AC-4, AC-13).
- `app/Jobs/SubmitScanJob.php`: one Copyleaks call, outcome mapping, retries (AC-5, AC-7).
- `app/Http/Controllers/CopyleaksWebhookController.php`, `app/Http/Middleware/VerifyCopyleaksWebhook.php`: checks (AC-8).
- `app/Actions/AcceptWebhook.php`: file write, then the single transaction (artifact, event, scan move, `handled_at`), then the event (AC-9, AC-10).
- `app/Events/ScanResultsArrived.php`: the seam for feature 7. No listener in this feature.
- `app/Actions/ResendScanResult.php` and a `ScanController` with `store` and `resend` (AC-12).
- `routes/webhooks.php`, registered in `bootstrap/app.php` outside the web group; named limiters `scan-submit`, `copyleaks-webhook` defined in a service provider.
- `config/copyleaks.php`: credentials, sandbox flag, webhook address and secret, option values, `words_per_page_estimate`, `submit_deadline_minutes` (15).

**API surface**:

| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
| --- | --- | --- | --- | --- | --- |
| `/submissions/{submission}/scans` | POST | `include_ai: bool` (default true), `confirm_duplicate: bool` | redirect back to the saved page, with a notice | none in Slice 0 (login arrives in Slice 1), `throttle:scan-submit` 5 per minute per IP | 422 (Word file, running scan, duplicate not confirmed, not set up), 404 unknown id, 429 throttle |
| `/submissions/{submission}/scans/resend` | POST | none | redirect back with a notice | none in Slice 0. Limit is the per scan rule below, no route throttle | 422 (not eligible yet or limit used), 404 unknown id |
| `/webhooks/copyleaks/{scanId}/{token}/{status}` | POST | Copyleaks JSON body, header `X-Webhook-Secret` | `200` empty | token, secret header, `developerPayload` | `404` for any failed check or a `status` outside `completed`, `error`; `413` body over 10 MB; `429` over 30 a minute per scan ID |

The saved page (`GET /submissions/{submission}`) gains: `scan` (`status`, `label`, `failure_reason`, `ai_requested`, `submitted_at`, `can_resend`, `is_running`, `is_polling`) and `estimated_credits` (null for Word files).

**Value sourcing** (where each value comes from):

| Action | Value produced / displayed | Source |
| --- | --- | --- |
| Submit | `copyleaks_scan_id` | App: lowercase `Str::ulid()`, 26 characters |
| Submit | Webhook token and `webhook_token_hash` | App: `hash_hmac('sha256', 'copyleaks-webhook|'.$scanId, config('app.key'))` (64 hex characters). Never stored. The Submit action saves `sha256(token)`, and the job recomputes the token for the URL. This keeps the plain token out of the `jobs` table. |
| Submit | `ai_requested` | The checkbox `include_ai` |
| Submit | `is_sandbox` | `config('copyleaks.sandbox')` from `COPYLEAKS_SANDBOX` |
| Submit | Estimated credits shown | `ceil(page_count * words_per_page_estimate / 250)`, with `words_per_page_estimate` (default 500) in config. Shown as "about N credits". Null when `page_count` is null. Not stored. |
| Submit | Duplicate warning | Scans joined to submissions where `file_sha256` matches, `scans.status != failed` and `scans.is_sandbox = false`. Shows the newest earlier submission's `public_id` link, `original_name` and `created_at`. |
| Submit | Readiness | `CopyleaksSettings::isReady()`: email, API key and webhook secret not empty, and the webhook base starts with `https://` |
| Submit job | File bytes, file name | `submissions.file_path` on the private disk, `submissions.original_name` |
| Submit job | Status address | `COPYLEAKS_WEBHOOK_URL` else `APP_URL`, trailing slash removed, plus `/webhooks/copyleaks/{scanId}/{token}/{STATUS}` |
| Submit job | Secret header value | `COPYLEAKS_WEBHOOK_SECRET`, sent as `X-Webhook-Secret` through `statusHeaders` |
| Submit job | `developerPayload` | The scan's `copyleaks_scan_id` |
| Submit job | `submitted_at` | `now()` in the same update that moves to `waiting` |
| Submit job | Deadline | `scans.created_at` plus `submit_deadline_minutes` |
| Failing a scan | `failure_reason` | `"{code}: {message}"` from the Copyleaks error body (`error.code`, `error.message`), or one of the fixed texts in AC-5 and AC-12. Capped at 1000 characters |
| Webhook | Which scan | `scanId` in the path, matched on `copyleaks_scan_id` |
| Webhook | Token valid | `hash_equals(webhook_token_hash, sha256(token))` |
| Webhook | `kind`, `status` | Fixed `status`, and the `{status}` path word |
| Webhook | Event `payload` | Small copy built by us (see Key invariants) |
| Webhook | `summary` artifact `path`, `sha256`, `size_bytes` | Path made by the app; checksum and size of the compressed bytes |
| Webhook | `received_at` / `handled_at` | `now()` on save / `now()` in the same transaction |
| Saved page | `label` | New `ScanStatus::label()` (Sending, Waiting for Copyleaks, Result received, Done, Failed) |
| Saved page | `can_resend` | `ResendEligibility`: AC-12 clock, plus `RateLimiter` key `scan-resend:{scans.id}` (1 per 600 seconds, used up only when Copyleaks is actually called) |
| Saved page | `is_running` / `is_polling` | `ScanStatus::isRunning()` / status is `submitting` or `waiting` |

**Key invariants**:
- Only the Submit action creates a scan and queues a submit job. Nothing in the scheduler, a listener, a model event, a factory or a test may do so.
- The real `ScanClient` throws if `app()->environment('testing')`. The container binds the fake in tests.
- The scan ID is fixed for the life of the scan. Every submit repeat uses it, so Copyleaks answers `409` instead of billing twice.
- The submit job never sends after its 15 minute deadline, and resend for a `submitting` scan is allowed only after that deadline. This closes the gap where a resend `404` could fail a scan while an old job was still about to send.
- A refusal on any try after the first never fails the scan.
- The webhook route does no Copyleaks call. The summary file is written before the transaction, and the artifact row, event row, scan move and `handled_at` commit together, so a repeat can never find an event without its artifact.
- The `summary` artifact is the exact request body, gzip compressed, never edited. A repeat is ignored by the unique rule.
- `scan_events.payload` for `completed` holds only: `status`, `developerPayload`, `scannedDocument.scanId`, `scannedDocument.totalWords`, `scannedDocument.credits`, `results.score`, and the list of `notifications.alerts[].code`. For `error` it holds `error.type`, `error.id`, `error.code`, `error.message`. Never source text, never manuscript text, never headers.
- Unknown statuses are not accepted. We never ask Copyleaks for `creditschecked` or `indexed`, so those words return `404`.
- Timing: Copyleaks needs an answer within 70 seconds and retries a failed call up to 17 times with doubling waits. The submit job runs from cron, so it may start up to a minute late. The webhook moves the scan at once.
- Changing `APP_KEY` breaks the webhook token of every scan still running (`APP_PREVIOUS_KEYS` is not used). Rotate only when no scan is running.
- Money safety: nothing may call Copyleaks except `CopyleaksScanClient`, and nothing in tests may reach it.

**Security model**: Internal tool, no login in Slice 0, so Submit and Resend are open until Slice 1 adds login. The webhook is public and protected by two secret checks and one consistency check: a per scan token in the path (derived from `APP_KEY`, only its hash is stored, compared with `hash_equals`), a shared secret header sent through Copyleaks' `statusHeaders` and checked with `hash_equals`, and the `developerPayload` that must equal the scan ID (it catches a mixed up call but is not a secret, since the ID is also in the path). Copyleaks' client certificate check is skipped on purpose (shared hosting terminates TLS before PHP, and the thumbprint list changes daily). The route is limited per scan ID, not per sender address, because Copyleaks calls from shared addresses. A `429` just makes Copyleaks retry. Manuscripts are authors' personal data, sent to Copyleaks over HTTPS only. The brief's open question about Copyleaks keeping documents stays open, so scans search the internet only, add nothing to any shared index, and expire on Copyleaks after 72 hours.

**Configuration required**:
- `COPYLEAKS_EMAIL`, `COPYLEAKS_API_KEY`, `COPYLEAKS_SANDBOX`: already in `.env.example` (spec 0001).
- `COPYLEAKS_WEBHOOK_URL`: new, optional public base address (for example the Herd share address) used when building the webhook address. Empty means use `APP_URL`. It must start with `https://`, so locally set it to the Herd share address.
- `COPYLEAKS_WEBHOOK_SECRET`: new, a long random string checked in the `X-Webhook-Secret` header. Add it to `.env.example`.
- Prerequisite: a Copyleaks account with the API key. A public HTTPS address must reach the app (Herd share locally, the real domain on Hostinger).

**Critical test scenarios** (all use the fake `ScanClient`):
- Happy path: Submit on a PDF creates one `submitting` scan and one job; the job sends the exact AC-6 body and AC-5 fields and moves to `waiting`; a valid `completed` call saves the summary file, artifact, event, moves the scan to `fetching` in one step and fires `ScanResultsArrived` once, verifies **AC-1**, **AC-2**, **AC-4**, **AC-5**, **AC-6**, **AC-9**, **AC-10**
- Failure case: a second Submit press after the first, and a Submit while a scan is running, create one scan. A duplicate checksum is refused without the override and accepted with it; a sandbox duplicate is not checked; a Word file is refused. The lock itself cannot be proven on sqlite (`lockForUpdate` does nothing there), so the test covers only the second press arriving after the first, verifies **AC-1**, **AC-2**, **AC-3**
- Failure case: `409` moves to `waiting`; a first try `402` fails with `"{code}: {message}"` and no retry; a timeout retries up to 3 times with the same scan ID and ends `submitting`; a refusal on try 2 leaves `submitting`; `401` logs in once more; nothing is sent after the 15 minute deadline; an unreadable file fails with the fixed reason, verifies **AC-5**, **AC-7**
- Failure case: the same webhook sent twice; `error` while `waiting`; `completed` while still `submitting`; `completed` after `failed` (summary kept, warning logged); any call for a `completed` scan; a repeat when the file is missing writes it again, verifies **AC-9**, **AC-10**
- Auth/permission: wrong token, wrong secret header, unknown scan, invalid JSON, mismatched `developerPayload` and a status other than `completed` or `error` all return the same empty `404` and save nothing; an 11 MB `Content-Length` returns `413`, verifies **AC-8**
- Failure case: resend `202`, `400`, `404` (fails the scan), the 10 minute wait for `waiting`, the 15 minute wait for `submitting`, and the once per 10 minutes limit, verifies **AC-12**
- Safety: the real client throws under `testing`; an architecture test proves only `SubmitScan` uses `SubmitScanJob`; empty credentials, empty secret and a base address that is not `https://` create no scan; a log capture never contains the token, the secret or file text; the page polls only for `submitting` and `waiting`, verifies **AC-11**, **AC-13**, **AC-14**

## Build plan

Approach: Tracer Bullet. Build the thinnest real thread first (Submit, job, webhook, `fetching`) on the fake client, then thicken one strand at a time. No migration is needed. Each step carries its own Pest tests, so nothing waits until the end.

1. Client seam: `ScanClient` interface, `FakeScanClient`, `config/copyleaks.php`, `CopyleaksSettings`, `ScanOptions`, `WebhookToken`, `WebhookUrlBuilder`, the testing guard, `COPYLEAKS_WEBHOOK_SECRET` in `.env.example`. Install the SDK (check the package name on Packagist first) or fall back to the `Http` client behind the interface. Tests for the exact options body, the token, the address and the guard, satisfies **AC-4**, **AC-6**, **AC-13**, **AC-14**
2. Thin thread, send side: `ScanStatus::label()`, the `scan-submit` limiter, `SubmitScan` (one transaction: lock, insert, dispatch), `POST /submissions/{submission}/scans`, `SubmitScanJob` for the `201` path, a plain Submit button on the saved page. Tests for one scan per press and the `waiting` move, satisfies **AC-2**, **AC-4**, **AC-5**
3. Thin thread, receive side: `routes/webhooks.php` registered in `bootstrap/app.php`, the `copyleaks-webhook` limiter, `VerifyCopyleaksWebhook`, `AcceptWebhook` for `completed` (file, then one transaction, then `ScanResultsArrived`). Tests for the happy path and for the shared empty `404`, satisfies **AC-8**, **AC-9**, **AC-10**
4. Thicken the receive side: `error`, repeats, a missing file on repeat, finished scans, `completed` after `failed` with the warning, the 413 size check, invalid JSON. Tests for each, satisfies **AC-8**, **AC-9**, **AC-10**
5. Thicken the send side: `409`, `401` login repeat, first try refusal, later try refusal, 3 tries with the backoff, the 15 minute deadline, unreadable file. Tests for each, satisfies **AC-5**, **AC-7**
6. Submit guards: Word refusal, running scan refusal, duplicate warning with override and sandbox rule, readiness check, credit estimate. Tests for each, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-13**
7. Resend: `ResendEligibility`, the per scan limiter, `ResendScanResult`, the route, `202`/`400`/`404` handling. Tests for each, satisfies **AC-12**
8. Real page with shadcn-vue: Submit panel, AI checkbox, duplicate override, status block with polling, failure reason, resend button, and the new page props. Update spec 0003's page test that expects no Submit button for PDFs. Run `npm run build` first, then the page tests, satisfies **AC-1**, **AC-11**, **AC-12**
9. Close out: the architecture test for `SubmitScanJob`, a log capture test, `composer ci:check`, and `app/Services/Copyleaks/AGENTS.md` linked from root `AGENTS.md`, satisfies **AC-13**, **AC-14**
10. Engineer run check, not automated: with `COPYLEAKS_SANDBOX=true` and a Herd share address, press Submit once on a tiny PDF and confirm the open facts in Follow-up. Then, only if wanted, run one small real scan and keep its `completed` body as a test fixture (spec 0001), satisfies **AC-5**, **AC-9**

## Consequences

**Positive**:
- A scan is sent once in effect: one press, a fixed scan ID, a job deadline and a `409` rule mean repeats cost nothing.
- The webhook is quick and boring: it saves and answers. The exact summary is on disk before anything else can go wrong, and one transaction means no half handled event and no handling job that could be lost.
- Resend gives a way out of a lost call without a rescan, and an unanswered submit never turns into a blind second scan.
- Scanning once with the AI check and integrity alerts on means Slice 4 and feature 22 can build from saved data.

**Negative / tradeoffs**:
- The submit step waits for the next cron run, so a scan can show a delay of up to a minute before it reaches `waiting`.
- The token sits in the webhook path, so hosting access logs may contain it. The secret header is the second layer, and the token only works for one scan.
- Until feature 7 is built, a finished scan sits at `fetching` and the page shows no result. If the process dies between the commit and the `ScanResultsArrived` event, the scan stays `fetching` and feature 7 needs a way to pick it up.
- A scan left `submitting` after retries can only be cleared by waiting for the 15 minute deadline and then using resend. That is slower on purpose, because failing it early could mean paying twice.
- Retrying a submit after a timeout relies on Copyleaks answering `409` for a repeated ID. The docs describe reusing the ID for exactly this, but it is the one place a mistake could cost credits, so it needs the sandbox check.
- Asking for the AI check, integrity alerts and a 72 hour expiry now commits those choices. Changing them later means a new paid scan.
- The credit estimate is a guess (500 words per page) and can be wrong.
- Locking cannot be proven in the sqlite tests, so the double press protection relies on MySQL in production.

**Neutral**:
- Reverses the `tries = 1` rule from spec 0001 for network errors only, with a deadline.
- The official SDK is still allowed, but the plan names a fallback if it does not fit.
- Feature 6 now saves the `summary` artifact, which narrows feature 7 to the export files.
- A scan that goes from `submitting` straight to `fetching` has no `submitted_at`; readers must cope.

## Follow-up

- [ ] Approved difference from spec 0001: the submit job retries up to 3 times for network errors and `5xx` with the same scan ID (with a deadline), and the read only "fetch results" recovery became the Copyleaks resend call. Reword those lines in spec 0001 next time it is touched. In spec 0002 the same wording sits in the state table note, the "Submit job" lines and the value sourcing row for `webhook_token_hash`.
- [ ] Small difference from spec 0002's value sourcing: the webhook token is derived from the scan ID and `APP_KEY` instead of being random, so the plain token never sits in the `jobs` table. Rotating `APP_KEY` breaks scans still running.
- [ ] Spec 0003's acceptance criterion AC-8 says the saved page has no Submit button. For PDF submissions this feature replaces that. Update the page test and reword the line.
- [ ] Scope: reword feature 7 to "download the export files (results, text, AI result) and finish the scan", since the `summary` artifact is saved here. Update the feature 6 row to point at this spec.
- [ ] Spec 0002 follow-ups closed here: allowed event statuses are `completed` and `error` (Copyleaks' `{STATUS}` word is lowercase); scan IDs must be lowercase; the similarity score is `results.score.aggregatedScore` (a float) from the `completed` body. Feature 7 sets `ai_percent` from the AI result summary (`summary.ai`, a value from 0 to 1) once it fetches the export.
- [x] Sandbox check done on 2026-10-31 (two sandbox scans, no credits spent). Answered: the `error` body does carry `developerPayload` (no loosening needed); Copyleaks accepted every option, including `statusHeaders`, `aiGeneratedText.explain` and `scanning.copyleaksDb`; the webhook passed the token, secret and `developerPayload` checks through a public address; a `completed` body saved and unzipped to the exact bytes. A blank PDF fails with `11: The submitted document is empty.` (`not_enough_text`). The similarity score is `results.score.aggregatedScore`. The AI result arrived as a `suspected-ai-text` alert whose `additionalData` holds `summary.Ai` and `summary.Human`. Still open: the `completed` summary showed `enabled.cheatDetection = false` although we send `properties.cheatDetection: true` (the documented name), so it is either ignored in sandbox or not on the account plan (confirm on the first real scan or with Copyleaks); the AI result ID field was not found; the `409` on a repeated submit, the credit cost of the AI check and resend, and the allowed `expiration` range are untested.
- [x] Second sandbox check done on 2026-10-31 (browser run with a one page PDF that has real text, one sandbox scan, no credits spent). The page went Sending, Waiting, Result received with no manual reload, and the scan reached `fetching` with `is_sandbox` true and `ai_requested` true. A blank PDF still fails with `11: The submitted document is empty.` Findings from the saved `completed` body: there is no AI result ID field; the AI result is the `suspected-ai-text` alert, whose `additionalData` is a JSON string with `summary.Ai`, `summary.Human`, `probability` and `modelVersion` (feature 7 should read it from there). `enabled.cheatDetection` is `false` again and `enabled.explainableAi` is `false`, so the sandbox ignores `cheatDetection` or the plan lacks it (still to confirm on the first real scan). The body also carries `writingFeedback` data we did not ask for, so feature 7 must not rely on it. `credits` was 0 and `expectedCredits` 1. Still untested: the `409` on a repeated submit, the credit cost of the AI check and resend, and the allowed `expiration` range.
- [ ] Original sandbox check list (kept for the open items above): the exact field in the `completed` body that carries the AI result ID (the docs mention it but do not name it); that the `error` body really carries `developerPayload` (if it does not, every error call gets a `404` and the scan waits forever, so loosen the check for `error` only); whether `cheatDetection` or the AI check cost extra credits; the allowed range for `expiration`; whether the resend call costs credits; that a repeated submit with the same ID returns `409`.
- [ ] Feature 7 listens to `ScanResultsArrived`, starts the export with its own `exportId`, saves the files and moves the scan to `completed`. It must handle the AI result ID, and it needs a sweep for scans stuck in `fetching` with no export started.
- [ ] Confirm the SDK: Packagist shows `copyleaks/php-plagiarism-checker` v5.2.0 (2025-10-08) but its README names `copyleaks/copyleaks`, and PHP 8.3 / Laravel 13 support is unconfirmed. Check before `composer require`.
- [ ] Confirm with Hostinger that a public HTTPS address can receive webhook `POST`s up to 10 MB, that `post_max_size` is at least that, and that cron runs every minute.
- [ ] Idea for later: Copyleaks has a credits check (`action = 1`, webhook `creditschecked`) that may give the exact cost before a real scan. The docs did not say whether it is free, so it is not in this feature.
- [ ] Sandbox limit: 100 sandbox scans per hour. Keep that in mind when testing by hand.
- [ ] `shadcn-vue` conventions are already listed in root `AGENTS.md` `## Agent skills`.

## Rationale

Reasoning, options, the Copyleaks facts checked and the references: see [rationale.md](rationale.md).
