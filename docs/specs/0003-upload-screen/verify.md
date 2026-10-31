# Verify: upload screen · spec 0003 · updated 2026-10-31
_Steps derived from spec 0003 acceptance criteria. `/check verify` runs these; `/test` locks the durable ones._

Before you start: run `php artisan migrate:fresh` on your local MySQL (the original migration was edited so `page_count` can be empty), set `upload_max_filesize` and `post_max_size` to 25M in your Herd PHP settings, and run `npm run build`.

## UI / manual
- [x] Open `/` → you land on `/submissions/create`, no login asked → AC-11
- [x] Pick a small PDF, fill author, title and assignment, press Upload → you land on the saved page showing file name, size, page count, the three details, upload time and "Not sent", with no Submit button → AC-1, AC-5, AC-8
- [x] Upload a `.docx` → saved page shows pages as "Unknown" and a note that Word files cannot be checked yet → AC-4
- [x] Choose a file over 20 MB → the error shows under the file field before sending, and the Upload button is disabled → AC-10
- [x] Choose a `.txt`, an old `.doc` and a text file renamed `.pdf` → each is refused with a message naming the allowed types, and the typed text fields keep their values → AC-2, AC-10
- [x] Upload the encrypted, corrupt and 101 page fixtures from `tests/Fixtures` → each shows its own clear message → AC-3
- [x] Leave author empty, or type 256 characters in title → errors show next to those fields → AC-1
- [x] Upload the same PDF twice → the second saved page lists the first as a link → AC-7
- [x] Visit `/submissions/not-a-real-id` → 404 page → AC-8
- [x] Watch the Upload button during a larger upload → it is disabled and a progress bar shows → AC-10

## Commands
- [x] `php artisan test --filter=UploadScreenTest` → all pass → AC-1 to AC-11
- [x] `php artisan tinker --execute='echo App\Models\Scan::count();'` after uploads → `0` (no scan was created) → AC-9
- [x] List `storage/app/private/submissions` → one folder per saved row, each holding only `manuscript.pdf` or `manuscript.docx` → AC-5, AC-6
- [x] Post 11 uploads within a minute → the 11th shows "Too many uploads" on the file field → AC-10

## Value sourcing checks
- [x] `public_id` in the folder name equals the row's `public_id` → Store `public_id`
- [ ] Rename a PDF to `x.docx` and upload → rejected (type comes from the bytes, not the name) → Store `file_path`
- [x] Upload a file named `../../evil.pdf` → saved as `manuscript.pdf`, `original_name` shows only `evil.pdf` → Store `original_name`
- [x] `file_size` and `file_sha256` on the row match the original file on disk → Store `file_size`, `file_sha256`
- [x] Docx row has `page_count` null, PDF row has a number → Store `page_count`
- [ ] Upload at a different local time zone (change the computer clock zone) → the saved page upload time follows the viewer's zone → Show upload time

## Acceptance-criteria coverage
- AC-1 … steps 2, 7 · AC-2 … step 5 · AC-3 … step 6 · AC-4 … step 3 · AC-5 … step 2 and file listing · AC-6 … file listing and tests · AC-7 … step 8 · AC-8 … steps 2, 9 · AC-9 … tinker step · AC-10 … steps 4, 5, 10 and rate limit · AC-11 … step 1
