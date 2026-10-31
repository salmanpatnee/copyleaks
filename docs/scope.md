# Scope: Manuscript Report Tool

An internal tool for our staff. A staff member uploads a manuscript, Copyleaks checks it for copied text and AI written text, and the tool turns the saved results into PDF reports that follow the Turnitin style layout (our own name and logo). The full brief is in [brief.md](brief.md) and the report layout is in [turnitin-report-design.md](turnitin-report-design.md).

**Build approach:** Tracer Bullet (build one thin, real thread through every layer first, then thicken it one strand at a time).
**Workflow:** Beta (after build, `/check verify` then `/test`). This is the project default level of rigor. `/architect` is the recommended first stop for a feature with a real decision, but you can skip it when you already know the build. Any feature can carry its own tag (e.g. `· GA`) to do more or less.

_These are recommendations to keep your build orderly, not requirements. Skip anything that does not fit: if you already know how to build a feature, use `/develop` and skip `/architect`. You decide when a feature is `done`._

## At a glance

| #   | Feature                         | Phase      | Status      |
| --- | ------------------------------- | ---------- | ----------- |
| 1   | Stack & architecture            | Foundation | done |
| 2   | Coding standards & tooling      | Slice 0    | planned     |
| 3   | Data model                      | Slice 0    | planned     |
| 4   | Upload screen                   | Slice 0    | planned     |
| 5   | Cover details form              | Slice 0    | planned     |
| 6   | Copyleaks scan & result webhook | Slice 0    | planned     |
| 7   | Raw results storage             | Slice 0    | planned     |
| 8   | Report PDF engine               | Slice 0    | planned     |
| 9   | Manuscript page rendering       | Slice 0    | planned     |
| 10  | Copyleaks to report mapping     | Slice 0    | planned     |
| 11  | Similarity cover page           | Slice 0    | planned     |
| 12  | Similarity overview pages       | Slice 0    | planned     |
| 13  | Highlights & margin badges      | Slice 0    | planned     |
| 14  | Design system & UI foundation   | Foundation | planned     |
| 15  | Staff login (invite only)       | Slice 1    | planned     |
| 16  | Admin creates staff accounts    | Slice 1    | planned     |
| 17  | Submissions list & status       | Slice 2    | planned     |
| 18  | Failed scan & retry             | Slice 2    | planned     |
| 19  | Match groups rules              | Slice 3    | planned     |
| 20  | Source types, names & ranking   | Slice 3    | planned     |
| 21  | Our own cover details           | Slice 3    | planned     |
| 22  | Integrity flags                 | Slice 3    | planned     |
| 23  | AI writing report               | Slice 4    | planned     |
| 24  | Review & download reports       | Slice 5    | planned     |

## Foundations

### 1. Stack & architecture

Record the stack you already chose (Laravel 13, Vue 3 with Inertia, the Laravel Vue starter kit, shadcn-vue, a Laravel queue) and install the framework and libraries so the project runs. Install only what Slice 0 needs.
**Done when:** the stack is written down in a spec and the empty project starts locally and builds.
spec [0001](specs/0001-stack-and-architecture/index.md) · code in the repo root

- [x] Decide the stack (spec): `/architect stack & architecture`
- [x] Scaffold from the decision: `/develop stack & architecture`

## Slice 0: Proof of concept (also the walking skeleton)

One real thread from upload to a finished similarity PDF. The PDF follows the layout and format in the design spec exactly (page sizes, fonts, colors, header and footer, page breaks). The content comes only from what Copyleaks gives us, placed into the matching spots of that layout. Anything Copyleaks does not give us, and that we would have to work out or invent ourselves, is left out of this slice and planned in Slice 3. Where a spot has no data, it is left out cleanly, never guessed.

Login is off in this slice. Credits are limited: use small files, never scan the same file twice, and only scan when a person clicks upload. Once a result is saved, rebuild the PDF from it instead of scanning again.

### 2. Coding standards & tooling

First thing after the install: capture conventions, then set up linting, formatting and checks from the real project, so all later code follows them.
**Done when:** root `AGENTS.md` reflects the real project, and lint and format run clean.

- [ ] Capture conventions and tooling choices: `/audit`
- [ ] Install the tooling: `/develop tooling`
- [ ] Check it runs clean: `/test`

### 3. Data model · needs a decision

The core records: a submission (file, cover details, status), the raw Copyleaks replies kept exactly as received, and the finished reports. Raw data is never changed, so old reports can be rebuilt.
**Done when:** the records support Slice 0 and every later slice without a breaking change, and raw results are stored unchanged.

- [ ] Design it (spec): `/architect data model`

### 4. Upload screen

One simple page to upload a manuscript (Word or PDF). No login needed yet.
**Done when:** a person can pick a small file and submit it, and the file is saved.

- [ ] Build it: `/develop upload screen`

### 5. Cover details form

The upload form also asks for author, title and assignment or class name, which the cover page shows.
**Done when:** a submission saves these three details, and bad input shows clear errors.

- [ ] Build it: `/develop cover details form`

### 6. Copyleaks scan & result webhook · needs a decision

Send the uploaded file to Copyleaks for a similarity check and an AI check, and receive the answer through a webhook. Keys stay on the server and out of logs.
**Done when:** one upload triggers exactly one scan, the webhook delivers the result, and no scan ever runs by itself (not in loops, not in tests).

- [ ] Design it (spec): `/architect copyleaks scan & result webhook`

### 7. Raw results storage

Save every Copyleaks reply exactly as it arrives, plus the original file.
**Done when:** the saved replies match what Copyleaks sent, and a PDF can be rebuilt from them without scanning again.

- [ ] Build it: `/develop raw results storage`

### 8. Report PDF engine · needs a decision

Build PDFs from our own HTML templates, following the design spec: Letter pages with 36 pt side margins, Lexend Deca and Noto Sans fonts, the color tokens, the shared header and footer (`Page n of N - Section` and an ID) with our logo, and the page break rules. Font licences are still to confirm.
**Done when:** a sample report renders with the right page sizes, margins, fonts, colors, header and footer, and uses our logo only.

- [ ] Design it (spec): `/architect report pdf engine`

### 9. Manuscript page rendering · needs a decision

Turn the uploaded Word or PDF file into pages we can draw highlights on, keeping the original page size (A4 or Letter) and the original look.
**Done when:** a Word file and a PDF file both appear as pages inside the report with their text positions known.

- [ ] Design it (spec): `/architect manuscript page rendering`

### 10. Copyleaks to report mapping · needs a decision

Decide which Copyleaks field fills which spot in the Turnitin layout, using only what Copyleaks returns: the overall score, the matched text types, the source list with its percentages, and the match positions. Spots with no matching Copyleaks data are left out for now. The header and footer ID uses the Copyleaks scan ID until Slice 3.
**Done when:** a written table shows every spot in the layout and the Copyleaks field behind it (or "left out"), and a saved result can be turned into the data the report pages need without scanning again.

- [ ] Design it (spec): `/architect copyleaks to report mapping`

### 11. Similarity cover page

The cover page as the design spec describes it, filled with the author, title and assignment from the form, the file name, and any details Copyleaks gives us.
**Done when:** the cover matches the design spec layout, and any detail we have no data for is left out, with no blanks or guesses.

- [ ] Build it: `/develop similarity cover page`

### 12. Similarity overview pages

The overview pages as the design spec describes them: the overall score, the match types and the top sources from Copyleaks, laid out in the same blocks and rows. The source list flows across pages without splitting a row, and the summary block repeats on the second page.
**Done when:** the pages match the design spec layout, and every number agrees with the saved Copyleaks result.

- [ ] Build it: `/develop similarity overview pages`

### 13. Highlights & margin badges

Draw highlights and numbered margin badges on the manuscript pages, using the match positions Copyleaks gives us and the colors and shapes from the design spec.
**Done when:** highlighted text lines up with the right words on every page, and badges sit in the margin without overlapping.

- [ ] Build it: `/develop highlights & margin badges`

## Foundations, continued

### 14. Design system & UI foundation · needs a decision

Base look and layout for the staff screens (built on shadcn-vue), so every screen feels the same. The report has its own look, set by the design spec.
**Done when:** type, colors, spacing and base components are written down, and base components work with the keyboard.

- [ ] Design it (spec): `/architect design system & UI foundation`

## Slice 1: Staff access

### 15. Staff login (invite only) · GA

Staff sign in with accounts that already exist. No sign up. The upload screen and everything after it now needs login.
**Done when:** only signed in staff reach the app, there is no public sign up, and failed logins are limited.

- [ ] Build it: `/develop staff login`

### 16. Admin creates staff accounts

An admin creates and disables staff accounts.
**Done when:** an admin can add and disable a staff member, and a non admin cannot.

- [ ] Build it: `/develop admin creates staff accounts`

## Slice 2: Managing submissions

### 17. Submissions list & status

A list of submissions showing waiting, done or failed.
**Done when:** staff see every submission with its current status, and the status updates when Copyleaks answers.

- [ ] Build it: `/develop submissions list & status`

### 18. Failed scan & retry

When Copyleaks fails, mark the submission failed with the reason, and let staff try again. Never show a score we do not have.
**Done when:** a failed scan shows its reason, and retry works without creating a duplicate submission.

- [ ] Build it: `/develop failed scan & retry`

## Slice 3: Fill the report gaps (the parts Copyleaks does not give us)

These are the "missing parts" from the brief. Each one replaces a spot that Slice 0 left out or filled with plain Copyleaks data.

### 19. Match groups rules · needs a decision

Our own rules that sort each match into the four groups (Not Cited or Quoted, Missing Quotations, Missing Citation, Cited and Quoted), for example by looking for quotation marks and nearby citations.
**Done when:** every match lands in exactly one group, the rules are written down and tested on sample text, and the overview and highlights use the four groups and their colors.

- [ ] Design it (spec): `/architect match groups rules`

### 20. Source types, names & ranking · needs a decision

Map Copyleaks source types to our three labels (Internet, Publications, Student Papers), show the best name we have (no date when missing), rank the sources, and give them the four color cycle.
**Done when:** each source has a label, a name, a rank and a color, and missing names or dates never break the page.

- [ ] Design it (spec): `/architect source types, names & ranking`

### 21. Our own cover details

Our own submission ID, upload and download dates, file size, and page, word and character counts (counted ourselves where Copyleaks does not give them), shown on the cover and in the header and footer.
**Done when:** the cover shows every detail from the design spec, and the header and footer use our own submission ID.

- [ ] Build it: `/develop our own cover details`

### 22. Integrity flags · needs a decision

Show the flag count and the info box. Highlight flagged text only if Copyleaks confirms it gives positions.
**Done when:** the flag count is shown correctly, and text is highlighted only when positions exist.

- [ ] Design it (spec): `/architect integrity flags`

## Slice 4: AI writing report

### 23. AI writing report · needs a decision

Cover page, AI overview and manuscript pages with AI highlights in our own style, reusing the report engine from Slice 0. Includes the decision on the "under 20%" rule (show `*%` instead of a number) and whether customers see AI scores at all.
**Done when:** an AI report builds from saved data, shows the score by the rule we picked, and highlights AI written text.

- [ ] Design it (spec): `/architect ai writing report`

## Slice 5: Review and download

### 24. Review & download reports

Staff open a finished submission, check the report on screen, and download either PDF to send to the customer.
**Done when:** staff can view and download both PDFs for a finished submission, and the files are only reachable when signed in.

- [ ] Build it: `/develop review & download reports`

## Deferred

Out of scope for the first version, kept so the plan stays honest.

- **Staff approval before download**: each report approved before it can be downloaded · needs a decision
- **Customer accounts or portal**: customers log in and see their reports · needs a decision · GA
- **Emailing reports to customers**: send the PDF automatically · needs a decision
- **Payments and billing**: charge for reports · needs a decision · GA
- **Editing results by hand**: staff change results before the report · needs a decision
- **Other report styles**: anything besides the Turnitin style layout · needs a decision

## Legend

**The decision box.** Every feature carries exactly one, the sub task whose label ends with `(spec)`. Its wording varies (`Design it (spec)` normally, `Decide the stack (spec)` on Stack & architecture), so skills find it by that `(spec)` suffix. Every other box is an execution box and `/architect` never ticks one.

**Feature lifecycle**: the scope updates as a feature moves.

| State                        | Set by                       | The feature shows                                                                                                                         |
| ---------------------------- | ---------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------- |
| `planned` · needs a decision | `/scope`                     | one box: `Design it (spec): /architect <feature>`                                                                                         |
| `in-progress` (designed)     | `/architect` at spec capture | `Design it` ticked; spec linked; `Build it: /develop <feature>` with 2 to 5 milestones; the tier's closing boxes (`Verify it`, `Test it`) |
| `in-progress` (building)     | `/develop`                   | milestone boxes tick one by one; code pointer filled                                                                                      |
| `in-progress` (verified)     | `/check verify`              | `Build it` and milestones ticked; `Verify it` ticked                                                                                      |
| `done`                       | you, when you decide it is   | boxes you ran ticked, skipped ones marked skipped; at Beta the suggested point is after `/test`                                           |

- **Next step** is the first unticked box (always a command or a tracked milestone).
- **needs a decision** means run `/architect` first; otherwise go straight to `/develop` (or `/audit` for standards and tooling). The tag drops once the spec is captured.
- **Atomic build tasks live in the spec's `## Build plan`**, not here. The scope carries only the milestone rollup.
- **Status** goes `planned` to `in-progress` to `done`, plus `existing` (built before the workflow) and `dropped` (de-scoped, kept for history).
- **Workflow tier tag** beside a heading (e.g. `· GA`) sets that one feature's rigor above or below the project default. No tag means it follows the default.
- **Pointer line** (`spec <n> · code in <path>`) appears once a spec or code exists.
