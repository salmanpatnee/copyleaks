# 0012. Our own cover details

**Date**: 2026-11-09
**Status**: In Progress

## Summary

Today the cover page shows only the Submission ID, the file name and a word count, because the report data leaves the other details empty. This spec fills them in: a Submission ID in the Turnitin shape (`trn:oid:::3117:646033185`) that we make ourselves at upload, the upload date and the report build date in UTC, the file size, the page count and the character count. The cover drawing code does not change. It already draws each detail the moment it has a value, and the same ID also appears in the header and footer of every page.

## Requirements

**User stories**:
- As a staff member, I want the cover to show the submission date, download date, file size, pages and characters, so that it reads like the Turnitin cover our customers know.
- As a staff member, I want the Submission ID on the report to be our own, so that it does not change when a scan is re-sent and does not expose a Copyleaks ID.
- As the developer, I want every cover value to come from one named source, so that nobody guesses a value later.

**Acceptance criteria** (the contract, each one independently checkable):
- **AC-1**: Every new submission gets a 9 digit number (100000000 to 999999999), chosen at random, saved in a new unique column `submissions.submission_number`. The report shows the Submission ID as `{prefix}{account}:{number}`, for example `trn:oid:::3117:646033185`. The cover, the header and the footer all show this same string, and no Copyleaks scan ID appears on any report page. This includes the check PDF of `report:manuscript` (`CheckManuscriptPages`), which today passes `copyleaks_scan_id` as its header ID and must use the formatted ID too. A duplicate upload of the same file gets a new number. A re-sent scan keeps the number of its submission.
- **AC-2**: Two submissions can never share a number. The upload picks a number that is not used yet (up to 5 tries), before the file is stored, so nothing needs cleaning up if it gives up. If it gives up it throws `SubmissionNumberUnavailable` (in `app/Exceptions`), handled like `ManuscriptStorageFailed` with the same "could not save" message. If the database still rejects a number (two uploads at the same instant), the existing catch removes the stored folder and the upload fails like any other save failure.
- **AC-3**: The migration gives every existing submission a distinct number, so no old scan stops building (`submission_id_missing`). It uses plain code inside the migration (not the app's generator class, which may change later): read the numbers already used into a PHP set, pick a random free number per row, update with `chunkById`, then make the column required (`->change()`) and add the unique index. It has a `down()` that drops the index and then the column. The `submission_id_missing` check stays as a backstop.
- **AC-4**: The prefix and the fixed account part are settings (`report.id_prefix`, default `trn:oid:::`, and `report.id_account`, default `3117`), read through config, never typed in code. Changing them changes the ID on every report with no data change.
- **AC-5**: Submission Date is the time the file was uploaded (`submissions.created_at`). Download Date is the time the report is built (the clock at mapping time). Both are shown in UTC in this form: `Oct 28, 2026, 4:12 PM GMT+0` (month short name, day without a zero, year, 12 hour time with AM or PM, then `GMT+0`). The format string is `'M j, Y, g:i A'` followed by the fixed text ` GMT+0`, in English with no locale translation. The formatter converts with `->utc()` so it stays right even if the app time zone changes. Download Date uses `Carbon::now()` (never `time()` or `date()`) so `Carbon::setTestNow` works. A submission with no `created_at` leaves Submission Date out.
- **AC-6**: File Size comes from `submissions.file_size` and is shown with 1024 as the step and one decimal: `812 B`, `113.9 KB`, `2.4 MB`, `1.0 GB`. Bytes under 1024 show no decimal. A value that would round to `1024.0` moves up to the next unit, so 1,048,575 bytes shows `1.0 MB`, never `1024.0 KB`.
- **AC-7**: Pages comes from `submissions.page_count`. A null (a Word file) or a 0 leaves Pages out of the stats box. The page count is never guessed for a Word file.
- **AC-8**: Characters is the length of the text Copyleaks scanned (the same length the mapping already reads for highlights), written with thousands separators. Words stays the Copyleaks total. The column `submissions.character_count` stays unused on purpose, and nothing fills it.
- **AC-9**: A detail with no value is still left out cleanly (spec 0009 AC-2). A submission with no number stops the build with the existing `submission_id_missing`. The mapping folder still has no `Log::`, `ScanClient`, `Http::`, `SubmitScan` or database write.
- **AC-10**: `report:cover`, `report:overview`, `report:similarity` and `report:sample` all build with the new values, and the `Left out:` line of `report:cover` names only the spots that really had no value (for a PDF upload: none). `report:sample` keeps its fixed made up data (its own fake ID and dates) and builds unchanged.

## Decision

**Chosen option**: Option 1: Fill the existing `DocumentDetails` from data we already hold, plus one new column for our own ID.

Add `submissions.submission_number`, build the Submission ID from config plus that number, and let `DetailsMapper` pass dates, size, pages and characters into the `DocumentDetails` object that the cover already knows how to draw. No change to `CoverSection`.

## Feature design

**Data model sketch**:

| Table | Change |
|---|---|
| `submissions` | add `submission_number` unsigned big integer, unique index (required after the backfill) |

- Nullable only while the migration adds the column and fills old rows. The same migration then makes it required and adds the unique index. The upload action always sets one. A null at report time would still stop the build (AC-9), as a backstop.
- The migration adds the column, fills every existing row with a free random number, then adds the unique index. It does not edit the earlier migration (`2026_10_31_000000_create_submission_tables.php` has already run).
- `public_id` (the ULID) stays the key for URLs and commands. `submission_number` is only for the printed ID.

**State transitions**: none.

**API surface**: no new route or screen. The upload screen is unchanged. Changed code only.

| Piece | Change |
|---|---|
| `StoreSubmission` (upload action) | asks `SubmissionNumberGenerator` for a free number before `putFileAs`, and saves it with `forceFill` next to `public_id` (so a form post can never set it; not in `#[Fillable]`). `Submission` gets a `@property int|null $submission_number` line for PHPStan |
| `SubmissionFactory` | gives each row a unique random 9 digit number, so existing report tests keep building |
| `AppServiceProvider` | binds `ReportIdFormatter` with `config('report.id_prefix')` and `config('report.id_account')`. Both keys are added to `config/report.php` with `env(...)` and the defaults above |
| `CheckManuscriptPages` (`report:manuscript`) | uses the formatted ID as its header ID instead of `copyleaks_scan_id` |
| `ReportMappingTest` line 93 | the one existing assertion that expects `submissionId` to equal `copyleaks_scan_id` is changed to the formatted ID (an update, not a new test) |
| `SubmissionNumberGenerator` (new, `app/Services/Manuscripts` or next to the model) | `next(): int`, random 9 digits, checks the table, up to 5 tries, then throws a domain exception |
| `ReportIdFormatter` (new, in `Reports/Mapping`) | `format(int $number): string`, reads `report.id_prefix` and `report.id_account` through constructor values |
| `ReportDateFormatter` (new, in `Reports/Mapping`) | `format(CarbonInterface): string`, always converts to UTC first |
| `FileSizeFormatter` (new, in `Reports/Mapping`) | `format(int $bytes): string` |
| `DetailsMapper::map()` | now also passes `submissionId`, `submissionDate`, `downloadDate`, `fileSize`, `pages` and `characters`; takes `textLength` as an extra argument from `ReportDataMapper` |

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Upload | `submission_number` | `SubmissionNumberGenerator`, random 100000000 to 999999999, unique in the table |
| Map details | Submission ID string | `ReportIdFormatter` from config `report.id_prefix`, `report.id_account` and `submissions.submission_number` |
| Map details | Submission Date | `submissions.created_at`, converted to UTC |
| Map details | Download Date | `Carbon::now()` at mapping time, converted to UTC (the report is built in the same run) |
| Map details | File Size | `submissions.file_size` (bytes), through `FileSizeFormatter` |
| Map details | Pages | `submissions.page_count` (null for Word, then left out) |
| Map details | Characters | `$textLength` that `ReportDataMapper` already reads from the saved Copyleaks text (UTF-16 units, the unit Copyleaks offsets use). Nothing extra is read |
| Map details | Words | unchanged, Copyleaks total from the summary |
| Header and footer | ID on every page | `details.submissionId` through the existing `CoverReportFactory` header ID (no change) |

**Key invariants**:
- `submission_number` is unique (database index) and never changes after upload.
- The Submission ID string is never stored. Only the number is. The string is built every time from config.
- The dates are always printed in UTC, whatever the app time zone says.
- Mapping never writes to the database (clock read only).

**Security model**: internal staff tool, no new route. The number is not a secret. It is random so it does not show how many uploads exist. No manuscript text is logged or stored by this change.

**Configuration required**:
- `REPORT_ID_PREFIX` (default `trn:oid:::`): the front part of the Submission ID
- `REPORT_ID_ACCOUNT` (default `3117`): the fixed middle number

**Critical test scenarios** (listed so `/test` can pick them up later; tests are not written in `/develop`):
- Happy path: upload a PDF, build the cover, see `trn:oid:::3117:<9 digits>`, both dates, `NN.N KB`, pages and characters, verifies **AC-1**, **AC-5**, **AC-6**, **AC-7**, **AC-8**
- Edge: a Word upload shows no Pages line and no gap, verifies **AC-7**, **AC-9**
- Failure: a taken number triggers a retry; five taken numbers throw, verifies **AC-2**
- Migration: old rows all get distinct numbers, verifies **AC-3**
- Config: change the prefix and the ID changes, verifies **AC-4**
- Size edges: 0, 1023, 1024, 1048575, 1048576 bytes, verifies **AC-6**

## Build plan

Tracer Bullet: one thin real thread first (a real number and a real date on a cover), then thicken it one strand at a time. Tests are not in this plan (project rule: only on request). `/check verify` then `/test our own cover details` close it at the Beta tier.

- [x] 1. Thin thread: migration for `submission_number` (add, backfill inline, required, unique index, `down()`), `SubmissionNumberGenerator`, `StoreSubmission` saves it with `forceFill`, `@property` line, `SubmissionFactory` number, `ReportIdFormatter` bound in `AppServiceProvider` with the two config keys, `DetailsMapper` returns the new Submission ID; run `report:cover` and see the new ID on the cover, satisfies **AC-1**, **AC-3**, **AC-4**
- [x] 2. Thicken the ID: collision retry and `SubmissionNumberUnavailable` handled like `ManuscriptStorageFailed`, `CheckManuscriptPages` uses the formatted ID, update `ReportMappingTest` line 93, check that the header and footer show the same string on every page of `report:similarity`, satisfies **AC-1**, **AC-2**, **AC-9**
- [x] 3. Thicken the dates: `ReportDateFormatter` (UTC, one format), Submission Date from `created_at`, Download Date from the clock, satisfies **AC-5**
- [x] 4. Thicken size and counts: `FileSizeFormatter`, Pages from `page_count`, Characters from `textLength` passed through `ReportDataMapper`, satisfies **AC-6**, **AC-7**, **AC-8**
- [ ] 5. Finish and check: run `report:cover`, `report:overview`, `report:similarity` and `report:sample`, read the `Left out:` line, look at the cover by eye for five field pairs and a three line stats box, bump `MpdfReportRenderer::VERSION` only if the cover look moved, satisfies **AC-9**, **AC-10**

## Consequences

**Positive**:
- The cover matches the Turnitin cover for a PDF upload, with all five pairs and the full stats box.
- The ID is ours: it does not change on a re-send and it hides the Copyleaks scan ID.
- One config change re-labels every report, so a legal decision on the `trn` prefix costs one line to change.

**Negative / tradeoffs**:
- The ID uses Turnitin's own prefix `trn:oid:::`. The brief says to use our own name and still lists "how closely can we copy the Turnitin design" as an open legal question. The chosen default is the engineer's decision, and it must be reviewed before any report goes to a customer.
- Word uploads show no page count, because we cannot count Word pages without a layout engine.
- Characters counts UTF-16 units, so an emoji counts as 2. It may differ slightly from the number Turnitin would show.
- Random numbers can in theory collide. The retry and the unique index handle it, and a lost race fails the upload cleanly.
- Dates are in UTC only, so staff in another time zone see `GMT+0`.

**Neutral**:
- The `Download Date` is the build time. A copy of the PDF downloaded later keeps that date.
- The stand in logo and the callout wording are still open (spec 0006 AC-10), so no report may go to a customer yet.
- `/sync` should mark the cover and mapping spots in specs 0008 and 0009 as filled, and update the "No new .env values" line in `Reports/AGENTS.md` (two new optional settings).

## Rationale

The reasoning and the options are in [rationale.md](rationale.md).

## Follow-up

- [ ] Legal check on using `trn:oid:::` and the account part `3117` (the settings make a change one line).
- [ ] Count Word pages if staff need them on the cover (needs a decision, possibly converting Word to PDF first).
- [ ] `public_id` and `submission_number` both exist now. If staff ever search by the printed ID, add a lookup by number to the submissions list (feature 17).
