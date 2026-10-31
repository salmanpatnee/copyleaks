# 0001. Stack and architecture: rationale

Build spec: [index.md](index.md)

## Context

> ⚠️ Premise note: The hosting choice (Hostinger shared or Cloud) works against the PDF goal. The report must match a Turnitin style layout (custom fonts, exact page sizes, rounded shapes, 80% opacity highlights, margin badges). A real browser engine renders that most faithfully, and shared hosting cannot run one. Word files also need LibreOffice, which shared hosting cannot run. The risk is a PDF that looks close but not exact, and a first version that cannot take Word files. The simpler framing would be a small VPS. The engineer chose to stay on shared or Cloud hosting, so this spec accepts that and plans around it.

This is a new internal tool for staff (an empty repo with scope and design docs). Staff upload a manuscript, Copyleaks checks it, and our app saves the raw results and builds PDF reports in a Turnitin style layout with our own branding. Customers never log in.

Forces that shaped the stack:
- The scope already fixed Laravel 13, Vue 3 with Inertia, the Laravel Vue starter kit (Fortify), shadcn-vue and a Laravel queue.
- Hosting is Hostinger shared or Cloud. No root access is assumed, so no Chromium, no LibreOffice, no Redis and no permanent worker.
- Volume is low (staff uploads), but each Copyleaks scan costs credits, so accidental scans must be impossible.
- Reports are rebuilt from saved raw JSON, so rendering must be repeatable and cheap.
- Local machine: Windows with Herd, PHP 8.3.19, Node 22, MySQL 8.

Without a decision, scaffolding starts without knowing the PDF tool, the queue method or the upload types, and each of those changes the design of several later features.

## Options considered

### Option 1: Monolith on shared or Cloud hosting, pure PHP PDF stack (chosen)

One Laravel app. MySQL, database queue drained by cron, local private disk, mPDF for report pages, PDF import for manuscript pages, official Copyleaks PHP SDK behind our interface.

**Pros**:
- Fits the hosting the engineer chose, with nothing to install on the server.
- Cheapest to run, simplest to deploy.
- mPDF has real font, page size and header and footer support.

**Cons**:
- Less CSS support than a browser, so exact layout is harder and may need workarounds.
- PDF only uploads in the first version.
- Cron adds up to about a minute of delay to jobs.

### Option 2: Monolith on a VPS, Chromium based PDFs

Same app on a Hostinger VPS (or similar), HTML to PDF through headless Chromium with `spatie/laravel-pdf`, LibreOffice for Word, a Supervisor managed worker.

**Pros**:
- Closest match to HTML and CSS templates. Layout is easiest to get exactly right.
- Word and PDF both supported from day one.
- A proper permanent worker.

**Cons**:
- Costs more and needs server administration (updates, Chromium, LibreOffice, Supervisor).
- More things to break that a small team must operate.
- A small VPS costs about the same as Hostinger Cloud, so cost alone does not rule it out. Note that a VPS would not fix the highlight position problem either, because mapping Copyleaks offsets to page positions is needed with any renderer.

### Option 3: Shared hosting app plus an outside PDF or conversion service

Keep the Laravel app on shared hosting, and call a separate service (for example a Gotenberg server somewhere else) for Chromium PDFs and Word conversion.

**Pros**:
- Browser quality PDFs and Word support without a full VPS for the app.

**Cons**:
- A second system to host, secure and pay for. Manuscripts travel to another service, which adds a data handling question on top of Copyleaks.
- More moving parts than a proof of concept needs.

### Smaller choices (each decided in the stack walk)

| Layer | Chosen | Runner up |
|---|---|---|
| Database | MySQL 8 | PostgreSQL, SQLite |
| Queue | Database queue | Redis, sync |
| File storage | Private local disk | S3 compatible |
| Copyleaks client | Official PHP SDK behind our interface | Laravel HTTP client wrapper (my recommendation) |
| Tests | Pest | PHPUnit |
| Deployment | Local build, Git push, SSH commands | FTP upload, GitHub Actions |
| Local webhook | Herd site sharing | Expose or ngrok, polling |
| Observability | Daily log files | Sentry or similar |

## Rationale

Option 1 is chosen because hosting is the strongest force, and the engineer fixed it as Hostinger shared or Cloud. Under that limit, the only stack that runs without server tools is a pure PHP one. mPDF is the best fit inside that group because the report depends on custom fonts, exact page sizes and mixed page sizes, where Dompdf is weaker. Importing the uploaded PDF's pages keeps the original page size and look, which the scope requires.

My recommendation was Option 2 (a VPS with Chromium), and the engineer chose to stay on shared or Cloud hosting. Option 1 works, but the engineer accepts that the layout may be a close copy rather than an exact one, that Word files wait, and that the plan needs early proof. The follow-up list makes that proof the first job of the Report PDF engine spec. If the proof fails, the cleanest escape is Option 2, and the monolith structure means only hosting and the renderer change.

Other choices follow the boring and proven rule. A database queue and database sessions avoid Redis. A private local disk avoids a storage account. Daily logs avoid a new service that could receive manuscript text.

On the Copyleaks client, I recommended the Laravel HTTP client with a thin wrapper, because it is easiest to fake in tests. The engineer chose the official SDK. That works as long as the SDK sits behind our own `ScanClient` interface, which gives the same test safety, so the rule is part of the spec.

## Cross check (second model, 2026-10-31)

A read only review found 11 decision gaps and 3 wording points. All recommended fixes were applied to `index.md` (stack rules and follow ups). The biggest finding: Copyleaks match positions are character offsets in its own text, so mapping them to PDF page positions is unproven, and the choice of renderer does not change that. It is recorded as an open risk with a fallback. The reviewer's Copyleaks endpoint details were not checked against the docs and are marked to confirm.

## Evidence: current landscape check (2026-10-31)

Collected once during the design conversation. Items I could not confirm are marked unverified.

- Laravel 13 needs PHP 8.3 or newer (8.3 to 8.5 supported). Released 2026-03-17, security fixes to 2028-03-17.
- The Vue starter kit uses Inertia, Vue 3 (Composition API), TypeScript, Tailwind, shadcn-vue and Laravel Fortify, with two factor auth on by default. Exact version numbers, default test framework and default queue driver: unverified.
- `spatie/laravel-pdf` renders through Chromium and needs PHP 8.2+. It was evaluated and set aside because it needs Chromium on the server. Its other drivers: unverified.
- Gotenberg (Chromium and LibreOffice routes): confirmed only from search snippets, unverified.
- Copyleaks has an official PHP SDK. Login returns a token (lifetime reported as about 48 hours, unverified on the page) sent as a bearer token. Submit is `PUT /v3/scans/submit/file/{scanId}` with base64 content; it returns 201 with no body and results arrive by webhook (`{STATUS}` in the webhook URL becomes `completed`, `error`, `creditschecked` or `indexed`). Sandbox mode returns mock results, uses no credits, is limited to 100 scans an hour and must be off in production. Copyleaks can also create its own PDF export, which we do not use because we build our own report.
- Not researched: Dompdf and mPDF install and feature detail, poppler tools. The mPDF choice rests on general knowledge and must be proven early.

## References

**Project sources**:
- `docs/scope.md` (stack already chosen, build approach, Slice 0 rules)
- `docs/brief.md` (workflow, rules we keep, open questions)
- `docs/turnitin-report-design.md` (page sizes, fonts, colors, shapes that the PDF tool must reproduce)

**Practices & standards**:
- Monolith first for a small team
- Database backed queue before a dedicated broker
- Never store files in the database; keep uploads outside the public folder
- Idempotency: one scan ID per submission
- Keep third party clients behind an interface so tests never hit the real service

**Links** (web verified):
- Laravel 13 release notes: https://laravel.com/framework/docs/releases
- Laravel 13 starter kits: https://laravel.com/docs/13.x/starter-kits
- Copyleaks PHP SDK: https://docs.copyleaks.com/resources/sdks/php
- Copyleaks submit file API: https://docs.copyleaks.com/reference/actions/authenticity/submit-file
- spatie/laravel-pdf requirements: https://spatie.be/docs/laravel-pdf/v1/requirements
