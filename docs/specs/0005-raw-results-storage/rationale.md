# 0005. Raw results storage: reasoning

The build spec is [index.md](index.md). This file keeps the reasons. A build does not need it.

## Context

Spec 0004 ends when the webhook has saved the Copyleaks summary and moved the scan to `fetching`. The summary holds the scores, the list of matched sources and the AI alert, but not the full result of each source and not the manuscript text that the match positions point to. Those two live only on Copyleaks, and only for as long as the scan lives there. Our submit call sets that to 72 hours.

Every report from here on (features 8 to 13, 19 to 23) must be built from our own saved copy, because a scan costs credits and the same file must never be scanned twice. Spec 0002 already decided where the files live (`scan_artifacts`, insert only, on the private disk) and spec 0001 decided the hosting shape: Hostinger shared or Cloud, a database queue drained by cron every minute, no always running worker.

Forces that shaped the choice:
- The save must be exact and complete. A missing file is found only when a report is built, which may be days later, after Copyleaks has deleted the data.
- Pushes carry the manuscript text, so they need the same protection as the 0004 webhook and must never be logged.
- Jobs wait up to a minute for the cron, so the flow must tolerate delay and a lost call at every step.
- A manuscript can match hundreds of sources, so the number of calls matters.
- Spec 0002 says no later feature needs a new column, and the model already has `copyleaks_export_id`, `remote_deleted_at` and the `export_completed` event kind.

## Options considered

### Option 1: Push export, with our own completeness check and a sweep

One export call lists every result ID and the crawled version. Copyleaks pushes each file to a protected address, then calls a completion address. We save each file as it arrives and finish the scan only when our own check finds every expected file. A failed or incomplete export is retried up to 3 times, and a 5 minute sweep rescues anything stuck.

**Pros**:
- One outbound call however many results there are. Copyleaks does the copying and the retrying of single files.
- No long running download loop inside a cron driven job.
- Our own check does not depend on Copyleaks' flag being right.
- Matches the plan in specs 0001 and 0002.

**Cons**:
- Needs three more public addresses, a new throttle and PHP limits raised on the host.
- Pushes of up to 10 MB can use several times that in memory, because Laravel decodes a JSON body before our code runs.
- The flow has more moving parts (claim, pushes, completion, retry, sweep).

### Option 2: Pull with download calls from a queued job

A job asks Copyleaks for each result and the crawled version with plain GET calls and saves them itself.

**Pros**:
- No extra public addresses and no post size problem.
- The job decides when to ask, so a bad file can be retried by itself.

**Cons**:
- One API call per source. Hundreds of results mean a long job on a cron queue with a 240 second timeout, so it has to be split and resumed.
- More code to track which results are done, and each call counts against Copyleaks rate limits.
- The download endpoints and their limits are less clearly documented than the export call.

### Option 3: Push export, but trust Copyleaks' completion flag only

Same as Option 1, but finish the scan when the completion call says `completed` with every task healthy, with no check of our own.

**Pros**:
- Less code, no list of expected IDs to keep.

**Cons**:
- A healthy flag does not prove we saved the file (a disk error after the `200`, a rename that failed). The gap shows up only when a report is built, after Copyleaks has deleted the data.
- Gives no way to repair a scan with the files half there.

## Rationale

Option 1 fits the forces best. The call count stays at one however large the scan is, which matters on a cron queue where a long download job would hit the timeout. The extra public addresses are the same kind we already run and protect (token in the path, secret header, size check, one empty `404`), so the risk is known and the code is mostly a reuse of the 0004 middleware.

Our own completeness check is what turns "Copyleaks says it sent everything" into "we hold everything". It is cheap, because the expected result IDs are already in the saved summary and are copied into the `export_started` event. Option 3 would save about a screen of code but moves the failure to the worst moment, a report build after the data is gone. The engineer chose the retry, sweep and give up rules (3 attempts, 5 minute sweep, 48 hours) in the design conversation, and each one maps to an acceptance criterion.

The decision to track attempts in `scan_events` instead of new columns follows spec 0002 (no new column for later slices). The unique key on (scan, status, kind) makes the claim of an attempt atomic with no extra locking, which is the same trick the 0004 webhook uses for repeats. The engineer declined a shared reader class, so each report feature reads and checks its own files. That is a fair choice for now, and the Follow-up keeps the question open.

The delete step is a separate job and a failed delete is only logged. Mixing it into the finish step would let a minor cleanup failure break a good scan. The 72 hour expiry we already send is the backup, and `remote_deleted_at` is set only when Copyleaks accepts the delete.

## References

**Project sources**:
- `AGENTS.md` and `app/Services/Copyleaks/AGENTS.md`, the money safety and logging rules
- Spec 0001, the raw bundle as source of truth, the cron driven database queue and the hosting limits
- Spec 0002, the `scan_artifacts` and `scan_events` rules, `ScanTransitioner`, and the `fetching` to `completed` move
- Spec 0004, the webhook checks, the saved summary, the `ScanResultsArrived` seam and the sandbox findings (AI alert, no AI result ID)
- The saved sandbox summary under `storage/app/private/scans/` (where the result IDs and the AI alert were read from)

**Practices & standards**:
- Idempotent receivers: a repeated delivery must change nothing
- Write to a temp file, then rename, so a half written file is never seen
- Insert only storage for raw data, with a checksum per file
- Verify what you hold instead of trusting a remote success flag
- Copyleaks documentation pages for the export call, the export completed webhook, scan delete and scan expiry (read through Context7 on 2026-11-02; no links are listed because none were web verified)
