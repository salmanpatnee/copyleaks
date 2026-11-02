# Verify: Raw results storage · spec 0005 · updated 2026-11-02
_Steps derived from spec 0005 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
- [x] Submit a one page PDF with real text on the sandbox and wait for Copyleaks to finish → an `export_started` event with status `{scan}-1` and `handled_at` set exists → AC-1
- [x] Open the saved scan page while the scan is `fetching` → it keeps refreshing, then stops at `completed` or `failed` → AC-16
- [x] Look at `scans/{copyleaks_scan_id}/` on the private disk → `summary`, `text` and one `result-*` file per result ID are there, and each gunzips to exactly what Copyleaks sent → AC-3, AC-4
- [x] Delete one `result` row and its file, then call that export's `/completed` address → attempt 2 starts with a new export ID → AC-9
- [x] Repeat the step above until three accepted attempts have ended → the scan is `failed` with "Export incomplete after 3 tries. The summary is kept." and the summary file is still there → AC-9
- [x] Complete a scan → `similarity_percent` is set, `ai_percent` equals the `Ai` value times 100 from the alert, and `remote_deleted_at` is set after the delete job runs → AC-7, AC-8, AC-12
- [x] Sandbox check by hand: the export ID `{scan_id}-{n}` is accepted, `X-Webhook-Secret` arrives on pushes, the `pdfReport` field can be left out, real file sizes fit the 10 MB cap, and note whether export or delete costs credits → AC-1, AC-3, AC-6

## Commands
- [x] `curl -s -o /dev/null -w "%{http_code}" -X POST <push address with a wrong token>` → `404` with an empty body → AC-2
- [x] Same call with a wrong `X-Webhook-Secret`, an unknown result ID, an unknown export ID, or a body of `{not json` → `404` with an empty body, nothing saved → AC-2, AC-14
- [x] Push a body over 10 MB (once with a `Content-Length` header, once chunked) → `413`, nothing saved → AC-2
- [x] Push the same file twice → one `scan_artifacts` row, one file, `200` both times → AC-4
- [x] Push to a scan that is already `completed` or `failed` → `200`, nothing saved → AC-5
- [x] `php artisan copyleaks:sweep-fetching` on a scan stuck at `fetching` with no export → attempt 1 is queued, no scan is created, Submit is not called → AC-10
- [x] `php artisan copyleaks:sweep-fetching` on a `fetching` scan whose `completed` event is over 48 hours old and with a file missing → `failed` with "The files could not be stored in time." → AC-10
- [x] `php artisan schedule:list` → `copyleaks:sweep-fetching` every 5 minutes and the queue worker every minute → AC-10
- [x] `php artisan route:list --path=webhooks` → the `result`, `text` and `completed` export addresses are listed → AC-2, AC-6
- [x] Search the logs for a push body, token, secret or task endpoint → none found → AC-14

## Acceptance-criteria coverage
- AC-1 covered by steps 1, 7 · AC-2 by the 404, 413 and route steps · AC-3, AC-4 by the file and repeat steps · AC-5 by the finished scan push step · AC-6 by the sandbox check and route step · AC-7, AC-8, AC-12 by the completed scan step · AC-9 by the retry and failure steps · AC-10 by the sweep and schedule steps · AC-14 by the log search · AC-16 by the polling step
- AC-11 (export call answers) and AC-13, AC-15 (client seam, rebuild proof) have no manual step here. They need fake client scripting, which is `/test` work.
