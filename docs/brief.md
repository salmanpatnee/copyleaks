# Scope: Manuscript Report Tool

Last updated: 2026-10-31

## What we are building

An internal tool for our staff. A staff member uploads a manuscript. The tool sends it to **Copyleaks**, which checks it for copied text (similarity) and for AI-written text. When Copyleaks finishes, the tool saves the full results and turns them into a **PDF report** that looks and reads like the Turnitin report our customers already know.

The report layout is described in [turnitin-report-design.md](turnitin-report-design.md). We copy the structure and style, but we use **our own name and logo**, never Turnitin's.

## Who uses it

- **Internal staff only.** It is not open to the public, and there is no sign-up.
- Staff log in with accounts that an admin creates.
- Customers never log in. They only receive the finished PDF from staff.

## How it is built

| Part | Choice |
|---|---|
| Back end | Laravel 13 |
| Front end | Vue 3 with Inertia |
| Login | The official Laravel Vue starter kit, using Fortify (login only, invite only) |
| UI components | shadcn-vue |
| Document checking | Copyleaks API (a third-party service) |
| Background work | Laravel queue, so uploads and PDF building don't make staff wait |
| PDF output | Built by our app from our own HTML templates, following the design spec |

## How it works, step by step

1. **Upload.** A staff member uploads a manuscript (Word or PDF) and fills in the cover details: author, title and assignment or class name.
2. **Send to Copyleaks.** The app sends the file to Copyleaks for a similarity check and an AI check.
3. **Wait for the result.** Copyleaks works in the background. When it's done, it calls our app back through a webhook.
4. **Save the raw results.** The app saves Copyleaks' replies **exactly as they arrive** (the raw JSON), along with the original file. We never change the raw data. If we improve the report later, we can rebuild old reports from what we saved.
5. **Build the report.** The app reads the saved results and creates two PDFs:
   - **Similarity report:** cover page, overview (match groups and top sources), then the manuscript pages with highlights and margin badges.
   - **AI writing report:** cover page, AI overview, then the manuscript pages.
6. **Review and download.** Staff check the report in the app and download the PDF to send to the customer.

## Building the missing parts

Copyleaks doesn't give us everything the Turnitin report shows. Our app has to work out or create these parts itself:

| Part of the report | What Copyleaks gives us | What we build |
|---|---|---|
| **Four match groups** (Not Cited or Quoted, Missing Quotations, Missing Citation, Cited and Quoted) | Matched text, split into identical, minor changes and paraphrased. There is no citation or quotation check. | Our own rules that sort each match into one of the four groups, for example by checking for quotation marks and nearby citations. |
| **Source types** (Internet, Publications, Student Papers) | Internet, database and repository results, using different words | A mapping from Copyleaks' source types to our three labels. |
| **Source names and dates**, e.g. "University of Sussex on 2025-11-13" | Often only a title or URL. Institution names and dates may be missing. | Show the best name available, and leave out the date when we don't have it. |
| **Source rank and colors** | Source list with percentages | Rank the sources and give them the 4-color cycle from the design spec. |
| **Margin badges and highlights** on manuscript pages | The start and end positions of matched text | Draw the highlights and badges onto the manuscript pages ourselves. |
| **Cover page details** (submission ID, dates, page, word and character counts) | Some counts | Our own submission ID, upload and download dates, and file size. We count words and characters ourselves if needed. |
| **Integrity flags** | Partly covered (hidden characters and similar alerts). Whether it gives positions is not confirmed. | Show the flag count and the info box. Highlight flagged text only if Copyleaks gives positions. |
| **The "under 20%" AI rule** (show `*%` instead of the number) | An AI score | Our own decision on whether to use this rule (see open questions). |
| **AI highlights** on manuscript pages | Positions of AI-written text | Our own highlight style, because no Turnitin sample shows it. |

## Phase 0: Proof of concept

Before we build the real tool, we build a proof of concept. It shows that the whole flow works from start to finish: upload, then Copyleaks, then saved results, then a PDF. If the results are what we want, we build the tool properly.

**What we build:**

1. **Install** Laravel with the Vue starter kit (Fortify), shadcn-vue and the tooling that comes with the starter kit (such as linting and formatting). We install them now so they are ready later, but we don't depend on them in this phase.
2. **Upload screen.** One simple page to upload a manuscript.
3. **Copyleaks processing.** Back-end code that sends the file to Copyleaks and receives the result.
4. **Save the results.** Store the raw Copyleaks results exactly as they arrive.
5. **Generate a PDF.** Build a simple PDF straight from the raw results.

**What we do not build in this phase:**

- No login or accounts. Fortify is installed, but the upload screen doesn't require login yet.
- No extra tooling work. The tools are installed, but we don't spend time setting up rules, CI or checks yet.
- None of the missing parts listed in "Building the missing parts". No match groups, source mapping, highlights, badges or Turnitin-style layout yet.

**Using credits carefully:**

- We only have limited credits in the Copyleaks sandbox, so every scan counts.
- Use small test files.
- Don't send the same file twice. Once a result is saved, reuse it to test and rebuild the PDF instead of scanning again.
- Never run scans automatically, in loops or in tests. Only scan when a person clicks upload.

## What is in the first version

- Staff login (invite only), with an admin who creates the accounts
- Upload a manuscript and enter its cover details
- Send it to Copyleaks and receive the result through the webhook
- Save the raw results and the original file
- A list of submissions with their status: waiting, done or failed
- Similarity PDF and AI PDF, following the design spec
- Download the PDFs

## What is not in the first version

- Customer accounts or a customer portal
- Emailing reports to customers automatically
- Payments or billing
- Editing results by hand
- Any report style other than the Turnitin-like layout

## Rules we keep

- Copyleaks API keys stay on the server. They are never sent to the browser and never written to logs.
- Raw Copyleaks results are saved unchanged. The report is always built from the saved data.
- If Copyleaks fails, the submission is marked "failed" with the reason, and staff can try again. We never show a score we don't have.
- Keep it simple. Build the smallest thing that works.

## Open questions

- **Price:** what is the Copyleaks API price per page, and are there minimums? Copyleaks counts one page as every 250 words.
- **AI billing:** is the AI check billed on top of the similarity check?
- **Data use:** does Copyleaks use our documents for training or keep them in a shared index? How long does it keep them?
- **Branding:** may we put Copyleaks results in our own branded report?
- **Approval:** should staff approve each report before it goes to a customer?
- **AI scores:** should customers see AI scores at all? Should we copy the "under 20%" rule?
- **Legal:** how closely can we copy the Turnitin report design?
- **Fonts:** confirm the licences for Lexend Deca and Noto Sans.
