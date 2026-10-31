# Rationale for 0002. Data model for submissions, scans, raw results and reports

The decision record for [spec 0002](index.md): why it looks the way it does.

## Context

The tool must save the uploaded file with its cover details, run a scan at Copyleaks, keep every Copyleaks reply exactly as received, and build PDF reports from those saved replies. Spec 0001 already fixes several rules this model must carry: raw results live as compressed files on the private disk with a path and SHA 256 checksum; our own code creates the scan ID; a retry makes a new scan ID; a scan row is saved as `submitting` before the Copyleaks call; webhook events are unique on (scan, status, kind); and uploads are checked by file checksum.

Login is off in Slice 0 and arrives in Slice 1. The cover page needs a submission ID, dates and counts in Slice 3. If the model ignores these, each slice forces a breaking migration on a table that already holds paid scan data. Copyleaks deletes scan data after its expiry, so our copy must be complete and trustworthy.

## Options considered

**Option 1: five normalized tables (chosen).** Submission, scan, event, artifact, report, each with its own rules.
- Pros: retry history; webhook idempotency in the database; raw files tracked one by one with checksums; reports versioned by renderer.
- Cons: more tables and one join for the latest scan.

**Option 2: one wide submissions table with JSON columns for raw replies.** Raw bundle, scores and report paths all on the submission row.
- Pros: fewest tables, simple first migration.
- Cons: a retry overwrites the first try; large JSON blobs bloat the row and slow every list; no place for webhook idempotency; spec 0001 already chose files on disk for the bundle.

**Option 3: submissions plus one scans table, with files tracked as paths on the scan.** Merge events, artifacts and reports into scan columns.
- Pros: three tables fewer.
- Cons: a scan has many result files, so a JSON list of paths loses per file checksums; webhook repeats have nowhere to be recorded; rebuilt reports overwrite paths with no record.

## Rationale

Spec 0001 already demands a scan row per attempt, a unique key on webhook events, and a path with checksum per bundle file. Those are separate records by nature, so Option 1 is the shape of the rules, not extra design. The tool pays per scan, so losing the first scan's ID or reason, or a repeated webhook flipping a status, costs real money and trust. The cost is a few extra tables for a small team, which Laravel models handle easily.

Copying the two headline scores onto the scan row breaks the "do not store derived values" habit on purpose. The list page would otherwise open compressed files for every row, which shared hosting handles badly. The bundle stays the source of truth.

Hard delete was chosen over soft delete because soft deleted rows and files would linger past the retention promise and complicate the unique rules.
