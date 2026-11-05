# 0009. Similarity cover page: decision record

The build spec is [index.md](index.md). This file holds the reasoning and is not read during a build.

## Context

The design spec (`docs/turnitin-report-design.md`, page 1) fixes the cover: author, title and assignment in the lower half of a Letter page, a divider, `Document Details` with up to five field pairs, and a grey stats box with Pages, Words and Characters. The space above y 336 stays empty on purpose.

The engine (spec 0006) already draws these parts, and a throwaway `SampleCoverSection` already lays them out from made up data. The mapping (spec 0008) already builds a typed `DocumentDetails` from a saved scan: author, title, assignment, file name, Submission ID (the Copyleaks scan ID) and the word count. Dates, file size, pages and characters are null until feature 21, and the scope rule says a spot with no data is left out, never blank and never guessed.

That leaves a few real questions: how the cover behaves when most of its details are missing (the stats box and the field list were designed for a full set), what happens when a name is too long for its line (the design shows only short samples), where the production cover code lives (the sample folder is throwaway), and how a person can check a real cover without spending a scan. A cover that breaks on long text or looks wrong with half its data would be the first page of every report.

Two outside limits apply. The logo, icons and callout wording are stand ins taken from the Turnitin samples, and the legal check on copying the layout is open (spec 0006), so nothing built here can go to a customer yet. No saved scan exists on this machine, so a real look is not possible until one is saved.

## Options considered

### Option 1: Promote the sample cover into a real `CoverSection`, with a factory and a `report:cover` command

Create `app/Services/Reports/Cover/` with a real section, a small text fitting helper and a factory from `ReportData`. Add a command that builds a cover only PDF from a saved scan. Swap the sample factory to the real section and delete the throwaway one.

**Pros**:
- One cover class for features 12, 21 and 24 to reuse, in a folder named for what it is.
- The command lets `/check verify` look at a real cover with no new scan.
- The fitting rules sit in one small class and are easy to read and check.

**Cons**:
- A new command and a small refactor of the sample prune code add surface.
- The cover only is a thin PDF, so the command does not prove the cover inside a full report.

### Option 2: Rename the sample section in place and feed it real data

Keep `SampleCoverSection` where it is, drop "Sample" from the name, and pass it `ReportData::details`.

**Pros**:
- The smallest change, fewest files.
- No new folder.

**Cons**:
- Production layout stays inside a folder whose own comment calls it throwaway.
- No place to put the fitting rules without growing a class that already draws everything.
- Still needs some way to look at a real cover, so the command comes later anyway.

### Option 3: Build the cover as part of a full report builder now

Create the document assembly (cover, overview, manuscript) and draw the cover as its first part.

**Pros**:
- Shows the cover in context, with the real header and a real page count.

**Cons**:
- Overlaps features 12, 13 and 24, which own the overview, the highlights and the final assembly.
- Big for a feature whose done rule is only that the cover matches the layout.
- Hides the cover problems behind unfinished pages.

## Rationale

Option 1 fits the forces in Context. The work that is really new is small and sharp: what to do with missing data and long text. That belongs in a class of its own, not in a sample. Option 2 saves a few files but leaves production layout in a throwaway folder and gives the fitting rules no home. Option 3 is the "build everything at once" path that the Tracer Bullet approach avoids, and three later features already own those parts.

The engineer chose the command over a section only, so the cover can be checked against a real saved scan with no extra scan, which matters because credits are limited and a result is rebuilt from saved files (scope, Slice 0). The command reads through `ReportDataMapper`, the single entry point of spec 0008, even though the cover needs only the details. The extra work is small for a developer command, and a second way to read saved files would break the rule that features 11, 12 and 13 never open a Copyleaks file themselves.

Four choices were made with the engineer:
- **Stats box with only Words**: show the box and shrink it to fit (height 34 plus 22 per extra line, which reproduces the design's 78 pt for three lines). Runner up: hide the box until all three counts are known. The box was kept because the Words line is real data and the box is part of the cover's look.
- **Long text**: wrap the title onto 2 lines and cut author and assignment with `…`. Titles are often long file names, so wrapping loses the least, while an author or class name is short in practice. Runner up: cut everything to one line, which keeps every position fixed but loses words from long titles.
- **File Size**: wait for feature 21, as the mapping table and the scope say, so this feature stays within form and Copyleaks data. The value is already saved on the submission, so feature 21 is cheap.
- **Empty author or title**: leave only that line out and keep every other spot where it is. This follows the scope rule (no blanks, no guesses). Failing the whole build or borrowing the file name as a title were rejected: the first stops a report that could still be built, the second invents a value.

Decided without a question, with the runner up:
- **Shift on a wrapped title**: everything below the title moves down by one title line, 20 pt (the title's line height in `ReportTheme`). Worst case ends near y 700, well above the footer at 748, so one extra line never needs a second page. Runner up: keep positions fixed and shorten the gap; rejected because the 8 pt gap under the title is too small to absorb a 20 pt line.
- **Measuring**: `SectionCanvas::textWidth` already takes a font family, so the fitter measures Lexend Deca and Noto Sans in the same font that draws them. Runner up: let mPDF wrap by CSS and clip. Rejected because the layout must know how many lines the title used in order to shift what follows.
- **File name and Submission ID**: cut at 340 pt with `…` so a long value cannot run under the stats box (x 403) or into the next pair, which starts 36 pt lower.
- **Shared pruner**: one small `SampleFilePruner` used by both commands, instead of copying the prune code, with the pattern as its only input. Runner up: a second copy in the new command, which breaks the one reason to change rule.
- **Output folder**: `reports/samples/` with `cover-{Ymd-His}.pdf`, kept at the newest 5, so the files sit where `report:sample` already puts its own.
- **Renderer version**: bump `MpdfReportRenderer::VERSION` because the look of the cover changes (spec 0006 rule).
- **Cross check changes** (a second model read the draft, you chose to apply all its fixes): a null Submission ID stops the build with `submission_id_missing` (runner up: let the strip skip the label, which needs an engine change); the assignment is 8 pt as the design says, with its own class; the stats box takes a top input so the title shift reaches it; each fitted line is its own `nowrap` div so mPDF cannot re-wrap it; the fitter takes a width function per font because `TextMeasurer` has no font argument; a 0 count is left out; whitespace is collapsed before measuring; and a 98% width margin guards fallback fonts. Right to left names stay a known limit.
- **Tests**: none are planned in the build. The project memory says to write tests only when asked, and the scope tier is Beta, where `/test` is a separate step after `/check verify`.

## References

None. The engineer chose a spec without references. The decision rests on `AGENTS.md`, `docs/turnitin-report-design.md`, `docs/scope.md` and specs 0004, 0006 and 0008, all in this repo.
