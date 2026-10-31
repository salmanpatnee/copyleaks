# Turnitin Report Design Specification

A page-by-page breakdown of the Turnitin PDF report design, so we can build a similar layout in our own reporting system (MPS).

## How this was measured

- **Main sample:** `RAH-EDU-13483_1793141285_127.pdf` (Similarity report, 24 pages) and `RAH-EDU-13483_1793141285_127 (1).pdf` (AI Writing report, 18 pages).
- **Checked for differences:** `Hello everyone (1) (1).pdf` (Similarity, 5 pages), `Hello everyone (1).pdf` (AI, 4 pages, 0% score), `kb-agt-salman-300_1 new (1).pdf` (Similarity, 75 pages) and `kb-agt-salman-300_1 new (2).pdf` (AI, 60 pages).
- **Method:** I read every text span, shape and image straight from the PDFs with PyMuPDF, then rendered pages to check them by eye. Measurements tagged **Measured** come from the PDF data. Values tagged **Estimate** are based on how the page looks.
- **Units:** all sizes are in **PDF points (pt)**. 1 pt = 1/72 inch. To convert: px at 96 dpi = pt × 1.333, and mm = pt × 0.3528.
- **Coordinates:** x is measured from the left edge and y from the top edge. "top" is the top of the text box, not the baseline.
- **Producer:** the files were put together with **pdf-lib**. Turnitin creates its own pages (cover and overview) and joins them with the original submitted pages, then adds a header and footer to every page.

---

## 1. Global design system

### 1.1 Page size and orientation

| Page type | Size | Orientation | Source |
|---|---|---|---|
| Cover and overview pages | **612 × 792 pt** (US Letter, 8.5 × 11 in) | Portrait | Measured |
| Submission pages (the student's document) | **Same size as the uploaded file.** In the samples this is either A4 (**595.32 × 841.92 pt**) or Letter (612 × 792 pt) | Portrait | Measured |

So a single report can contain pages of two different sizes. Turnitin does not resize the student's pages.

### 1.2 Margins and content area (Turnitin's own pages)

| Item | Value | Source |
|---|---|---|
| Left margin | **36 pt** (0.5 in) | Measured: all content starts at x = 36 |
| Right margin | **36 pt**, so content ends at x = 576 | Measured: dividers run from 36 to 576 |
| Content width | **540 pt** | Measured |
| Header band | logo from y = 24 to 40.5 | Measured |
| First content on overview pages | y ≈ 53–60 | Measured |
| Last content on a full page | y ≈ 737 (the last list divider) | Measured |
| Footer band | logo from y = 748 to 764.5, which leaves **27.5 pt** below it | Measured |
| Bottom margin under the footer | 27.5 pt | Measured |

On submission pages, the original document keeps its own margins (for example, a Word file with 72 pt / 1 inch margins). Turnitin only adds the header, footer and the badges in the left margin.

### 1.3 Grid

- **One column** across the full 540 pt width for headings, dividers and the Top Sources list.
- **Two columns** for the "Match Groups | Top Sources" summary block:
  - Left column starts at **x = 36**.
  - Right column starts at **x = 298.5**, which is the page center (306) minus 7.5. Measured.
- **Callout boxes** sit in the right half and start at x = 301 (Similarity) or x = 306 (AI). Measured.

### 1.4 Fonts

Both font families are embedded as subsets, so their names carry random suffixes such as `NotoSans-SemiBold-816`.

| Role | Family | Weight | Size | Source |
|---|---|---|---|---|
| Cover: author name | **Lexend Deca** | SemiBold (600) | **20 pt** | Measured |
| Cover: document title; overview score heading ("18% Overall Similarity", "0% detected as AI") | **Lexend Deca** | Medium (500) | **17 pt** | Measured |
| Section headings ("Document Details", "Match Groups", "Top Sources", "Integrity Flags", "Filtered from the Report") | **Noto Sans** | SemiBold (600) | **10 pt** | Measured |
| Source names in the Top Sources list, and their percentages | Noto Sans | SemiBold | **8 pt** | Measured |
| Cover: assignment / class name | Noto Sans | Regular (400) | **8 pt** | Measured |
| Margin badge numbers on submission pages | Noto Sans | SemiBold | **7.75 pt** | Measured |
| Body, labels and descriptions | Noto Sans | Regular or SemiBold | **7 pt** | Measured |
| Callout text, disclaimer, and header / footer text | Noto Sans | Regular (and SemiBold for callout titles) | **6 pt** | Measured |
| Submission page content | The student's own fonts, embedded as `CIDFont+F1…F6` (in the sample, Times-like at 12 pt, black) | — | — | Measured |

**Type scale:** 20 / 17 / 10 / 8 / 7.75 / 7 / 6 pt. The text is very small, because the report is meant to be read on screen and zoomed.

**Line spacing (measured from the gaps between lines):**
- 7 pt text: **10 pt** per line, about 1.43× the font size.
- 6 pt text: **8 pt** per line, about 1.33× the font size.
- A blank line between paragraphs in callouts: **16 pt** from the top of one paragraph's last line to the next paragraph (one empty line).

**Licences:** Lexend Deca and Noto Sans are both free Google Fonts under the SIL Open Font License, so we can use them. *(We should still confirm this before shipping.)*

### 1.5 Colors

**Text and neutral colors (measured):**

| Token | Hex | Used for |
|---|---|---|
| Text primary | `#191919` | Headings, values, body text, header and footer |
| Text secondary | `#636363` | Descriptions, field labels on the cover, disclaimer, Top Sources icons, assignment line on the cover |
| Text in callouts | `#2d2d2d` | Callout box text; also the color of icons inside the match-group circles |
| Text on dark pills | `#fefefe` (near white) | Numbers inside source pills and margin badges |
| Divider | `#cccccc`, **0.5 pt** stroke | All horizontal rules |
| Stats box background | `#f8f8f8` | The Pages / Words / Characters box on the cover |
| Info callout background | `#d0e8f9` (light blue) | "Integrity Flags" explanation box and "Caution: Review required" box |
| Cover assignment icon | `#888888` | Clipboard icon |

**Brand colors (logo, measured from the logo image pixels):**

| Part | Hex |
|---|---|
| Wordmark "turnitin" | `#003c46` (dark teal) |
| Arrow mark | `#0096ff` (bright blue) |

**Match group colors (measured).** Each color is used for the circle on the overview pages, the highlight on submission pages, and the left half of the margin badge.

| Match group | Hex | Icon inside the circle |
|---|---|---|
| Not Cited or Quoted | `#f86371` (coral red) | Stacked pages / copy |
| Missing Quotations | `#f68e4a` (orange) | Quotation mark ( ❞ ) |
| Missing Citation | `#f4e4aa` (pale yellow) | Three horizontal lines (text) |
| Cited and Quoted | `#c1ecdf` (mint) | Graduation cap |

**Source colors (measured).** These rotate in a fixed cycle of 4 based on the source's **rank**, not its type.

| Rank (mod 4) | Strong color (number pill, badge) | Light tint (category pill) |
|---|---|---|
| 1, 5, 9 … | `#cb1476` magenta | `#fedce7` |
| 2, 6, 10 … | `#225ec6` blue | `#d6e5fb` |
| 3, 7, 11 … | `#007546` green | `#c1ecdf` |
| 4, 8, 12 … | `#7533e7` purple | `#ecdffe` |

**AI report:**

| Item | Hex |
|---|---|
| "AI-generated" group icon (robot in a circle) | `#52c7db` (cyan) circle with a `#2d2d2d` robot. Measured from the image pixels. |
| AI highlight color on submission pages | **Not visible in any sample.** Every AI report here was below 20% or at 0%, so no text was highlighted. Still unknown. |

### 1.6 Shapes, borders and corner radius (measured from path data)

| Element | Size | Corner radius | Fill | Border |
|---|---|---|---|---|
| Divider line | full width or 36–400 | — | — | 0.5 pt `#cccccc` |
| Match-group circle | 12.5 × 12 pt | 6 pt (a full circle) | group color | none |
| Source number pill | 24.5 × 15 pt | 7 pt | strong source color | none |
| Source category pill | 70 × 15 pt | 7.5 pt (fully rounded) | light tint | none |
| Info callout box | 266 × 64 pt (Similarity) and 273 × 56 pt (AI) | **8 pt** | `#d0e8f9` | none |
| Cover stats box | 89 × 78 pt | **0** (square) | `#f8f8f8` | none |
| Margin badge, left part | 15.5 × 15.5 pt | full circle | match-group color | none |
| Margin badge, right part | 17.7 × 15.5 pt | about 7.75 pt (pill) | strong source color | none |
| Text highlight | matches the line height (about 10.5–11 pt) | 0 (square) | match-group color at **80% opacity** | none |

There are no borders anywhere. Shapes are separated only by fill color and by the thin grey dividers. There are no shadows or gradients.

### 1.7 Icons

- All icons are small raster images (PNG) with transparency. None are vector graphics or icon-font glyphs.
- Icons in the match-group circles: about 5.5 × 6 pt, color `#2d2d2d`, placed 3 pt in from the circle's left edge.
- Top Sources summary icons (globe = Internet, open book = Publications, person = Student Papers): about 8.5 × 8.5 pt, color `#636363`.
- "Filtered from the Report" list marker: a tiny right-pointing triangle, 2 × 3 pt, color `#2d2d2d`.
- Cover assignment icon: a clipboard, 12 × 12 pt, color `#888888`.
- Logo: an image 1392 × 417 px drawn at **55 × 16.5 pt** (about 3.33 : 1).

### 1.8 Header and footer (on every page, including the student's pages)

The header and footer are **identical**. The footer is the same strip moved to the bottom of the page.

| Part | Position | Style |
|---|---|---|
| Logo | x = 36, y = 24 (header) or *page height − 44* (footer), 55 × 16.5 pt | Image |
| Page label | x = **107** (16 pt after the logo), text top at y = 27.6 | Noto Sans Regular 6 pt `#191919` |
| "Submission ID" label | **right-aligned** with the value, 5 pt gap | Noto Sans Regular 6 pt `#191919` |
| Submission ID value, e.g. `trn:oid:::3117:645894348` | right edge at *page width − 36* (576 on Letter, 559.3 on A4) | Noto Sans Regular 6 pt `#191919` |

- **Page label format:** `Page {n} of {total} - {Section}`. The page count covers the whole report, not just one section.
- **Section names:** `Cover Page`, `Integrity Overview`, `Integrity Submission`, `AI Writing Overview` and `AI Writing Submission`.
- The text is vertically centered on the logo: logo 24–40.5, text 27.6–35.8.
- There are no lines or background bands under the header or above the footer.
- On submission pages the header and footer are placed relative to that page's own size. On A4 the footer logo sits at y = 797.9.
- Bookmarks / outline: **none**. Links: **none**. Annotations: **none**. Everything is printed directly onto the page.

### 1.9 Page breaks

- The cover is always exactly 1 page.
- The overview section flows from page to page. A Top Sources row (pill, name and divider, 48 pt high) **is never split across pages**.
- When the overview flows to a second page, the **Match Groups and Top Sources summary block is repeated** at the top of that page (see page 3 below). From page 4 on, only list rows appear.
- The first submission page always starts on a new page.
- Section lengths in the samples: Similarity 24 pages = 1 cover + 7 overview + 16 submission. AI 18 pages = 1 cover + 1 overview + 16 submission. For the 75-page Similarity report, the overview takes 16 pages, because it lists every source.

---

## 2. Similarity report, page by page

### Page 1: Cover Page

The cover is the same in both reports.

The content is placed in the **lower half** of the page. The space from y = 41 to y = 336 (about 295 pt) is left completely empty. *(Estimate: this is on purpose, to give the page a "title page" feel.)*

| # | Element | x | y (top) | Font | Color |
|---|---|---|---|---|---|
| 1 | Author name, e.g. "Author 32" | 36 | **336** | Lexend Deca SemiBold 20 | `#191919` |
| 2 | Document title (the file name without its extension) | 36 | **369** | Lexend Deca Medium 17 | `#191919` |
| 3 | Clipboard icon 12 × 12 | 36 | 397 | image | `#888888` |
| 4 | Assignment / class name, e.g. "paper checking 10" | **56** (8 pt after the icon) | 397.4 | Noto Sans Regular 8 | `#636363` |
| 5 | Divider, from 36 to 576 | — | **425** | 0.5 pt | `#cccccc` |
| 6 | "Document Details" | 36 | 443.3 | Noto Sans SemiBold 10 | `#191919` |
| 7 | Field list (see below) | 36 | 472.5 onward | Noto Sans SemiBold 7 | label `#636363`, value `#191919` |
| 8 | Stats box (x 403–492, y 470–548) | 403 | 470 | — | `#f8f8f8` fill |
| 9 | Stats text: "16 Pages", "5,523 Words", "33,999 Characters" | **415** (12 pt padding) | 484.5, 506.5, 528.5 | Noto Sans SemiBold 7 | `#191919` |

**Field list layout (measured):**
- There are 5 pairs in this order: Submission ID, Submission Date, Download Date, File Name, File Size.
- Each pair has the label on top (grey) and the value **14 pt** below it (dark). Both labels and values use the **SemiBold** weight.
- A new pair starts every **36 pt**: label tops at 472.5, 508.5, 544.5, 580.5 and 616.5.
- The last value ends at about y = 640.

**Stats box:**
- Square corners, no border.
- The text inside has 12 pt padding on the left and 14.5 pt on top. The three lines are 22 pt apart.
- The box sits in the right half and starts 2.5 pt above the first field label.

**Value formats:**
- Dates look like `Oct 28, 2026, 4:12 PM GMT+5`.
- File size looks like `113.9 KB`.
- Counts use thousands separators, for example `5,523 Words`.

### Page 2: Integrity Overview (first page)

| # | Element | x | y (top) | Style |
|---|---|---|---|---|
| 1 | Score "18%" | 36 | 55 | Lexend Deca Medium 17, `#191919` |
| 2 | "Overall Similarity" | the score's right edge + **10 pt** | 55 | same as the score |
| 3 | Subtitle "The combined total of all matches, including overlapping sources, for each database." | 36 | 80 | Noto Sans Regular 7, `#636363` |
| 4 | "Filtered from the Report" | 36 | 100.8 | Noto Sans SemiBold 10 |
| 5 | Filter items, e.g. "Bibliography", "Quoted Text", "Small Matches (less than 8 words)" | marker at x 39, text at **48** | 122, then every **14 pt** | Noto Sans Regular 7, `#191919` |
| 6 | Divider, full width | — | last item top + 26.5 (148.5 with 1 item, 176.5 with 3 items) | 0.5 pt `#cccccc` |
| 7 | Two-column block: "Match Groups" (x 36) and "Top Sources" (x 298.5) headings | — | divider + 13.3 | Noto Sans SemiBold 10 |
| 8 | Divider, full width | — | 291 | |
| 9 | "Integrity Flags" | 36 | 301.8 | Noto Sans SemiBold 10 |
| 10 | "0 Integrity Flags for Review" | 36 | 319 | Noto Sans SemiBold 7 |
| 11 | Info callout (x 301–567, y 318.5–382.5) | 301 | 318.5 | `#d0e8f9`, 8 pt radius |

**Match Groups rows (left column):**
- Row pitch: **25 pt**. The first row starts 23.2 pt below the heading top.
- Circle: 12.5 × 12 pt at x = 36, with its top at text top − 0.5. The icon sits at x = 39.
- Line 1:
  - Count, e.g. "56", in **SemiBold** 7 pt at x = **53**.
  - Then the name and percentage in Regular 7 pt, written as `Not Cited or Quoted␣␣11%`. There are **two spaces** before the percentage.
  - The name starts 3 pt after the count: x = 64 for 2-digit counts and x = 60 for 1-digit counts.
- Line 2: the description in Regular 7 pt, `#636363`, at x = 53, **10 pt** below line 1.
- The four groups always appear, in this fixed order, even when a count is 0.

| Group | Description text |
|---|---|
| Not Cited or Quoted | Matches with neither in-text citation nor quotation marks |
| Missing Quotations | Matches that are still very similar to source material |
| Missing Citation | Matches that have quotation marks, but no in-text citation |
| Cited and Quoted | Matches with in-text citation present, but no quotation marks |

**Top Sources summary (right column):**
- 3 rows, **14 pt** apart.
- Percentage at x = 298.5, then the icon at x ≈ 322, then the label at x = **336**. All text is Regular 7 pt `#191919`.
- The rows are always "Internet sources", "Publications" and "Submitted works (Student Papers)", in that order.

**Integrity Flags callout:**
- Text starts at x = **313** (12 pt padding on the left). The first line starts 7.6 pt below the box top.
- Text is 6 pt Regular `#2d2d2d` with 8 pt line spacing.
- There are two paragraphs separated by one blank line.
- The box's top edge is level with the "0 Integrity Flags for Review" line, and the box sits in the right column.

Below this content, the rest of page 2 is empty. The detailed source list always starts on the next page.

### Page 3: Integrity Overview (continued)

1. The **summary block is repeated**: "Match Groups" and "Top Sources" headings at y = 53.3, with the same rows as page 2.
2. Divider at y = 187.5.
3. "Top Sources" heading (10 pt SemiBold) at y = 205.8.
4. Description at y = 224: *"The sources with the highest number of matches within the submission. Overlapping sources will not be displayed."* (Regular 7, `#636363`).
5. **Source list rows** begin at y = 245.5.

**Source list row: anatomy (measured).** Each row is **48 pt** tall:

```
y+0    ┌──────┐ ┌──────────────────┐
       │  1   │ │  Student papers  │      ← pills, 15 pt tall
y+15   └──────┘ └──────────────────┘
y+19.4 Coventry University on 2026-10-26                       1%   ← 8 pt SemiBold
y+38   ───────────────────────────────────────────────────────────  ← divider 36→400
y+48   (next row)
```

| Part | x | Size | Style |
|---|---|---|---|
| Number pill | 36–60.5 | 24.5 × 15, radius 7 | strong source color; number in white `#fefefe`, SemiBold 7, centered |
| Category pill | **66**–136 (6 pt gap) | 70 × 15, radius 7.5 | light tint; label in SemiBold 7 `#191919`, centered |
| Source name | 36 | — | SemiBold 8 `#191919`, starts 19.4 pt below the pill top |
| Percentage | **386**, left-aligned | — | SemiBold 8 `#191919`, e.g. `1%` or `<1%` |
| Divider | 36 → **400** (does **not** run full width) | 0.5 pt | `#cccccc`, 38 pt below the pill top |

- **Category labels seen:** "Student papers", "Internet" and "Publication".
- **Source name formats:**
  - Student papers: `{Institution} on {YYYY-MM-DD}`
  - Internet: the domain, e.g. `www.econstor.eu`
  - Publication: the citation with author names and a quoted title
- **Long names** are cut off with "…" so they end at about x = 358, which leaves room before the percentage at 386.
- **Percentages:** whole numbers. Anything under 1% is shown as `<1%`.
- **Rows per page:** 10 on page 3, because the summary block takes up space at the top.
- The list uses only the left **364 pt** of the page (36 to 400). The right part of the page is left empty.

### Pages 4–8: Integrity Overview (list continued)

- Only source list rows, with no headings.
- The first pill top is at **y = 75**. Rows follow every 48 pt, so the last divider is at y = 737.
- **14 rows per page.**
- The last overview page simply ends early. Page 8 has 3 rows (sources 67–69), and the rest of the page is blank.
- The color cycle continues across pages (rank mod 4).

### Pages 9–24: Integrity Submission (the student's document)

- The page is the **original page** at its original size (A4 here) with the original fonts, layout and colors.
- **Header and footer** are added at the same positions as on other pages, relative to this page's size.
- **Highlights:**
  - Square rectangles behind or over each matched run of text.
  - Fill: the match-group color (`#f86371`, `#f68e4a`, `#f4e4aa` or `#c1ecdf`) at **80% opacity**.
  - Height: about 10.5–11 pt, which matches the text line. Width: the exact width of the matched words.
  - A separate rectangle is drawn for each line of each match. Highlights often break between words.
  - Draw order: the highlights come after most of the text in the drawing order, yet the text still looks solid black. *(Estimate: the highlights use a multiply-style blend, so the text shows through. If we recreate this, drawing the highlight **under** the text gives the same look.)*
- **Margin badges** (in the left margin, level with the first line of each match):

```
x 7.5            x 24.1
 ┌───┐┌──────┐
 │ ⧉ ││  32  │   ← 15.5 pt tall
 └───┘└──────┘
 circle  pill
```

  - Left part: a **15.5 pt circle** in the match-group color, with the group's icon (6 × 6.6 pt) in `#2d2d2d`.
  - Right part: a **17.7 × 15.5 pt pill** in the source's strong color, with the source rank number in white SemiBold **7.75 pt**, centered.
  - Gap between the two parts: about 1 pt.
  - The badge's top edge lines up with the highlight's top edge.
  - When two matches start on the **same line**, the second badge is placed to the right at **x = 44**, about 2 pt after the first badge.
- Badges and highlights are the only additions. Turnitin adds **no** numbers in the right margin, no line numbers and no source panel.
- Other colors found in the 75-page sample, such as yellow `#ffff00` and `#467886`, come from the **original document** (Word highlighting and hyperlink colors). They are not Turnitin's.

---

## 3. AI Writing report, page by page

### Page 1: Cover Page

Identical to the Similarity cover (section 2, page 1). Only the page count in the header and footer changes.

### Page 2: AI Writing Overview

This layout has **3 or 4 bands**, separated by full-width dividers.

| # | Element | x | y (top) | Style |
|---|---|---|---|---|
| 1 | Divider (this page **does** have a rule below the header, unlike the Similarity overview) | 36–576 | **60** | 0.5 pt `#cccccc` |
| 2 | Score heading: `0% detected as AI`, or `*% detected as AI` when the score is between 1% and 19% | 36 | **81** | Lexend Deca Medium 17 `#191919` |
| 3 | Explanation, 2–3 lines, about 260 pt wide | 36 | 100.5, with 10 pt line spacing | Noto Sans Regular 7, **`#191919`** (dark, not grey) |
| 4 | Caution callout (x 306–**579**, y 76–132) | 306 | 76 | `#d0e8f9`, 8 pt radius. Note: its right edge goes **3 pt past** the 576 margin |
| 4a | "Caution: Review required." | **318** (12 pt padding) | 85.6 | Noto Sans **SemiBold** 6 `#2d2d2d` |
| 4b | Callout body, 3 lines | 318 | 101.6, with 8 pt line spacing | Noto Sans Regular 6 `#2d2d2d` |
| 5 | Divider | full | **148** | |
| 6 | *(0% case only)* AI group row | 36 | 166.5 | see below |
| 7 | *(0% case only)* Divider | full | **200** | |
| 8 | "Disclaimer" | 36 | 165.6 (`*` case) or 217.6 (0% case) | Noto Sans SemiBold 6 `#636363` |
| 9 | Disclaimer body, 3 lines, **full width** (36 to 576) | 36 | + 8 pt | Noto Sans Regular 6 `#636363`, 8 pt line spacing |
| 10 | Closing divider | full | 212 (`*` case) or 264 (0% case) | |

**Explanation wording depends on the score:**
- **0%:** *"The percentage indicates the combined amount of likely AI-generated text as well as likely AI-generated text that was also likely revised by AI."*
- **`*%` (1–19%):** *"AI detection includes the possibility of false positives. Although some text in this submission is likely AI generated, scores below the 20% threshold are not surfaced because they have a higher likelihood of false positives."*

**AI group row** (only shown in the 0% sample):
- Icon: a 12 × 12 pt cyan circle with a robot (`#52c7db`).
- Line 1 at x = **52**: the count "0" in **SemiBold** 7, then "AI-generated" in Regular 7, then the percentage after two spaces ("0%").
- Line 2 at x = 52, 10 pt below line 1: *"Likely generated or likely generated and revised by AI."* in Regular 7, `#636363`.
- This uses the same pattern as a Similarity match-group row, but the text starts at x = 52 instead of 53.
- *Unknown:* the `*%` sample has no group row at all. A report scoring 20% or higher probably shows more rows, such as "AI-generated only" and "AI-generated text that was AI-paraphrased", but **no sample shows this**. We need a report with 20% or more to confirm it.

The rest of page 2 is empty. The AI overview is always one page.

### Pages 3–18: AI Writing Submission

- The original pages at their original size, with the standard header and footer added.
- In the samples, **no highlights or badges are added**, because the scores are 0% or under 20%.
- *Unknown:* how AI-flagged text is highlighted (color, opacity, margin markers) when the score is 20% or higher.

---

## 4. Differences between the samples

| Item | Hello everyone (short) | RAH (16 pages) | kb (58 pages) |
|---|---|---|---|
| Filtered from the Report | Bibliography, Quoted Text, Small Matches (less than 8 words) | Bibliography | Bibliography |
| Overview pages | 2 (one source) | 7 | 16 |
| Submission page size | Letter | A4 | A4 |
| Match groups with counts above 0 | 1 | 2 | 3 (Missing Citation has 3, so pale yellow highlights appear) |
| AI score | 0% (shows the group row) | `*%` | `*%` |

Everything else is the same across all samples: positions, font sizes, colors, and header and footer placement. The layout is template-driven, and only the content changes.

---

## 5. Overall style summary

- **Look:** clean, flat and very light. White background, no borders, no shadows, no gradients. Sections are separated by thin `#cccccc` lines and by white space.
- **Hierarchy:**
  - Lexend Deca (geometric, friendly) is used only for the large headings: the score and the cover title and author.
  - Noto Sans is used for everything else.
  - Importance is shown through **weight** (SemiBold vs Regular) and **grey vs near-black**, more than through size.
- **Color is used only for meaning.** Match groups have one palette and source ranks have another. The only decorative color is the light-blue info callout.
- **Alignment:** everything is aligned to the left on x = 36, except the right column at 298.5–301, the header and footer values aligned to the right, and the cover stats box at 403.
- **Spacing rhythm (measured):**
  - 10 pt between lines of 7 pt text, 8 pt between lines of 6 pt text.
  - 14 pt between simple list rows, 25 pt between match-group rows, 36 pt between cover field pairs, and 48 pt between source rows.
  - About 13 pt from a divider to the next heading.
- **Density:** very small type (6–7 pt) with generous white space. The report expects readers to zoom in on screen.

---

## 6. Build checklist for our version

1. **Page setup:** Letter 612 × 792 pt for our own pages, with 36 pt side margins. Keep the student's pages at their original size.
2. **Fonts:** load Lexend Deca (Medium, SemiBold) and Noto Sans (Regular, SemiBold) as web fonts or embedded fonts.
3. **CSS tokens:** set these up as variables:
   - text `#191919`, muted `#636363`, callout text `#2d2d2d`
   - divider `#cccccc` at 0.5 pt, stats box `#f8f8f8`, info `#d0e8f9`
   - the 4 match-group colors and the 4 source color pairs from section 1.5
4. **Shared header and footer component:** our logo at 55 × 16.5 pt, then `Page n of N - Section` at x + 71, and the right-aligned ID. Place it 24 pt from the top and 27.5 pt from the bottom.
5. **Components:**
   - Match-group row
   - Summary block
   - Source row (48 pt, kept on one page)
   - Info callout
   - Margin badge
   - Highlight rectangle at 80% opacity
6. **Page-break rules:**
   - The cover is always 1 page.
   - The overview's second page repeats the summary block.
   - Source rows are never split across pages.
   - The submission section starts on a new page.
7. **Still to decide or confirm:**
   - AI highlight styling for scores of 20% or more.
   - Whether to copy the "under 20% = `*`" rule.
   - Font licence check.
   - A legal check on copying the layout too closely. *(This was already raised as an open question in earlier research.)*
