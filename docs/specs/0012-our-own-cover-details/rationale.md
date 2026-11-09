# 0012. Our own cover details: rationale

## Context

The cover page code (spec 0009) already draws Submission Date, Download Date, File Size, Pages and Characters, but only when the report data carries a value. `DetailsMapper` passes only the author, title, assignment, file name, words and the Copyleaks scan ID. So a real cover shows two field pairs and a one line stats box, and it looks unfinished next to the Turnitin sample.

The upload already saves the file size and the page count. The mapping already reads the Copyleaks text length. The one thing we do not have is an ID of our own: the cover and the header and footer show the Copyleaks scan ID, which is not ours and would change if a scan were sent again. The Turnitin ID has the shape `trn:oid:::3117:646033185`. The engineer supplied two real examples (`...3117:645894348` in the design doc and `...3117:646033185`), and in both the middle `3117` is the same while the last 9 digits differ, so the middle looks like a fixed account number and the end like a per submission number.

## Options considered

### Option 1: Fill the existing details from data we hold, plus one new column for our ID

Keep `CoverSection` as it is. Pass the missing values from the submission and the mapping into `DocumentDetails`. Add a `submission_number` column and build the printed ID from config plus that number.

**Pros**:
- No change to the cover drawing, which is already built and looked at by eye.
- Uses data that is already saved (size, pages, text length).
- The ID is ours and survives a re-send.
- The prefix and account part are settings, so a legal change is one line.

**Cons**:
- One new column and a backfill migration.
- Word files get no page count.

### Option 2: Make the ID from the public ULID or the database ID, with no new column

Format the existing `public_id` or the numeric ID into the Turnitin shape.

**Pros**:
- No migration.

**Cons**:
- A ULID is 26 letters and digits and does not look like the 9 digit number the engineer wants.
- The numeric database ID is guessable and shows how many uploads exist.

### Option 3: Count everything ourselves and save it on the submission

Extract text from every upload (PDF and Word), save words, pages and characters in the existing nullable columns at upload time.

**Pros**:
- Word files could get pages and characters.
- Counts would be saved, not recomputed.

**Cons**:
- Needs a text and layout engine for Word, which is a large job.
- The numbers could disagree with what Copyleaks scanned and highlighted.
- Mapping today never writes to the database. Saving counts breaks that rule.

## Rationale

Option 1 is the smallest change that makes the cover complete. Every value already exists or is cheap to make, and the cover has been built to wait for exactly this. The engineer chose the ID shape (Turnitin's exact form), UTC dates, the build time as the download date, and characters from the Copyleaks text, so the remaining calls were left to the spec.

The decisions made in the spec, each with its runner up:
- **Random number, not a counter** (the engineer's pick). It hides how many uploads exist. The runner up, a start number plus the database ID, needs no retry code but exposes volume.
- **Store only the number, build the string from config.** The runner up, storing the whole string, would need a data change to alter the prefix after a legal review.
- **Submission Date is the upload time** (`created_at`), not the time we sent it to Copyleaks. The staff member's action is the submission. The runner up, `scans.submitted_at`, changes on a re-send.
- **Download Date is the clock at mapping time.** It matches the "when the PDF is built" pick and needs no stored date. The runner up, `reports.built_at`, is written after the build, so the cover cannot read it.
- **Characters is the UTF-16 length the mapping already reads.** It costs nothing extra and uses the same unit as the highlight offsets. The runner up, counting code points, would differ only for emoji and needs another pass.
- **File size steps of 1024 with one decimal**, copied from the Turnitin sample (`113.9 KB`).
- **Word page count stays empty.** Counting Word pages honestly needs conversion to PDF. Better to leave it out than to show a guess, which is the rule the cover already follows.

## References

**Project sources**:
- `docs/turnitin-report-design.md` (the five field pairs, the stats box, date form `Oct 28, 2026, 4:12 PM GMT+5`, file size form `113.9 KB`, ID form `trn:oid:::3117:645894348`)
- Spec 0009 (the cover leaves out null values and waits for this feature) and spec 0008 (mapping table and the `DetailsMapper` note)
- Spec 0002 (the `submissions` table with `file_size`, `page_count` and `character_count`)
- `docs/brief.md` (use our own name; the open legal question on how closely to copy)
