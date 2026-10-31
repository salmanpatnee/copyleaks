# 0003. Upload screen for manuscripts: rationale

Decision history for [spec 0003](index.md). Not needed to build.

## Context

Feature 4 is the first step of the Slice 0 thread. Spec 0002 already defines the `submissions` table and says upload saves only the submission, so a `not_sent` status is a real state until feature 6 adds the Submit button. Spec 0001 sets the limits: 20 MB, 100 pages, no encrypted PDFs.

Three gaps between the scope and the earlier specs shaped this design. The table requires author, title and assignment, while the scope puts them in feature 5. The scope says Word or PDF, while spec 0001 says PDF only. The table requires a page count, which PHP cannot read from Word. Login is off in Slice 0, so the starter kit sidebar (which needs a signed in user) cannot be used. No PDF reading library is installed yet.

## Options considered

### Option 1: One form post, then a saved page

The browser posts the file and the three fields together. The server validates, stores, saves and redirects to a saved page.

**Pros**:
- Fewest moving parts. Matches the Inertia form pattern already in the starter kit.
- The saved page is where feature 6 later adds the Submit button.

**Cons**:
- A rejected large file has already been sent over the network before the server can say no.
- Limited by PHP's `upload_max_filesize` and `post_max_size`.

### Option 2: Two steps (file first, details second)

Upload the file to a temporary place, then show a second form for the details.

**Pros**:
- Fast feedback on the file before the person types anything.

**Cons**:
- Needs temporary file cleanup and a half finished state. That is more than this slice needs.

### Option 3: Direct or chunked upload to storage

The browser uploads in pieces or straight to cloud storage.

**Pros**:
- Handles very large files.

**Cons**:
- Shared hosting has no cloud storage here, files are capped at 20 MB, and it adds a lot of code for no current need.

## Rationale

The files are small (20 MB at most), the team is small, and the host has no extra services. One post fits all three. The two step form and direct uploads solve problems we do not have.

Decisions I made for you, with the runner up:
- **PDF reader: `smalot/pdfparser`.** It reads modern compressed PDFs, gives the page count, and throws "Secured pdf file are currently not supported" for encrypted files. Runner up: the free FPDI parser from mPDF, which may fail on newer PDFs and would wrongly reject good files. mPDF can still arrive later for report building.
- **Word check:** read the zip's file list (never unpack it) and require `[Content_Types].xml` and `word/document.xml`. Runner up: Laravel's `mimes:docx` rule, which can reject real Word files that the system reports as a plain zip.
- **Inspection runs inside the form request** (`after()`), before any write. Runner up: inspect inside the action, which would show rejections as a server error, not a field error.
- **No database transaction** around the store step. It is one insert, so a try/catch that deletes the folder on failure is enough.
- **`page_count` nullable by editing the original migration** (spec 0002 shipped today and was never deployed). Runner up: a new alter migration, which needs a risky rollback on rows with nulls.
- **File name on disk is fixed** (`manuscript.pdf` or `manuscript.docx`), never from the upload. The original name is kept as text only.
- **Page count becomes nullable** through a small new migration. Runner up: store 0 as "unknown", which every later feature would have to remember.
- **Duplicates are checked after save** and shown on the saved page. Upload is free, and feature 6 is the place that blocks a second paid scan.
- **Throttle** the upload route even without login, since it is open to anyone who can reach the site.
