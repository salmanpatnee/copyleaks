# Copyleaks scan and result webhook

## Overview

Staff press Submit on a saved PDF. One action creates one scan, a queued job sends the file to Copyleaks once, and a public webhook saves the result summary and moves the scan to `fetching`. Feature 7 takes over from there. Decided in spec 0004 (`docs/specs/0004-copyleaks-scan-webhook/index.md`). Read it before changing a rule.

## Key files

| File                                                               | Owns                                                                                                                   |
| ------------------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Copyleaks/ScanClient.php`                            | The interface. `submit()`, `resendWebhook()`, `startExport()`, `deleteScan()`. The only door to Copyleaks              |
| `app/Services/Copyleaks/CopyleaksScanClient.php`                   | Real client on Laravel's `Http` (the SDK is not confirmed on Laravel 13). Throws in `testing`                          |
| `app/Services/Copyleaks/FakeScanClient.php`                        | Test client. Bound in `testing`. Script it with `willSubmit()` and `willResend()`                                      |
| `app/Services/Copyleaks/ScanOptions.php`                           | Every option a scan sends. Changing one changes a paid scan                                                            |
| `app/Services/Copyleaks/CopyleaksSettings.php`                     | `isReady()`, webhook base, credit estimate                                                                             |
| `app/Services/Copyleaks/WebhookToken.php`, `WebhookUrlBuilder.php` | Token derived from scan ID and `APP_KEY`. Status address built by joining strings                                      |
| `app/Services/Copyleaks/ResendEligibility.php`                     | One place for `can_resend` (10 minute wait, 15 minute deadline, once per 10 minutes)                                   |
| `app/Actions/SubmitScan.php`                                       | The only code that creates a scan and queues `SubmitScanJob`                                                           |
| `app/Jobs/SubmitScanJob.php`                                       | One Copyleaks call, outcome mapping, retries, 15 minute deadline                                                       |
| `app/Http/Middleware/VerifyCopyleaksWebhook.php`                   | Every webhook check. All failures are the same empty 404                                                               |
| `app/Actions/AcceptWebhook.php`                                    | File, then one transaction (artifact, event, move), then `ScanResultsArrived`                                          |
| `app/Actions/ResendScanResult.php`                                 | Asks Copyleaks to send the webhook again. Never creates a scan                                                         |
| `routes/webhooks.php`                                              | Loaded in `bootstrap/app.php` outside the web group (no session, no CSRF)                                              |
| `config/copyleaks.php`                                             | Keys, sandbox flag, webhook address, option values, timings                                                            |
| `app/Listeners/QueueExport.php`, `app/Jobs/StartExportJob.php`     | Raw results (spec 0005): the summary event queues attempt 1. The job claims one numbered try and makes one export call |
| `app/Http/Middleware/VerifyCopyleaksExportPush.php`                | Checks for the `result`, `text` and `completed` export addresses. Failures are the empty 404, over 10 MB is 413        |
| `app/Actions/AcceptExportPush.php`, `AcceptExportCompletion.php`   | Save a pushed file byte for byte (`RawFileWriter`), or record the completion call                                      |
| `app/Actions/FinishScan.php`, `AdvanceExport.php`                  | Finish check (all files present) sets scores and queues the delete. Else the next try or `failed` after 3              |
| `app/Services/Copyleaks/ExportAttempts.php`                        | Works out every try from the `export_started` and `export_completed` events                                            |
| `app/Services/Copyleaks/FetchingScanSweeper.php`                   | Rescues stuck `fetching` scans. Run by `copyleaks:sweep-fetching` every 5 minutes                                      |
| `app/Jobs/DeleteRemoteScanJob.php`                                 | One try to delete the scan on Copyleaks after `completed`. A failure only logs                                         |

## Conventions

- Only `SubmitScan` may dispatch `SubmitScanJob`. An architecture test enforces it. Never add a scheduler entry, listener, model event or factory that does.
- The export job, the sweep and the delete job never create a scan or call Submit. Architecture tests in `SweepTest.php` and `SafetyTest.php` enforce it.
- The export job never counts tries. The caller (listener, completion handler or sweep) works out the attempt number and passes it in. Only tries Copyleaks accepted (`handled_at` set) count toward the 3.
- Tests never reach Copyleaks. `ScanClient` resolves to `FakeScanClient` in `testing`. Get it in a test with `fakeScanClient()` from `tests/Support/CopyleaksTestHelpers.php` and `require_once` that file.
- The scan ID is lowercase ULID (26 characters) and never changes. Every submit repeat sends the same ID, so Copyleaks answers `409` instead of billing twice.
- The webhook token is never stored. Only `sha256(token)` is saved. Logs carry the scan ID and a status word only. Never log keys, the token, the secret header or manuscript text.
- Failure reasons are `"{code}: {message}"` from Copyleaks, or a fixed text, capped at 1000 characters.
- Staff messages come back as an error on the `scan` key (`->withErrors(['scan' => ...])`) or a one time `notice` (shared as `flash.notice`).

## Gotchas

- Needs three settings or Submit refuses: `COPYLEAKS_EMAIL`, `COPYLEAKS_API_KEY`, `COPYLEAKS_WEBHOOK_SECRET`, and a webhook base that starts with `https://` (`COPYLEAKS_WEBHOOK_URL`, else `APP_URL`). Locally use the Herd share address.
- Changing `APP_KEY` breaks the webhook token of every scan still running.
- `lockForUpdate` does nothing on sqlite, so the double press lock is only proven on MySQL.
- A refusal on try 2 or 3 never fails the scan, because an earlier try may have been accepted. A scan left `submitting` is cleared only by Resend after the 15 minute deadline.
- `VerifyCopyleaksWebhook` checks `Content-Length` itself, but Laravel's own post size check can answer `413` first when `post_max_size` is below 10 MB.
- The test client does not set `Content-Length`. Pass `CONTENT_LENGTH` in the server array to test the size rule.
- `tries = 3` is capped by hand inside the job because `retryUntil()` makes the queue ignore `tries`.
- Confirmed in the sandbox (2026-10-31): Copyleaks accepts our option shapes, the `error` webhook carries `developerPayload`, and a blank PDF fails with `11: The submitted document is empty.`
- `properties.cheatDetection: true` is the documented field, but the sandbox summary showed `enabled.cheatDetection = false`. It may be ignored in sandbox or not on the account plan. Check the first real scan before building feature 22 (integrity flags) on it.
- The similarity score is `results.score.aggregatedScore`. The AI result comes as a `suspected-ai-text` alert with `summary.Ai` and `summary.Human` in its `additionalData`.
- Export pushes keep the `X-Webhook-Secret` header check, but the `completed` export address does not, because Copyleaks cannot send custom headers on that call (sandbox check, 2026-11-02). It relies on the token, a known export ID and `developerPayload`.
- Task endpoints in the completion body carry the token. They are never saved or logged.
- Export test helpers (`fetchingScan()`, `runExport()`, `pushEverything()`, `exportPath()`) live in `tests/Support/CopyleaksTestHelpers.php`.
- Still untested: a repeated submit returning `409`, the Resend call, and whether the AI check or resend cost credits.

## Related specs

- [0004 Copyleaks scan and result webhook](../../../docs/specs/0004-copyleaks-scan-webhook/index.md)
- [0005 raw results storage](../../../docs/specs/0005-raw-results-storage/index.md)
- [0002 data model](../../../docs/specs/0002-data-model/index.md)
