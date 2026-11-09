# 0011. Highlights and margin badges: rationale

## Context

Slice 0 must end in a real similarity PDF made only from what Copyleaks returns. The pieces exist but are not joined. Spec 0007 answers "which rectangles hold characters 120 to 340?". Spec 0008 gives merged match ranges with the rank of their best source. Spec 0006 draws `Highlight` and `MarginBadge` shapes on imported pages and text pages in a fixed order. Nothing turns ranges into shapes, and no code builds a full report from cover, overview and manuscript pages.

The design file gives the shapes and one placement rule: the badge top lines up with the highlight top, and a second match starting on the same line puts its badge at x 44. It says nothing about matches on neighbouring lines. Text lines are about 12 to 14 pt apart and a badge is 15.5 pt tall, so such badges would overlap. The "done when" for this feature says badges must not overlap, so a rule is needed.

Credits are limited and sandbox scans return mock data that cannot test highlights. The real look can only be proven with one real scan.

## Options considered

### Option 1: Small placer, badge slot rule, factory, builder and a new command

One placer turns ranges into shapes, one pure slot rule keeps badges apart, a factory adds the manuscript section to the overview document, and `report:similarity` builds it from a saved scan.

**Pros**:
- Each class has one reason to change and none touches position logic.
- The command gives a free way to rebuild and look at a real result.
- The builder is what feature 24 will call later.

**Cons**:
- Five new classes and one command for what is mostly wiring.

### Option 2: Put the placement inside the manuscript section classes

Let `ImportedPdfSection` and `TextPagesSection` take ranges and place shapes themselves.

**Pros**:
- Fewer classes, no separate plan object.

**Cons**:
- Mixes drawing with match rules in two places, and spec 0006 and 0007 fixed those as separate jobs.
- The placement counts (unplaced, dropped) would be hard to report.

### Option 3: Extend `report:overview` with manuscript pages

Same logic as Option 1 but no new command name.

**Pros**:
- One command less.

**Cons**:
- The name stops matching what it does, and the cover plus overview only command is still useful on its own.

## Rationale

Option 1 fits the project rules (small classes, one reason to change, dependencies through constructors) and the existing pattern: each earlier feature added a pure factory and a `report:*` command that never calls Copyleaks. Keeping `lineBoxes()` as the only source of positions is the whole point of spec 0007, so the placer only maps boxes to shapes.

For badge collisions the chosen rule moves badges only sideways. A badge that moves up or down can end up beside the wrong line, which breaks the report's main promise that a badge marks the start of its match. Sliding right keeps it level and keeps every source number visible, at the cost of sitting over text in slots 2 and 3 (runner up: push down, which never covers text but loses the link to the line; also considered: drop the lower badge, which hides a source number). Four slots, 36.5 pt apart, copy the design's own spacing (7.5 then 44) and stop before the badge could reach far into the page. After four busy slots the badge is dropped and counted (runner up: keep going, which would push badges deep into the text).

Large ranks keep their full number with a smaller font (runner up: widen the pill, which changes the measured shape and the slot spacing). Unplaced ranges are skipped and counted instead of stopping the build, because one odd range should not block a whole report and the count makes the gap visible (runner up: stop with a reason code). Nothing is saved to the database here, because feature 24 owns the report row and the download, and saving one now would create a real report before there is a screen for it (runner up: save through `ReportReplacer` now, as spec 0007 suggests).

The proof uses one real small scan, as chosen. It costs one credit, but it tests the real Copyleaks offsets and text, which a made up fixture would not.

## Recommended calls made while writing

- Slots: `x = 7.5 + 36.5 × k`, four of them (runner up: two slots as in the design file).
- Free slot test: tops at least 16.5 pt apart (15.5 pt badge plus 1 pt gap).
- Number sizes: 7.75 pt for one or two digits, 6.0 pt for three, 4.5 pt for four or more.
- Placement order: per page by `y`, then range start, so the result does not depend on how ranges arrived.
- Command output prints numbers and the mode only, never names or text (project secrets rule).
- No test files in this build, unless you ask.
