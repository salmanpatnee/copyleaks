# Verify: Copyleaks scan and result webhook · spec 0004 · updated 2026-10-31
_Steps derived from spec 0004 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

## UI / manual
Set `COPYLEAKS_EMAIL`, `COPYLEAKS_API_KEY`, `COPYLEAKS_WEBHOOK_SECRET`, `COPYLEAKS_SANDBOX=true` and `COPYLEAKS_WEBHOOK_URL` (Herd share address, https) in `.env`. Run `composer dev` (the queue worker must be running).
- [x] Open a saved PDF page with no scan → Submit panel shows "about N credits", the AI checkbox is ticked → AC-1
- [x] Open a saved Word file → no Submit panel; the Word alert shows → AC-1
- [x] Press Submit once on a tiny PDF → status goes Sending, then Waiting for Copyleaks, then Result received, without a manual reload → AC-2, AC-5, AC-9, AC-11
- [x] While the page shows Result received → the page stops refreshing (no new requests every 10 seconds) → AC-11
- [x] Press Submit twice quickly → only one scan row exists → AC-2
- [x] Untick "AI check" and Submit → the sandbox request carries `aiGeneratedText.detect = false` (check the `scans.ai_requested` column) → AC-4, AC-6
- [ ] With `COPYLEAKS_SANDBOX=false` (do not submit), open a PDF whose checksum was already scanned → duplicate alert with a link and an override checkbox shows → AC-3
- [ ] Clear `COPYLEAKS_WEBHOOK_SECRET` and press Submit → "Copyleaks is not set up" and no scan row → AC-13
- [ ] Set `COPYLEAKS_WEBHOOK_URL` to an `http://` address and press Submit → "Copyleaks is not set up" → AC-13
- [ ] Make a scan stay Waiting for 10 minutes → the Resend result button appears; press it → notice appears, no new scan row → AC-12
- [x] A failed scan shows its reason and the Submit panel comes back → AC-1, AC-11
- [ ] Hand check in the sandbox: confirm the Follow-up facts (the `error` body carries `developerPayload`, a repeated submit with the same ID returns `409`, the `explain`, `copyleaksDb` and `statusHeaders` shapes are accepted, the AI result ID field) → AC-5, AC-7, AC-9

## Commands
- [x] `php artisan test tests/Feature/Copyleaks` → all pass → AC-1 to AC-14
- [x] `php artisan test` → whole suite passes → AC-13
- [x] `composer types:check && composer lint:check && npm run types:check && npm run check` → clean → AC-13
- [x] `php artisan route:list --path=webhooks` → one POST route `webhooks/copyleaks/{scanId}/{token}/{status}`, no `web` middleware → AC-8
- [x] `curl -i -X POST http://copyleaks.test/webhooks/copyleaks/x/y/completed -d '{}'` → empty 404 → AC-8
- [x] `curl -i -X POST http://copyleaks.test/webhooks/copyleaks/x/y/indexed -d '{}'` → empty 404 → AC-8
- [x] `grep -rn "SubmitScanJob::dispatch" app` → only `app/Actions/SubmitScan.php` → AC-13
- [x] `grep -rn "COPYLEAKS_API_KEY\|webhook_secret" storage/logs` after a run → no hits → AC-14
- [ ] Vary the input: change `APP_KEY` then post a webhook for a running scan → empty 404 (known tradeoff) → AC-4, AC-8

## Value sourcing checks (one per row of the spec table)
- [x] `scans.copyleaks_scan_id` → 26 lowercase characters, no `l` → AC-4
- [x] `scans.webhook_token_hash` → 64 hex characters, differs from the token in the status address; the token is not in the `jobs` payload → AC-4
- [x] `scans.ai_requested` → equals the checkbox → AC-4
- [ ] `scans.is_sandbox` → equals `COPYLEAKS_SANDBOX` at press time (flip the setting and press again) → AC-4
- [x] Estimated credits → 10 pages shows "about 20 credits"; a Word file shows nothing; change `words_per_page_estimate` and the number changes → AC-1 (ran live: 10 pages gave 20 credits and Word showed none; changing the config value was not run)
- [ ] Duplicate link → points to the newest earlier submission with the same checksum and a non failed, non sandbox scan → AC-3
- [ ] Submit job file bytes and name → equal the stored file and `original_name` (fake client request) → AC-5
- [x] Status address → starts with `COPYLEAKS_WEBHOOK_URL` (or `APP_URL` when empty), trailing slash removed, ends with `/{STATUS}` unencoded → AC-5
- [ ] `submitted_at` → set in the same update as `waiting`; empty when the webhook moves a scan straight from submitting to fetching → AC-5, AC-10
- [ ] Job deadline → a scan older than 15 minutes is never sent → AC-7
- [ ] `failure_reason` → `"{code}: {message}"` from Copyleaks, or the fixed text for unreadable file and resend 404 → AC-5, AC-7, AC-12
- [x] Summary artifact → `scans/{id}/summary.json.gz`, gunzips to the exact webhook body, `sha256` and `size_bytes` match the compressed file → AC-9
- [x] `scan_events.payload` → holds only the small copy, no source text, no headers → AC-9
- [ ] `can_resend` → false at 9 minutes waiting, true at 10; false right after use; submitting scan true only after 15 minutes → AC-12
- [ ] `is_polling` / `is_running` → polling only for submitting and waiting → AC-11

## Acceptance-criteria coverage
- AC-1 … Submit panel steps, Word step, props tests · AC-2 … double press, job row steps · AC-3 … duplicate steps · AC-4 … value sourcing checks · AC-5 … send steps, hand check · AC-6 … ai checkbox step, options test · AC-7 … deadline and hand check · AC-8 … webhook route and curl steps · AC-9 … receive steps, summary checks · AC-10 … straight to fetching check · AC-11 … polling steps · AC-12 … resend steps · AC-13 … not set up steps, grep, architecture tests · AC-14 … log grep
