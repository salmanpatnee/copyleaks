# Manuscript upload (file checks, safe store, size limits)

## Overview

The upload screen takes a PDF or Word (.docx) file with author, title and assignment, checks the file bytes, saves the file on the private disk and saves one `submissions` row. Nothing is sent to Copyleaks from here. Decided in spec 0003 (`docs/specs/0003-upload-screen/index.md`). Read it before changing limits or messages.

## Key files

| File                                                     | Owns                                                                                                                             |
| -------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `app/Services/Manuscripts/ManuscriptInspector.php`       | Interface: `accepts()` reads the first bytes, `inspect()` returns type and page count                                            |
| `app/Services/Manuscripts/PdfInspector.php`              | Cheap page count first, then one `smalot/pdfparser` parse. Max 100 pages                                                         |
| `app/Services/Manuscripts/DocxInspector.php`             | Reads the zip file list only, never unpacks                                                                                      |
| `app/Services/Manuscripts/ManuscriptInspectorChain.php`  | Gives the file to the first inspector that accepts it. Bound in `AppServiceProvider`                                             |
| `app/Http/Requests/StoreSubmissionRequest.php`           | Rules, messages, and the inspection inside `after()`. Keeps the result for the action                                            |
| `app/Services/Manuscripts/SubmissionNumberGenerator.php` | Random free 9 digit `submission_number`, 5 tries, then `SubmissionNumberUnavailable`                                             |
| `app/Actions/StoreSubmission.php`                        | ULID and number first, hash and size from the temp file, write file, save row (number set with `forceFill`), clean up on failure |
| `app/Http/Controllers/SubmissionController.php`          | `create`, `store`, `show`. Show sends an explicit array, never the model                                                         |
| `bootstrap/app.php`                                      | Oversized post and rate limit errors turned into errors on the `file` field                                                      |
| `resources/js/pages/submissions/`                        | `Create.vue` and `Show.vue`, shown inside `layouts/GuestLayout.vue` (picked in `app.ts`)                                         |
| `tests/Fixtures/`                                        | Small PDFs and files for the rejection tests                                                                                     |

## Conventions

- File type comes from the bytes, never from the name. The stored name is always `manuscript.pdf` or `manuscript.docx`. The original name is kept as text only.
- Every rejection is a `ManuscriptRejected` with a message that is safe to show. The form request turns it into an error on `file`. Nothing is written when a file is rejected.
- The PDF is parsed once. The inspector runs in the form request and the action reuses its result.
- A null `page_count` means unknown (Word files). Every later feature must handle it. `Submission::isWord()` checks the stored extension.
- Limits: 20 MB, 100 pages, no encrypted PDFs, 10 uploads a minute per address (limiter `uploads`).
- Never log manuscript text or file contents. Counts and error codes only.
- Tests use a fake `ScanClient` and never a real scan. A test fails if an upload creates a `scans` row.

## Gotchas

- Use `$exceptions->respond()` in `bootstrap/app.php` for the oversized post error. A `render()` callback for it never sends its response behind Herd's PHP-FPM, so the page hangs. The notice travels in the URL (`?notice=too-large`) because the session has not started yet at that point.
- PHP limits must be 25M for `upload_max_filesize` and `post_max_size`: `public/.user.ini` on Hostinger, and Herd's PHP settings locally. Herd's web PHP version can differ from the command line one.
- The Inertia page tests read `public/build`. Run `npm run build` after adding or renaming a page, or they fail with "Unable to locate file in Vite manifest".
- The `Form` component only shows errors from its own submit. Errors flashed to a plain page load are not displayed.
- Pest already has a `fixture()` helper. Name test helpers differently.
- Behind a proxy such as Cloudflare, set `TRUSTED_PROXIES` or every visitor shares one address for the throttle.

## Agent skills

- [shadcn-vue](../../../.agents/skills/shadcn-vue/): `unovue/shadcn-vue`, governs the components on both pages (Field, Card, Progress, Alert)

## Related specs

- [0003 upload screen](../../../docs/specs/0003-upload-screen/index.md)

_Drafted by /audit from the repo, worth a quick human pass. Edit freely: once a line stops matching this draft, later runs treat it as curated and will flag rather than overwrite it._
