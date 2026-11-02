# Manuscript Report Tool

An internal tool for staff. A staff member uploads a manuscript, Copyleaks checks it, and the tool builds PDF reports in the Turnitin style layout (our own name and logo). See `docs/brief.md` and `docs/scope.md`.

## Stack

Decided in spec 0001 (`docs/specs/0001-stack-and-architecture/index.md`). Read it before changing the stack.

- **Language / Runtime**: PHP 8.3+, TypeScript with Vue 3 (Composition API), Node 22
- **Framework**: Laravel 13 with Inertia 3, Fortify (login only, no public sign up), Wayfinder
- **UI**: shadcn-vue (reka-ui) with Tailwind 4
- **Data and jobs**: MySQL 8, database queue drained by cron, private `local` disk
- **Integrations**: official Copyleaks PHP SDK behind our own `ScanClient` interface, mPDF for PDFs (both arrive with their features)
- **PDF reading**: `smalot/pdfparser` for page counts and the encrypted check on upload (installed with spec 0003)
- **Tests**: Pest 4
- **Package manager**: Composer and npm
- **Hosting**: Hostinger shared or Cloud (no browser, no always running worker)

## Build approach

Tracer Bullet: build one thin, real thread through every layer first, then thicken it one strand at a time.

## Commands

```bash
# Install and set up
composer setup

# Dev server (Herd serves http://copyleaks.test; this also runs queue and Vite)
composer dev

# Build front end
npm run build

# Test (Pest). After adding or renaming a Vue page, run `npm run build` first: the page tests read public/build
php artisan test

# Lint PHP / check front end / types
composer lint && npm run check:fix
composer types:check && npm run types:check

# Everything CI would run
composer ci:check

# Turn on the pre-commit check (also runs inside composer setup)
composer hooks:install

# Build a sample report PDF from made up data (no Copyleaks call)
php artisan report:sample
```

## Specs

Stored in `docs/specs/`. Format: `docs/specs/NNNN-title.md`. Scope is in `docs/scope.md`.

## Git

- integration: off

## Rules

- **Style**: SOLID OOP. One reason to change per class, small classes (aim under 200 lines), depend on interfaces, inject dependencies through constructors, no service locator. Prefer composition over inheritance.
- **Folders**: keep Laravel's normal layout (`app/Http`, `app/Models`, `app/Actions`). Add `app/Services` and `app/Jobs` as needed. Vue pages live in `resources/js/pages`.
- **More folders in use**: `app/Enums` (backed enums) and `app/Exceptions` (domain exceptions).
- **Types are strict**: typed PHP signatures everywhere, PHPStan level 7, no `any` in TypeScript.
- **Copyleaks safety**: a scan starts only from a person's upload action. Tests always use a fake `ScanClient`, so no real scan ever runs.
- **Secrets**: Copyleaks keys live in `.env` only. Never log keys, webhook tokens or manuscript text.
- **Tests**: Pest unit and feature tests for every feature. Tests use the in memory sqlite database set in `phpunit.xml`.
- **Test style**: new tests are Pest function style, with `uses(TestCase::class, RefreshDatabase::class);` at the top of each database test file (there is no `tests/Pest.php`).
- **Commits**: use conventional commit messages (`feat:`, `fix:`, `chore:`).
- **Tooling** (installed by `/develop tooling`): keep the scaffold tools (Pint, PHPStan, `npm run check`). Run lint, format and types before every commit through a git hook. CI is not wanted yet, so leave `.github/workflows/tests.yml` as shipped and do not extend it.

## Agent skills

- [shadcn-vue](.agents/skills/shadcn-vue/): `unovue/shadcn-vue`, governs every UI component, page and `components.json` change

Declined: vue-best-practices, laravel-inertia-vue, laravel-best-practices, Copyleaks MCP, MySQL MCP, mPDF skills
MCP servers: shadcn-vue (recommended)

## Context files

- [app/Models/AGENTS.md](app/Models/AGENTS.md): data model records, enums and the guards around scan status, artifacts and deletes
- [app/Services/Copyleaks/AGENTS.md](app/Services/Copyleaks/AGENTS.md): Submit, the send job, the public webhook, resend, and the money safety rules
- [app/Services/Manuscripts/AGENTS.md](app/Services/Manuscripts/AGENTS.md): upload screen file checks, safe store, size limits and the oversized post gotcha
- [app/Services/Reports/AGENTS.md](app/Services/Reports/AGENTS.md): report PDF engine on mPDF, sections, theme, imported pages and the sample command
- [app/Services/Reports/Manuscript/AGENTS.md](app/Services/Reports/Manuscript/AGENTS.md): manuscript pages (original or text mode), word matching, thresholds, the check command and the smalot matrix gotcha

<!-- Nested AGENTS.md files are listed here as they are created -->

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
