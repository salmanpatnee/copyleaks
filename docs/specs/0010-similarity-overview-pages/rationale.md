# 0010. Similarity overview pages: rationale

## Context

Feature 12 builds the overview pages of the similarity report. A throwaway `SampleOverviewSection` already draws them from made up data, with every block of the design spec filled. The real report cannot do that. Spec 0008 lets only the overall score and the ranked source list through, and leaves out Match Groups, the Top Sources totals, the Filtered list and Integrity Flags on purpose, because Copyleaks gives no data for them. Spec 0008 left one question open for this feature: what does the page look like with those blocks missing, and does the summary repeat on the second page still exist?

The design spec only describes the full page. Pages must keep its sizes, fonts, positions and the rule that a source row never splits. The project rule is that a spot with no data is left out cleanly and never guessed, and that no scan runs just to test a layout. The overview also has to survive later features: 19 (match groups), 20 (source totals) and 22 (integrity flags) each add one block, and 24 joins all the sections. Today there is only one overview class, in the `Sample` folder, and no way to look at a real overview without a new scan.

## Options considered

### Option 1: Promote the sample into a real section with optional blocks

Move the section into its own folder. Match Groups, source totals, filters and the flag count become optional inputs. Missing ones draw a heading only or nothing. A factory and a `report:overview` command prove it on a saved scan.

**Pros**:
- Keeps the design page shape, so later features only pass data.
- The sample keeps its full look through the same class, so the full layout stays proven.
- Same pattern as the cover (spec 0009), so it is easy to follow.

**Cons**:
- Optional inputs have no real caller until Slice 3.
- Page 1 looks thin and unfinished with today's data.

### Option 2: A compact real section with only what we have

Write a new section that draws the score and the source list only, starting the list on page 1, with no empty areas. Keep the sample section as it is.

**Pros**:
- Looks finished today. Fewer lines of code.
- No gaps on the page.

**Cons**:
- Two different overview classes drift apart, and features 19, 20 and 22 must rewrite the layout later.
- It leaves the design spec's page shape, which the scope says to follow exactly.

### Option 3: Keep the sample as the real overview and fill it with zeros

Pass real data into the sample class, with 0 counts and 0% totals where there is no data.

**Pros**:
- Smallest change.

**Cons**:
- Draws numbers we do not have (`0`, `0%`). Breaks the no guessing rule and misleads a customer.

## Rationale

Option 1 is chosen. The design spec is the format to follow exactly and the layout positions are measured, so the page shape stays. Only the data changes, and the project rule says to leave out what Copyleaks does not give. Heading only blocks are the cheapest honest form of that: nothing is invented and the gap shows where later features will fill in. Option 2 looks better today but costs a rewrite in features 19, 20 and 22. Option 3 shows invented zeros.

The engineer chose to keep the full Turnitin page shape over the recommended compact page, and to keep the design positions on page 2 over moving the rows up. The accepted cost is a thin page 1 and a gap on page 2 until Slice 3, noted in Consequences and Follow-up.

Smaller calls made here: the real overview passes the four optional blocks as "no data" and the sample passes them all, so one class serves both. The flag count line is left out (the number is not proven) but the heading and info box stay, since the box is fixed wording. With no summary data page 2 shows the score line instead of a repeated block, because the engineer asked for a score line there. The zero source case is one page with a short message so a clean document never looks broken. All sources are listed with no cap, as in the design spec, because the engine was already proven at 100 sources. The factory composes `CoverReportFactory` so the submission ID rule lives in one place.
