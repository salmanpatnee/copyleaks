# 0004. Rationale: Copyleaks scan submit and result webhook

Build spec: [index.md](index.md)

## Context

The tool must send an uploaded PDF to Copyleaks and learn when the result is ready. Copyleaks answers through a webhook, a web address it calls when work finishes. Three forces shape the design.

**Credits are limited and a scan cannot be undone.** Sandbox scans are free mock results (100 per hour), but real scans use credits, and spec 0001 and the scope forbid scans from loops, retries and tests. Each file is scanned once, so the first scan has to ask for everything later slices need: the similarity check, the AI check (Slice 4) and the integrity alerts (feature 22).

**The hosting cannot run a worker or a browser.** Spec 0001 chose Hostinger with a database queue drained by cron every minute. Any step that waits on a job can start up to a minute late. Copyleaks needs an answer to a webhook call within 70 seconds, so the webhook cannot do slow work.

**The webhook is a public door.** Anyone can post to it. Copyleaks does not sign its calls. The documented protections are a client certificate check (hard on shared hosting), a secret in `developerPayload`, and custom headers we choose. Manuscripts are authors' personal data, and the brief's question about how long Copyleaks keeps documents is still open.

Related work already decided: spec 0001 (stack, `ScanClient` interface, no blind retries, token plus header on webhooks), spec 0002 (the five tables, the guarded status moves) and spec 0003 (the upload creates a submission and never a scan). Feature 7 (raw results storage) follows this one.

## Options considered

### Option 1: Queued submit job plus a token guarded webhook that records and moves the scan in one step

Press Submit, create a `submitting` scan and queue a job in one transaction. The job sends the file with a fixed scan ID, with a deadline. The webhook checks a token, a secret header and the scan ID, saves the exact summary file, then in one transaction saves the artifact row and the event row and moves the scan, and finally fires an event for feature 7.

**Pros**:
- The submit call is safe to repeat because Copyleaks refuses a second scan with the same ID.
- The webhook answers fast and cannot lose the summary, since the exact bytes are saved first and the rows commit together.
- No second job after the webhook, so no extra cron wait and no lost job to sweep up.
- Fits shared hosting: no worker, no long request.
- Each strand (send, receive, resend) is small and testable with a fake client.

**Cons**:
- Cron timing adds up to a minute at the submit step.
- More parts than a single request: a job, a route, a middleware, an action, an event.
- The webhook does a small amount of database work in the request, which is acceptable because it is one guarded update.

### Option 2: Send the file inside the Submit request

The Submit press uploads the base64 file to Copyleaks and waits for the `201` before showing the page.

**Pros**:
- Fewest parts and the user sees the result of the call at once.

**Cons**:
- A 20 MB PDF as base64 plus a slow Copyleaks call can pass shared hosting time limits, leaving an unknown state with no retry.
- A double click or a browser retry sends two requests at the same time.

### Option 3: Poll Copyleaks for the status instead of using a webhook

A scheduled job asks Copyleaks whether each scan is done.

**Pros**:
- No public address needed, which would help local work.

**Cons**:
- No endpoint for reading a scan's status was found in the docs, so this cannot be built on verified facts.
- A scheduled job that talks to a paid API is the kind of loop the project rules forbid.
- The scope and spec 0001 already chose webhooks.

### Option 4: Do all the work inside the webhook request

On `completed`, download the export and build the artifacts before answering.

**Pros**:
- No handing off between steps.

**Cons**:
- Copyleaks gives 70 seconds to answer, and an export is itself asynchronous with its own callback.
- A failure inside the request makes Copyleaks retry up to 17 times, repeating work.
- It makes feature 6 and feature 7 one large, hard to test feature.

## Rationale

Option 1 is the only one that satisfies all three forces at once. Credits are protected by a fixed scan ID, a database lock on Submit and the rule that only a person's press can queue a send. Cron timing is accepted at the submit step because a minute of delay costs nothing but patience. The public door is closed by two secret checks and one consistency check that work on shared hosting. The certificate check was skipped because TLS ends before PHP on that host and the thumbprint list changes daily.

A cross check by a second model changed three things. First, a refusal after an unanswered try no longer fails the scan, and the submit job has a 15 minute deadline that resend must wait for, because a timed out first try may have been accepted and failing the scan would invite a second paid scan. Second, the webhook writes the summary file before one transaction that holds the artifact, the event and the scan move, because saving the event first could lose the summary for good when Copyleaks retried. Third, the separate handling job was dropped: the status move is a single guarded update, so doing it inside the webhook transaction removes one cron wait and one way to lose work.

Saving the exact `completed` body inside the webhook request is a deliberate step across the line to feature 7. Copyleaks can only send it again through the resend call, and the scope says raw replies must be kept exactly as received. Feature 7 keeps the export files, which are the large part.

The engineer asked for the checkbox on the AI check and the override on duplicates, both recommended defaults. The engineer also chose retries with the same scan ID, which is a conscious change to spec 0001's `tries = 1`: Copyleaks' own docs say a repeated ID avoids duplicates after a network fault, so the original risk (a blind second paid scan) does not apply. The one risk left is an unconfirmed `409`, which the sandbox check in the build plan settles before real credits are spent.

Scan options were chosen so nothing has to be rescanned: AI check and integrity alerts on, no exclusions (feature 19 needs quotes and citations to still be matches), internet only and no indexing (the data use question is open), and a 72 hour expiry as a safety net after feature 7 deletes the remote copy.

## Copyleaks facts checked on 2026-10-31

Read from the official docs during the design conversation. Items marked unconfirmed were not found and are listed in Follow-up.

| Topic | What the docs say |
| --- | --- |
| Login | `POST https://id.copyleaks.com/v3/account/login/api` with `email` and `key`. Token lasts 48 hours. Calls use `Authorization: Bearer`. |
| Submit | `PUT https://api.copyleaks.com/v3/scans/submit/file/{scanId}` with `base64`, `filename`, `properties`. `201` means accepted. `409` means the ID already exists. `401` bad key. `429` rate limit. Error body: `{"error": {"type", "id", "code", "message", "details"}}`. `402` is not documented (insufficient credits arrives as a `payment_error`, codes 13 and 17). |
| Scan ID | 3 to 36 characters, lowercase letters, digits and a few symbols. Rejects `g`, `o` or `0`, `gle` patterns. |
| Webhooks | `properties.webhooks.status` with `{STATUS}` replaced by `completed`, `error`, `creditschecked` or `indexed`. `statusHeaders` sends custom headers. `POST`, any `2xx` accepted, 70 second limit, up to 17 attempts with doubling waits. |
| Security | No signature. Options: client certificate thumbprints, a secret `developerPayload` (max 512 characters), custom headers, Enterprise IP allowlist. |
| Resend | `POST /v3/scans/{scanId}/webhooks/resend`. `202` sent, `400` not ready, `401`, `404` unknown scan. Cost unconfirmed. |
| Completed body | `status`, `developerPayload`, `scannedDocument` (`scanId`, `totalWords`, `totalExcluded`, `credits`, `creationTime`, `metadata`), `results` (`score.aggregatedScore`, `identicalWords`, `minorChangedWords`, `relatedMeaningWords`, `internet[]`, `database[]`, `batch[]`, `repositories[]`), `referencesValidation`, `notifications.alerts[]`. |
| AI check | `properties.aiGeneratedText.detect`, `sensitivity` 1 to 3 (default 2), `explain.enable`. The `completed` body carries an `suspected-ai-text` alert. The detailed result is fetched through export. Result has `summary.ai` and `summary.human` (0 to 1) and `results[].matches` with character positions. The field holding the AI result ID is not named in the docs. |
| Integrity alerts | `properties.cheatDetection: true` adds scan alerts to the `completed` body. |
| Expiration | `properties.expiration` in hours, 1 to 2880 (default 2880). Starts when the scan completes. |
| Sandbox | `properties.sandbox: true`: mock results, no credits, 100 per hour. |
| Export | `POST /v3/downloads/{scanId}/export/{exportId}` with `completionWebhook`, `results[]`, `crawledVersion`. Feature 7. |
| Delete | `PATCH /v3.1/scans/delete`, `202`. Feature 7. |
| Status read | No endpoint found. |
| PHP SDK | `copyleaks/php-plagiarism-checker` v5.2.0 (2025-10-08), PHP 7.4 or newer. README and package name disagree. Laravel 13 support unconfirmed. |

## References

**Project sources**:
- `docs/specs/0001-stack-and-architecture/index.md`: stack rules for submit, webhooks and sandbox.
- `docs/specs/0002-data-model/index.md`: tables, status moves, value sourcing.
- `docs/specs/0003-upload-screen/index.md`: the upload creates no scan; feature 6 reuses the checksum lookup and refuses Word files.
- `docs/scope.md` and `docs/brief.md`: credit rules, open data use question.
- `app/Models/AGENTS.md`: `ScanTransitioner` is the only place a status changes.

**Practices & standards**:
- Idempotency keys for operations with external side effects (a fixed scan ID).
- Acknowledge fast, process later for webhook receivers.
- Constant time comparison (`hash_equals`) for secrets.
- Return the same empty response for every failed webhook check.
- Write the file first, then commit the rows together, so a repeat can repair a missing file.
- A hard deadline on a retried job, so a later "did it arrive" check cannot race the job.

**Links** (pages read during this design on 2026-10-31):
- Submit file: https://docs.copyleaks.com/reference/actions/authenticity/submit-file
- Webhooks: https://docs.copyleaks.com/using-the-apis/webhooks
- Webhooks security: https://docs.copyleaks.com/concepts/security/webhooks
- Resend webhook: https://docs.copyleaks.com/reference/actions/authenticity/resend-webhook
- Scan completed body: https://docs.copyleaks.com/reference/data-types/authenticity/webhooks/scan-completed
- Detect AI generated content: https://docs.copyleaks.com/guides/authenticity/detect-ai-generated-content
- AI detection result: https://docs.copyleaks.com/reference/data-types/authenticity/results/ai-detection
- Handling errors: https://docs.copyleaks.com/using-the-apis/api-errors
- PHP SDK on Packagist: https://packagist.org/packages/copyleaks/php-plagiarism-checker
