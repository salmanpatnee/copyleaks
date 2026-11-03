# 0008. Copyleaks to report mapping: rationale

> ⚠️ Premise note: this feature maps only what Copyleaks gives, but the Turnitin layout was drawn for data we do not have (four match groups, three source totals, flags). If every such block is left out, the overview page has holes the design doc never describes. That is a layout question for feature 12, not a mapping question, so it is listed in the Follow-up of `index.md`. Features 19, 20 and 22 have no specs yet. They are treated as later changes to this table, and every spot they will fill is marked "left out" with its feature number.

## Context

Slice 0 must produce a real similarity PDF from one scan, using only what Copyleaks returns. Spec 0005 already saves the summary, one result file per source and the crawled text. Spec 0006 draws pages from typed parts (`SourceRow`, `DocumentDetails`, `Highlight`, `MarginBadge`). Spec 0007 answers "which page and box holds characters 120 to 340?". Nothing yet connects the saved files to those parts.

Copyleaks does not give the four Turnitin match groups (it has identical, minor changes and related meaning), the three source totals, institution names or dates, or a confirmed flag list. The scope says those are Slice 3 work and must not be guessed in Slice 0. Three later features (cover, overview, highlights) all need the same score, source list and ranges. If each reads the files on its own, the unzip, checksum and field name code is repeated three times and the three can disagree on rank or percent.

The saved result files are large (cap 10 MB each, spec 0005) and a long document can have dozens of sources. The target host (Hostinger) has no always running worker and a limited memory budget. A source number on a page margin must have a matching row in the list, so the ranking has to be one decision made in one place.

## Options considered

### Option 1: A shared `ScanBundleReader` (kept in the Mapping folder) and one `ReportDataMapper` returning typed `ReportData`

One reader opens and checks saved files. One mapper builds a single readonly object with the score, details, ranked source rows and merged highlight ranges. Features 11, 12 and 13 only read that object.

**Pros**:
- One place for rank, percent and merge rules, so list and badges always agree.
- File reading and checks written once (closes the 0005 follow-up).
- Pure read step, easy to test on small fixtures and to run twice with equal output.

**Cons**:
- Decodes every result file on each rebuild, with no cache.
- One object holds data for three features, so it grows when Slice 3 adds fields.

### Option 2: A separate mapper per report feature

Cover, overview and highlight features each read the files they need and map them.

**Pros**:
- Each feature is self contained and can be built alone.

**Cons**:
- The same unzip and checksum code three times, which spec 0005 warned about.
- Rank and percent can drift between the list and the badges.
- Each mapper must open the large result files separately.

### Option 3: One mapper that also reads files

A single `ReportDataMapper` that opens the artifacts itself, with no separate reader.

**Pros**:
- Fewer classes.

**Cons**:
- Reading and checking files and mapping fields are two reasons to change in one class, against the project's SOLID rule.
- The failure paths (checksum, gzip, JSON) are harder to test apart from the mapping rules.

## Rationale

Option 1 fits the forces above. The badge to row match is the strongest one: the rank must come from a single sort, so one mapper has to own both the source list and the ranges. Reading the large result files once, one at a time, keeps memory predictable on Hostinger, and a separate reader keeps file failures (a real risk with stored artifacts) apart from the field rules, which are the part most likely to change when a real result file shows different names. Option 3 was close on size but breaks the one reason to change rule. Option 2 was ruled out by the repeated code and the drift risk.

Smaller calls made while writing (the engineer's answers fixed the rest):
- **Overlap merge only on real overlap, not on touching ranges** (runner up: merge touching ranges too). Two adjacent matches from different sources are different evidence and each deserves its badge.
- **Badge rank is the lowest rank covering the first character of the merged range** (runner up: the highest matched words total in the region). The first character is where the badge sits, and the lowest rank is a plain, repeatable rule.
- **`matchedWords` only, no fallback sum** (runner up: fall back to the sum of the type counts). The cross check pointed out that the type count names belong to the result file, and that a silent 0 would drop a real source. A wrong name now stops with `summary_unreadable`, and build step 7 confirms the name on a real summary.
- **Source with matched words but no ranges stays in the list** (runner up: drop it). Dropping it would change the list to fit an unproven result file shape. The command warns instead.
- **Stop with a reason code for any damaged file** (the engineer chose this over skipping bad files). It matches the report engine and the manuscript builder.
- **New named factories on `ReportRenderFailed`, not new exception classes** (runner up: one exception per case). The class already carries a short code with a factory per reason, and callers only need the code.
- **No `text` file read here** (runner up: check it exists). The manuscript builder already stops with `crawled_text_missing`. Checking twice would give two places to change.
- **Cover words come from `scannedDocument.totalWords`** as the engineer chose. The submission also stores a word count from the upload. They may differ, and feature 21 decides which one wins.

## References

**Project sources** (verifiable, in this repo):
- `docs/turnitin-report-design.md`: every layout spot and measurement in the mapping table.
- `docs/scope.md` (features 10 to 13, 19 to 22): which feature owns each left out spot.
- Spec 0005: the saved `summary`, `result` and `text` files, checksums, and the open `ScanBundle` reader question.
- Spec 0006 and `app/Services/Reports/AGENTS.md`: `SourceRow`, `DocumentDetails`, `MatchGroupStyle`, `ReportRenderFailed`.
- Spec 0007: `ManuscriptPages::lineBoxes()` and the character offset unit.
- Spec 0004 `rationale.md`: the documented shape of the `completed` summary.

**Practices & standards**:
- Never guess data a source does not give: leave the spot out.
- Deterministic mapping from stored raw data, so a report rebuilds without a new scan.
