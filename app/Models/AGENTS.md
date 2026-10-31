# Data model (models, enums, scan guards)

## Overview

The records behind the tool: a submission (the upload and its cover details) has many scans (each try at Copyleaks), and each scan has events, raw artifacts and reports. Decided in spec 0002 (`docs/specs/0002-data-model/index.md`). Read it before changing a table or a rule.

## Key files

| File                                                                 | Owns                                                    |
| -------------------------------------------------------------------- | ------------------------------------------------------- |
| `database/migrations/2026_10_31_000000_create_submission_tables.php` | The five tables, with unique keys, indexes and cascades |
| `app/Models/Submission.php`                                          | `public_id` ULID, `latestScan`, derived `status`        |
| `app/Models/Scan.php`                                                | One Copyleaks attempt. `status` is not fillable         |
| `app/Enums/ScanStatus.php`                                           | The allowed move table (`canMoveTo()`, `allowedFrom()`) |
| `app/Services/ScanTransitioner.php`                                  | The only place a scan status changes                    |
| `app/Services/ReportReplacer.php`                                    | Safe report rebuild (write new, switch row, delete old) |
| `app/Actions/DeleteSubmission.php`                                   | Hard delete: rows first, then files                     |
| `database/factories/`                                                | Row only factories. They never call Copyleaks           |

## Conventions

- Status and kind columns are plain strings with backed enum casts. No MySQL `ENUM`.
- The route key for a submission is `public_id` (ULID). The numeric `id` never reaches the browser.
- Submission status for lists is worked out from the latest scan (highest `id`). It is not stored.
- Files live on the private `local` disk: `submissions/{public_id}/`, `scans/{copyleaks_scan_id}/`, `reports/{scan_id}/`.
- Webhook events are saved with `insertOrIgnore` or by catching the unique violation, so repeats are safe.

## Gotchas

- Never set `scans.status` directly. Use `ScanTransitioner` (one guarded update, zero rows changed means skip). `completed` and `failed` can never move again.
- `scan_artifacts` is insert only: no `updated_at`, and `save()` on an existing row throws.
- `source_id` on artifacts is `''` (not null) for summary and text, or the unique rule stops working.
- Database cascade does not remove files. Delete through `DeleteSubmission`, which also refuses while a scan is running.
- `scans.webhook_token_hash` is hidden and must never be logged or sent to the browser.
- Same file checksum on many submissions is allowed on purpose (a plain index, not unique).
- `submissions.page_count` is null for Word files (unknown). `Submission::isWord()` tells them apart. Handle null in every feature that reads it.
- Tests use in memory sqlite with foreign keys on. Keep the cascade test that fails if they are off.

## Related specs

- [0002 data model](../../docs/specs/0002-data-model/index.md)
- [0003 upload screen](../../docs/specs/0003-upload-screen/index.md)

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
