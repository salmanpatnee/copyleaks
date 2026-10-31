# 0003. Upload screen for manuscripts

**Date**: 2026-10-31
**Status**: In Progress

## Summary

One plain page where a person picks a manuscript (PDF or Word `.docx`), types the author, title and assignment, and presses upload. The app checks the file, saves it on the private disk, saves one `submissions` row and shows a "saved" page. Nothing is sent to Copyleaks here, so uploading costs no credits. Word files are saved but cannot be scanned yet (spec 0001), so their page count is left empty.

## Requirements

**User stories**:
- As a staff member, I want to upload a manuscript with its author, title and assignment so that it is saved ready to be checked.
- As a staff member, I want a clear message when a file is not allowed so that I can fix it and try again.
- As a staff member, I want to be told when the same file was uploaded before so that I do not pay for the same scan twice by accident.

**Acceptance criteria**:
- **AC-1**: The form has a file field and three text fields: author, title and assignment. All four are required. The three text fields are at most 255 characters.
- **AC-2**: The file must have the extension `.pdf` or `.docx` and be at most 20 MB (`max:20480` KB, and the message says "20 MB"). Its bytes must match: a PDF starts with `%PDF-`, and a `.docx` is a zip holding `[Content_Types].xml` and `word/document.xml`. Anything else (including old `.doc` and a renamed text file) is rejected with a message that names the allowed types. The stored extension comes from the checked bytes. Nothing is saved on a rejection.
- **AC-3**: A PDF is rejected when it is encrypted, unreadable (including 0 pages or a parser error) or has more than 100 pages, each with its own clear message. Otherwise its page count is saved. All checks run before anything is written, and the PDF is parsed once.
- **AC-4**: A `.docx` is saved with an empty page count. The saved page shows the page count as "Unknown" and says Word files cannot be checked yet.
- **AC-5**: On success the file is stored on the private `local` disk at `submissions/{public_id}/manuscript.pdf` (or `.docx`), and one `submissions` row is saved with `original_name`, `file_size`, `file_sha256`, the cover details and `user_id` null. The person is redirected to the saved page for that `public_id`.
- **AC-6**: If the row cannot be saved after the file is written, the file is removed. If the file cannot be written, no row is saved. No orphan file or row is left behind.
- **AC-7**: A file whose checksum matches earlier submissions is still saved. The saved page lists up to 5 earlier submissions with the same checksum (newest first), each as a link.
- **AC-8**: The saved page shows the original file name, size, page count, author, title, assignment, upload time and the status "Not sent". It has no Submit button yet. An unknown `public_id` returns 404. The page never shows the numeric `id` or the file path.
- **AC-9**: Uploading never calls Copyleaks and never creates a `scans` row.
- **AC-10**: Errors show next to their field, including a file that is too big for PHP's limits (post size or upload size) and a "too many uploads" reply, so the person never sees a raw error popup. The text fields keep what the person typed after an error. The upload route is limited to 10 requests per minute per address. The browser blocks a file over 20 MB before sending it, and the button is disabled while an upload runs.
- **AC-11**: `/` redirects to the upload page. The page works with no login.

## Decision

**Chosen option**: Option 1: One form post, then a saved page.

A thin server side flow: a form request validates, an inspector reads the file facts, an action stores the file and the row, and a controller redirects to a saved page.

**Implementation skills**: `shadcn-vue` (`unovue/shadcn-vue`, `.agents/skills/shadcn-vue/`) for every component on both pages.

## Feature design

**Data model sketch**: no new tables. One change to `submissions` (spec 0002): `page_count` becomes `unsignedInteger` nullable (empty means unknown, used for Word). Edit the original migration `2026_10_31_000000_create_submission_tables.php` in place, since it was never deployed. The `Submission` doc comment changes to `int|null`. Add `Submission::isWord()`, based on the `file_path` extension (the file type is not stored, and a null page count must not mean Word). No other column changes.

**State transitions**: none. A new submission has no scan, so its derived status is `not_sent`.

**API surface**:
| Endpoint | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `/` | GET | none | redirect to `/submissions/create` | none (login off in Slice 0) | none |
| `/submissions/create` (`submissions.create`) | GET | none | Inertia page `submissions/Create` | none | none |
| `/submissions` (`submissions.store`) | POST (multipart) | `file` (req), `author` (req), `title` (req), `assignment` (req) | redirect to `submissions.show` | none, throttled 10 per minute | 422 field errors. A 413 (post too big) and a 429 (too many) become errors on `file` |
| `/submissions/{submission}` (`submissions.show`) | GET | `public_id` in the path | Inertia page `submissions/Show` with an explicit array (below) | none | 404 (also for a badly formed id) |

Routes are `Route::resource('submissions', ...)->only(['create','store','show'])`, so `create` is matched before `{submission}`. The Show page gets an explicit array, never the model: `public_id`, `original_name`, `file_size`, `page_count` (int or null), `author`, `title`, `assignment`, `created_at` (ISO 8601 UTC), `status` (value and label), `is_word`, and `duplicates` (each with `public_id`, `original_name`, `created_at`). `SubmissionStatus` must have a label method that returns "Not sent" (add it if missing).

**Value sourcing**:
| Action | Value produced / displayed | Source |
|---|---|---|
| Store | `public_id` | Generated first with `(new Submission)->newUniqueId()` (lowercase ULID), set on the row with `forceFill` (it is not fillable), and used in the folder name so both always match |
| Store | `file_path` | `submissions/{public_id}/manuscript.{pdf\|docx}`, extension from the verified type, never from user input |
| Store | `original_name` | The client file name, run through `basename()` and cut to 255 characters, saved as text only, never used as a path |
| Store | `file_size` | `getSize()` of the uploaded temp file (same bytes as the stored file) |
| Store | `file_sha256` | `hash_file('sha256')` of the uploaded temp file, computed before storing |
| Store | file type (PDF or Word) | Decided by the inspector from the bytes. Gives the stored extension. Passed from the form request to the action, so the PDF is parsed once |
| Store | `page_count` | PDF: `smalot/pdfparser` page count from the inspector. Word: null |
| Store | `author`, `title`, `assignment` | Form input |
| Store | `user_id` | null in Slice 0 (filled from feature 15) |
| Show | Status label "Not sent" | Derived by `Submission::status` from the latest scan (none) |
| Show | File size text | Formatted from `file_size` in the page |
| Show | Upload time | `created_at`, shown in the viewer's local time by the browser |
| Show | Earlier uploads of the same file | Query on `file_sha256`, excluding this row, newest first, limit 5 |

**Key invariants**:
- The stored file name never comes from user input.
- A rejected upload leaves no file and no row. If the file write returns false, the action throws. If the row save fails, the folder `submissions/{public_id}/` is deleted and the error is rethrown (a 500).
- No code in this feature calls Copyleaks or creates a scan.
- Manuscript text is never read into logs. Only counts and error codes may be logged.
- The browser never receives the numeric `id` or `file_path`.

**Security model**: Internal tool with login off in Slice 0, so the routes are open and throttled. Feature 15 puts the group behind login. Files live on the private disk, outside `public/`, and are not downloadable here. Manuscripts are personal data of authors (spec 0002), so do not log content. No separate compliance scope is named.

**Configuration required**:
- `upload_max_filesize` and `post_max_size` of 25M in `.user.ini` (spec 0001) and in the local Herd PHP settings. Without it a 20 MB file fails before Laravel sees it. Both a `PostTooLargeException` (turned into an error on `file` in `bootstrap/app.php`) and a `file.uploaded` failure (custom message "File is too large or did not finish uploading") must give a clear message.
- A named rate limiter `uploads` in `AppServiceProvider`: `Limit::perMinute(10)->by($request->ip())`. Set trusted proxies for Hostinger (or Cloudflare) so users do not all share one address.
- New Composer package: `smalot/pdfparser`. Add `ext-zip` to `composer.json` `require`.
- Memory: the PDF check raises `memory_limit` for this request only if needed (see Consequences).

**Test fixtures**: commit small files in `tests/Fixtures`: a 1 page PDF, a 101 page blank PDF, an encrypted PDF, a corrupt PDF, an old `.doc`, and a text file renamed `.pdf`. Build the `.docx` in the test with `ZipArchive`. Test "over 20 MB" with `UploadedFile::fake()->create('a.pdf', 20481)` (the size rule uses `bail`, so the inspector never runs). Test a failed row save with `Storage::fake('local')` plus a `Submission::creating` listener that throws.

**Critical test scenarios** (Pest, using the fixtures above):
- Happy path: a valid PDF with three details is stored, a row is saved with page count, checksum and size, and the person lands on the saved page, verifies **AC-1**, **AC-5**, **AC-8**, **AC-11**
- Happy path: a valid `.docx` is saved with null page count and the saved page shows "Unknown", verifies **AC-4**
- Failure case: a wrong type, an old `.doc`, a renamed text file, a file over 20 MB, an encrypted PDF, a corrupt PDF and a PDF over 100 pages are each rejected with their own message and nothing is saved, verifies **AC-2**, **AC-3**
- Failure case: missing or too long author, title or assignment shows field errors and keeps the typed values, verifies **AC-1**, **AC-10**
- Failure case: the row save throws after the file was written, and the file is removed, verifies **AC-6**
- Integrity: the same file uploaded twice makes two rows, and the second saved page links to the first, verifies **AC-7**
- Integrity: after an upload there are zero `scans` rows, verifies **AC-9**
- Permission: an unknown `public_id` returns 404, the 11th upload in a minute returns 429, verifies **AC-8**, **AC-10**

## Build plan

Approach: Tracer Bullet. Build the thinnest real line first (PDF in, row and file saved, saved page shown), then thicken with Word, duplicates and all the rejections.

1. Add `smalot/pdfparser` and `ext-zip`. Edit the original migration so `submissions.page_count` is nullable. Update the `Submission` doc comment, add `isWord()`, and check `SubmissionStatus` has a label for "Not sent", satisfies **AC-4**, **AC-8**
2. Thin thread: `Route::resource` limited to `create`, `store`, `show`, `SubmissionController`, a `StoreSubmission` action that writes the file under `submissions/{public_id}/` and saves the row, with a bare form and bare saved page for a PDF, satisfies **AC-1**, **AC-5**, **AC-8**
3. Add a `ManuscriptInspector` interface and two small classes (PDF reader, docx reader). The PDF reader checks `%PDF-`, treats 0 pages or any parser error other than "Secured" as unreadable, and treats "Secured" as encrypted. The docx reader reads the zip file list only. Both throw a domain exception from `app/Exceptions` and return the type and page count, satisfies **AC-2**, **AC-3**, **AC-4**
4. Add `StoreSubmissionRequest` (rules `bail|required|file|extensions:pdf,docx|max:20480`, 255 limits, plain messages). Run the inspector in `after()` and keep its result on the request so the action reuses it. Map inspector exceptions to errors on `file`, satisfies **AC-1**, **AC-2**, **AC-3**, **AC-10**
5. Make the store step safe: generate the ULID first, hash and size from the temp file, cut `original_name`, write the file (throw if it returns false), save the row in a try block, and delete the folder and rethrow if the row fails, satisfies **AC-5**, **AC-6**
6. Duplicate lookup on `file_sha256` passed to the saved page, with the explicit Show array, satisfies **AC-7**, **AC-8**
7. Build the real pages with shadcn-vue: a small `GuestLayout` (app name on top, one centered card), `submissions/Create.vue` (Inertia form with Input, Label, Button, progress, a 20 MB check before sending, a disabled button while processing, and `InputError`) and `submissions/Show.vue`. Generate Wayfinder routes, satisfies **AC-1**, **AC-8**, **AC-10**
8. Add the `uploads` limiter and trusted proxies. Turn `PostTooLargeException`, `file.uploaded` failures and 429 into errors on `file`. Make `/` redirect to the upload page and update the starter `ExampleTest` that expects `/` to return 200, satisfies **AC-10**, **AC-11**
9. Set the PHP upload limits in `.user.ini`, and note the matching local Herd PHP setting for the engineer to apply, satisfies **AC-2**, **AC-10**
10. Add the test fixtures and write the Pest tests above, satisfies **AC-1** to **AC-11**

## Consequences

**Positive**:
- A real, saved submission exists from day one, so features 5, 6 and 17 build on it.
- Uploading is free and safe to repeat, and no scan can start from this page.
- Rejections happen before anything is saved, so storage stays clean.

**Negative / tradeoffs**:
- Word files can be saved but not scanned until a converter exists. Staff may upload Word files that go nowhere for now. The saved page says so clearly.
- Page count becomes nullable, so every later feature (credit estimate, reports) must handle "unknown".
- Parsing a 20 MB PDF in PHP can use a lot of memory on shared hosting. Running out of memory is a fatal error that cannot be caught, so a bad file could give a blank server error instead of our message. Mitigation: do a cheap page count first (a pattern match on `/Type\s*\/Page[^s]`), reject over 100 pages without a full parse, and fully parse only after that.
- The open routes have only a throttle for protection until login arrives.
- Feature 5 is now mostly done by this feature. It shrinks to error message polish.

**Neutral**:
- The starter Welcome page is replaced by a redirect while login is off.
- The sidebar layout is not used yet. The upload pages move into it when feature 15 arrives.

## Follow-up

- [ ] Spec 0002: this feature edits its original migration so `submissions.page_count` is nullable (empty means unknown, used for Word). Update its data model table and AC-1 (page count required for PDF only). If that migration was already deployed anywhere, use a new alter migration instead.
- [ ] Spec 0001 and `docs/scope.md`: the scope says Word or PDF and spec 0001 says PDF only. This spec accepts `.docx` for storage only. Reword both next time they are touched.
- [ ] Scope feature 5 (Cover details form): this feature already collects the three fields. Reduce feature 5 to message polish or merge it.
- [ ] Measure memory use of `smalot/pdfparser` on a 100 page, 20 MB PDF, locally and on Hostinger (part of the existing hosting follow-up). If the cheap pre count is not enough, raise `memory_limit` for this route.
- [ ] Feature 6 must reuse the checksum lookup to stop a second paid scan, and must refuse to submit a Word file.
- [ ] Feature 15 (login) moves these routes behind `auth` and moves the pages into the sidebar layout.
- [ ] `shadcn-vue` conventions are not yet in root `AGENTS.md` `## Rules`. They apply to every screen and belong at root level.
