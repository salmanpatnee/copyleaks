# 0001. Stack and architecture for the manuscript report tool

**Date**: 2026-10-31
**Status**: Proposed

## Summary

We build one Laravel 13 web app (a "monolith", meaning a single app, not many small services) with a Vue 3 front end. It runs on Hostinger shared or Cloud hosting, which is cheap but cannot run a browser or an always running worker. Because of that, PDFs are built with a pure PHP tool (mPDF), background jobs run from a cron job every minute, and the first version accepts PDF uploads only. The app keeps MySQL for data, the private disk for files, and the official Copyleaks PHP package to talk to Copyleaks.

## Decision

**Chosen option**: Option 1: Single Laravel monolith on Hostinger shared or Cloud hosting, pure PHP PDF stack.

One deployable Laravel 13 app, with MySQL, a database queue drained by cron, local private file storage, mPDF for report pages, and the official Copyleaks PHP SDK behind our own interface.

**Implementation skills**: `shadcn-vue` (`unovue/shadcn-vue`, `.agents/skills/shadcn-vue/`)

**MCP servers** (engineer connects in their own MCP settings): shadcn-vue MCP (registry search and install).

## Proposed stack

| Layer | Choice | Reason |
|---|---|---|
| Architecture pattern | Monolith (one Laravel app, controllers, services, jobs) | Small internal tool, small team. Nothing here needs a second service. |
| Language (back end) | PHP 8.3 or newer (Laravel 13 needs 8.3+; local is 8.3.19) | Matches the framework. The Hostinger PHP version must be set to 8.3 or newer. |
| Framework | Laravel 13 | Already chosen in the scope. |
| Language (front end) | TypeScript with Vue 3 (Composition API) | Comes with the official Laravel Vue starter kit. |
| Front end glue | Inertia (version that the starter kit ships) | Already chosen. No separate API layer to build. |
| UI components | shadcn-vue with Tailwind | Already chosen. Ships with the starter kit. |
| Auth | Laravel Fortify through the starter kit, login only, no public sign up | Already chosen. Details belong to the staff login feature. |
| Primary DB | MySQL 8 | Already installed locally, used in your other Laravel projects, and has JSON columns for raw Copyleaks replies. |
| Background jobs | Laravel database queue, drained by cron every minute with `queue:work --stop-when-empty` | Shared hosting has no permanent worker. Volume is tiny, and a delay of up to a minute is fine for scans that take minutes. |
| Sessions and cache | `database` and `file` drivers | No Redis on shared hosting. Nothing here needs fast cache. |
| File storage | Laravel `local` disk, private (outside `public/`), downloads only through a signed in route | Simple. Can move to S3 later by config. |
| Copyleaks client | Official PHP SDK `copyleaks/php-plagiarism-checker`, wrapped behind our own `ScanClient` interface | Less code to write. The interface lets tests swap in a fake so no real scan can run by accident. |
| PDF report pages | mPDF, rendering our own HTML and CSS templates | Pure PHP, runs on shared hosting. Better font and page size support than Dompdf. Can import pages from an uploaded PDF. |
| Manuscript pages | Import the uploaded PDF's own pages into the report (mPDF's PDF import, built on FPDI) | Keeps the original page size and look. |
| Upload types (first version) | PDF only | Word needs a converter that shared hosting cannot run. Word comes later. |
| Tests | Pest | Your preferred framework. Confirm the starter kit's default and align it when scaffolding. |
| Hosting | Hostinger shared or Cloud hosting (PHP 8.3+, SSH, cron, MySQL) | Engineer's choice. See the risks in Consequences. |
| Deployment | Build front end assets locally, push code with Git, then `composer install` and `php artisan migrate` over SSH | Shared hosting often has no Node. |
| Local development | Laravel Herd, MySQL 8, Node 22 with npm | Already installed. |
| Local webhook testing | Herd's built in site sharing (public HTTPS address) | Copyleaks must reach your laptop. Same flow as production. |
| Observability | Laravel daily log files, no extra service | Internal tool. Secrets and manuscript text must never be logged. |

## Stack rules the build must follow

- **One submit per upload, never automatic.** The submit call to Copyleaks starts only from a person's upload action. No scheduler, loop, listener or test may call the submit endpoint. In the `testing` environment the real `ScanClient` must refuse to run.
- **Submit is never retried blindly.** The submit job has `tries = 1`. The scan row is saved with status `submitting` before the call. A "duplicate scan ID" reply (409) means the scan was already sent.
- **Read calls are allowed for recovery.** Staff get a "fetch results" action that only uses Copyleaks read endpoints, so a lost webhook never loses a paid scan.
- **Sandbox by default.** `COPYLEAKS_SANDBOX=true` everywhere except production. The submit call sends `properties.sandbox = true` when it is on. Sandbox results are mock data and cannot test highlights, so run one small real scan early and keep its saved bundle as a test fixture.
- **Webhook routes (status and export callbacks).** Public `POST`, exempt from CSRF, throttled. Each carries a random token of 32 or more bytes in the URL path. Only its SHA 256 hash is stored, and it is compared with `hash_equals`. A shared secret header is checked as a second layer. The token is kept out of application logs. Handlers only save the payload and queue a job, and they are idempotent: unique on (scan_id, status, kind), repeats and out of order callbacks are ignored.
- **Raw bundle is the source of truth.** For each scan, save the completion summary, every result, and the text version, exactly as received, each as a compressed file on the private disk, with a path and SHA 256 checksum in the database. Fetch them through the Copyleaks export route (exact endpoints to be confirmed in the Copyleaks scan spec). Reports are always built from the bundle. Copyleaks deletes scan data after its expiration, so the bundle must be saved first.
- **Idempotent scan IDs.** The scan ID is generated by us once per submission (3 to 36 characters, unique). A new scan ID is created only when staff retry on purpose.
- **Duplicate uploads.** Uploads have a unique SHA 256 file checksum. Scanning the same file again needs an explicit staff override, and the submit button is disabled while a scan is running.
- **Upload limits.** Maximum 20 MB and 100 pages. The page count is checked with the PDF import library before submitting. Encrypted PDFs are rejected. The estimated credit cost (one page per 250 words) is shown before submit. Set `upload_max_filesize` and `post_max_size` to 25M (via `.user.ini`). Base64 adds about 33% in memory.
- **Copyleaks login token.** Cached in the file cache for 47 hours. On a 401, log in again once.
- **Secrets.** Copyleaks credentials live in `.env` only, never in the browser, logs or Git.
- **Retention.** Placeholder, to be confirmed: uploads and raw bundles are deleted after 90 days; finished reports follow the same rule. After the bundle is saved, the scan is deleted on Copyleaks through its delete endpoint (to be confirmed in the Copyleaks scan spec).
- **Cron entry (one line).** `* * * * * <php path> <app path>/artisan schedule:run`. Use the real CLI PHP path from `which php` on Hostinger, because it can differ from the web PHP version.
- **Schedule (in code).** `Schedule::command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping(10)`. Each job sets `$timeout = 240`, and `retry_after` in `config/queue.php` is 300. Report build jobs use `tries = 2` with a backoff.
- **mPDF settings.** `tempDir` is `storage/app/mpdf`. Fonts are registered through `fontDir` and `fontdata`. The CLI `memory_limit` is raised to 512M with `-d memory_limit=512M`. Highlights use `SetAlpha(0.8)` and rounded shapes drawn over imported pages, to be proven in the spike.
- **Fonts.** Lexend Deca and Noto Sans are shipped as TTF files in the repo for mPDF (after the licence check).
- **Highlight positions are an open risk.** Copyleaks gives matches as character offsets in its own extracted text, not as positions on the PDF page. Turning offsets into page positions in pure PHP must be proven in a spike on 3 real manuscripts before the highlight features are built. **Fallback if the spike fails:** show the manuscript as re flowed text from the Copyleaks text version, with highlighted spans in mPDF. It always lines up but loses the original page look.

**Configuration required**:
- `COPYLEAKS_EMAIL`: account email for the Copyleaks login call
- `COPYLEAKS_API_KEY`: Copyleaks API key
- `COPYLEAKS_SANDBOX`: `true` or `false`
- `QUEUE_CONNECTION=database`, `SESSION_DRIVER=database`, `CACHE_STORE=file`
- `FILESYSTEM_DISK=local`
- `DB_*`: MySQL connection

## Consequences

**Positive**:
- One app, one database, one deploy. Easy to run on a quiet Tuesday.
- No server tools to install (no Chromium, no LibreOffice, no Redis, no Supervisor).
- Tests cannot spend Copyleaks credits by accident.
- A saved raw bundle plus a pure PHP renderer means old reports can be rebuilt without scanning again.

**Negative / tradeoffs**:
- mPDF supports less CSS than a browser. Rounded shapes, 80% opacity highlights and exact positioning in the Turnitin style layout may need workarounds. This is the biggest technical risk and goes to the Report PDF engine spec.
- Shared or Cloud hosting limits: memory and time limits, a possible small upload size, no root access. A 75 page report may hit the memory limit.
- Jobs wait up to about a minute for the next cron run.
- PDF only uploads. This is a scope change from "Word or PDF" in the brief.
- The official SDK adds a dependency we do not control. The wrapper keeps it replaceable.

**Neutral**:
- Moving to a VPS later is a change in hosting only. Chromium based PDFs and a permanent worker would then become options.

## Follow-up

- [ ] **Scope change:** update `docs/scope.md` and `docs/brief.md`: first version accepts PDF only. Word support moves to a later feature (needs a converter).
- [ ] Confirm with Hostinger: PHP 8.3+ available, SSH access, cron every minute, `upload_max_filesize` and `post_max_size` large enough for manuscripts, `memory_limit` and `max_execution_time` for CLI jobs, and that a public HTTPS webhook URL works.
- [ ] Confirm what the Copyleaks sandbox costs. The scope says sandbox credits are limited, while the Copyleaks docs say sandbox returns mock results with no credits (100 sandbox scans per hour). Test with one small file.
- [ ] Verify the Laravel Vue starter kit's exact versions (Inertia, Vue, Tailwind, shadcn-vue) and its default test framework when scaffolding.
- [ ] The starter kit turns on two factor auth by default. Decide in the Staff login feature whether to keep it.
- [ ] **Spike first:** on 3 real manuscripts, prove (a) mPDF can draw the key shapes (rounded pills, 80% opacity highlights, margin badges, mixed page sizes) and (b) Copyleaks character offsets can be mapped to positions on the imported PDF pages in pure PHP. Run it before the highlight features, as part of the Report PDF engine and Highlights specs. If (b) fails, use the re flowed text fallback above.
- [ ] Confirm the retention period (placeholder is 90 days) and the exact Copyleaks export, download and delete endpoints in the Copyleaks scan spec. These came from a cross check and were not verified against the docs.
- [ ] Confirm font licences for Lexend Deca and Noto Sans (already open in the brief).
- [ ] Connect the shadcn-vue MCP server in your MCP settings. I cannot do this for you.
- [ ] `shadcn-vue` skill conventions are not yet in root `AGENTS.md` `## Agent skills`; this applies to every screen and belongs at root level (`/audit` writes it).
- [ ] Declined, so nothing should offer them again: skills `vuejs-ai/skills@vue-best-practices`, `jasim-k/laravel-agent-skills@laravel-inertia-vue`, `asyrafhussin/agent-skills@laravel-best-practices`; MCP servers: official Copyleaks MCP, MySQL MCP.

## Rationale

Reasoning, options and references: see [rationale.md](rationale.md).
