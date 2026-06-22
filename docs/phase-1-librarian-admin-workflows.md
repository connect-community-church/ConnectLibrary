# ConnectLibrary — Phase 1 Librarian and Admin Workflows

A practical guide for volunteer librarians and WordPress administrators. Written for
Sunday-volunteer level: step-by-step tasks, plain language, no developer tools needed.

**Phase 1 is catalog foundation only.** See [What Phase 1 does not include](#2-what-phase-1-does-not-include-yet)
for what is deferred to later phases.

---

## Contents

1. [What Phase 1 includes](#1-what-phase-1-includes)
2. [What Phase 1 does not include yet](#2-what-phase-1-does-not-include-yet)
3. [Roles and access](#3-roles-and-access)
4. [First-time setup workflow](#4-first-time-setup-workflow)
5. [Add a book by ISBN lookup](#5-add-a-book-by-isbn-lookup)
6. [Add or edit a book manually](#6-add-or-edit-a-book-manually)
7. [Manage authors and series](#7-manage-authors-and-series)
8. [Manage public catalog display](#8-manage-public-catalog-display)
9. [Visibility, status, and privacy](#9-visibility-status-and-privacy)
10. [Troubleshooting](#10-troubleshooting)
11. [Quick-reference checklist](#11-quick-reference-checklist)

---

## 1. What Phase 1 includes

Phase 1 delivers the online catalog foundation. After installing and activating the plugin a
WordPress administrator can:

- Run the **ConnectLibrary Setup Wizard** to create the catalog page and save defaults.
- Add and edit book records in the WordPress admin using the **Library > Add New Book** screen.
- Look up book metadata by ISBN from Google Books and Open Library.
- Import cover images and store them in the WordPress **Media Library**.
- Manage author and series records backed by custom database tables.
- Set public catalog visibility (**Public** or **Hidden**) on each book.
- Place the catalog on any page using the `[connectlibrary_catalog]` shortcode or the
  **ConnectLibrary Catalog** Gutenberg block.
- Let public visitors search, filter, sort, and browse the catalog in grid or list view.
- Show public book detail pages with cover, metadata, status badge, and location.
- Display a basic public availability label on each book: **Available**, **Reserved**,
  **Checked Out**, **Waitlist Available**, or **Unavailable**.
- Configure plugin settings at **Settings > ConnectLibrary**.
- Follow accessibility and translation-readiness guidelines documented in
  `docs/accessibility-i18n-checklist.md`.

---

## 2. What Phase 1 does not include yet

The following features are intentionally deferred to later phases. Do not attempt to
configure, advertise, or email volunteers about these workflows until a later accepted
card introduces them.

| Feature | Phase |
|---|---|
| Borrower accounts / My Library | Phase 2 |
| Manual borrower records | Phase 2 |
| Child / guardian borrower workflows | Phase 2 |
| Reservations, guest approval, pickup holds, waitlists | Phase 2–3 |
| Checkout, return, renewals, due-date emails / reminders | Phase 2–3 |
| Library cards, QR codes, barcodes | Phase 3 |
| Sunday dashboard / scanner circulation workflows | Phase 3 |
| Reports, exports, audit-log browsing | Phase 3–4 |
| Offline / PWA install, offline cache, sync queue, conflict handling | **Phase 5** |
| Live church deployment | Requires explicit approval from the project steward |

The lending-defaults fields visible in the setup wizard and settings page (loan period days,
hold period days, reminder lead days) are stored now so later phases can inherit them. Saving
them does **not** activate checkout, holds, or email sending.

---

## 3. Roles and access

| Role | Phase 1 capabilities |
|---|---|
| **WordPress Administrator** | Full access: setup wizard, settings, add/edit/publish/delete books, manage authors and series, place shortcodes/blocks |
| **ConnectLibrary Librarian** | When this role is introduced by a later card, it will allow book add/edit access without full site admin access. Until then, use the WordPress Administrator role. |
| **Public visitor** | Browse the public catalog, search, filter, sort, view book detail pages. Phase 1 does not provide reservations or borrower self-service. |

> **Important:** Do not create real borrower or member accounts as part of Phase 1 setup.
> The plugin does not manage member personal data in Phase 1.

---

## 4. First-time setup workflow

### Step 1 — Install and activate

1. Upload `dist/connectlibrary.zip` via **Plugins > Add New > Upload Plugin**.
2. Click **Install Now**, then **Activate Plugin**.
3. Confirm activation: go to **Tools > ConnectLibrary** and verify the status page
   shows the plugin loaded successfully.

**Expected result:** The WordPress admin shows a **Library** menu in the left sidebar.
An admin notice appears: *"ConnectLibrary setup is not finished yet."*

### Step 2 — Open the setup wizard

1. Click **Start setup wizard** in the admin notice, **or** go to
   **Library > Setup Wizard**.
2. The wizard title reads **ConnectLibrary Setup Wizard** and shows a progress bar
   with eight steps: **Welcome → Pages → Defaults → Metadata → Scanner → Demo → ISBN → Finish**.

### Step 3 — Welcome step

Read the summary. Phase 1 status, access notes, and out-of-scope items are listed here.
Click **Continue**.

### Step 4 — Pages step (Catalog page)

Choose one option:

- **Create or reuse the standard Library Catalog page** — the wizard creates a WordPress
  page titled *Library Catalog* (slug: `library-catalog`) with the
  `[connectlibrary_catalog]` shortcode already in the page content.
- **Use an existing page** — select an existing page from the dropdown.

Click **Continue**.

**Expected result:** The catalog page setting is saved. You will see a confirmation notice:
*"Catalog page setting saved."*

> If no valid page is selected or created, the wizard shows: *"Please create or select a
> valid WordPress page."* Try again.

### Step 5 — Defaults step (Library defaults)

Configure practical defaults that later circulation features will reuse. These do not send
emails or enable checkout.

| Field | Label in wizard | Default |
|---|---|---|
| Loan period | Loan period days | 14 |
| Hold period | Hold period days | 14 |
| Reminder lead | Reminder days before due date | 3 |
| Contact email | Librarian notification email | *(blank)* |
| Default status | Default public availability wording | Available |

Click **Continue**.

### Step 6 — Metadata step

Set ISBN lookup preferences. No API key is required for Google Books or Open Library.

- **Provider order** — choose whether Google Books or Open Library is tried first.
- **Store imported covers in the WordPress Media Library** — check this to import cover
  images locally when ISBN lookup finds them.
- **Preferred language** — short code such as `en`.

Click **Continue**.

### Step 7 — Scanner step

Informational only. Explains USB/Bluetooth ISBN scanner use and notes that borrower cards
and circulation scanning are later Phase 2/3 workflows. Click **Continue**.

### Step 8 — Demo step

Optionally create safe sample book records for testing.

- **Skip demo books** (default) — no demo records are created.
- **Create safe sample books if they do not already exist** — creates three draft books
  titled *Demo Book: The Helpful Library*, *Demo Book: Sunday Stories*, and
  *Demo Book: Volunteers Guide*. These contain no borrower or member data and can be
  deleted after setup review.

Click **Continue**.

### Step 9 — ISBN step

If you have a book handy, enter its ISBN and continue. The wizard sends you to
**Library > Add New Book** with the ISBN prefilled so you can use the
**Metadata source/import details > ISBN metadata lookup** controls. You can also
click **Add a book manually** or continue without entering an ISBN.

### Step 10 — Finish step

Review the summary (catalog page ID, defaults saved). Use the buttons to:

- **View catalog page** — open the public catalog in a new browser tab.
- **Add Book** — go directly to the book add screen.
- **Settings** — open **Settings > ConnectLibrary**.
- **Run wizard again** — restart from the beginning if needed.

Click **Mark setup complete**.

**Expected result:** The setup prompt disappears from admin notices. The wizard remains
accessible at **Library > Setup Wizard** if you need to re-run it.

### Troubleshooting setup

| Symptom | Resolution |
|---|---|
| Admin notice does not appear | Go to Library > Setup Wizard directly |
| Wizard shows "You do not have permission" | You need the WordPress Administrator role |
| Catalog page not created | Use the Pages step again; confirm a valid page ID is shown on the Finish step |
| Public catalog page is blank | Confirm the page content contains `[connectlibrary_catalog]`; also see [Section 10](#10-troubleshooting) |

---

## 5. Add a book by ISBN lookup

ISBN lookup queries Google Books first, then Open Library as a fallback. Results are
**suggestions only** until you explicitly apply selected fields.

1. Go to **Library > Add New Book** (or click **Add Book** on any Library admin screen).
2. The book edit screen opens. You will see a **Title** field at the top and several
   metaboxes below.
3. Scroll to the **Metadata source/import details** metabox (near the bottom of the page).
4. Find the **ISBN metadata lookup** section.
5. Enter the ISBN in the **Lookup ISBN** field (ISBN-10 or ISBN-13; hyphens are accepted).
6. Click **Lookup metadata**.
7. The page reloads showing a results table labeled **Suggested value** for each field
   found (Title, Subtitle, Authors, Publisher, Publication date/year, Description,
   Page count, Language, Categories/subjects, Source attribution, Cover image if available).
8. Check the boxes next to the fields you want to apply. For the cover image, check
   **Cover image** and optionally **Replace existing cover** if a cover is already set.
9. Click **Apply selected lookup fields**.
10. The page reloads with the selected fields applied. Review every field before publishing.
11. Fill in any remaining fields manually (see Section 6 below for field details).
12. Set **Public catalog visibility** to **Public** (in the **Public notes and visibility**
    metabox) when the record is ready.
13. Click **Publish** (or **Update** if editing an existing book).
14. Click **View Book** to check the public detail page.

> **Librarian responsibility:** You are responsible for correcting metadata before making
> a book record Public. ISBN databases sometimes return incorrect or incomplete results.
> Leave the visibility set to **Hidden** until you have reviewed all fields.

---

## 6. Add or edit a book manually

Use manual entry when ISBN lookup is unavailable or returns incomplete results.

1. Go to **Library > Add New Book** (new record) or **Library > All Books** and click the
   book title (existing record).
2. Enter the book **Title** in the large title field at the top.
3. Fill in the metaboxes described below.

### Catalog details metabox

Fields in the **Catalog details** metabox:

| Field label | Notes |
|---|---|
| ISBN-10 | Optional 10-digit ISBN. Hyphens are cleaned when saved. |
| ISBN-13 | Preferred for ISBN lookup. |
| Subtitle | Shown with the book title in catalog views. |
| Publisher | |
| Publication date/year | Use a year (e.g. `2021`) or full date if known. |
| Language | Short label such as `English` or `en`. |
| Page count | |
| Age/reading level note | Edition-specific wording; use the Age/Reading Level taxonomy for browsable labels. |
| Reading level detail | Additional reading level information. |

The post content editor (WordPress standard) holds the book description.

### Cover image

Use the WordPress **Featured Image** panel (right sidebar, labeled **Book cover image**)
to set the cover. Click **Set book cover image** and upload from your computer or select
from the Media Library. The image is stored locally in WordPress.

To import a cover via ISBN lookup, see step 8 in Section 5.

### Location and item status (sidebar metabox)

| Field | Options / notes |
|---|---|
| Room | Free text, e.g. `Foyer` |
| Shelf | Free text, e.g. `A3` |
| Section | Free text, e.g. `Fiction` |
| Condition | New / Good / Fair / Poor |
| Item status | Active / Damaged / Lost / Retired |

### Public notes and visibility metabox

| Field | Notes |
|---|---|
| Public catalog visibility | **Public** — visible in catalog. **Hidden** — excluded from all public lists and search. |
| Librarian/pastoral recommended | Check to mark as recommended; shown as "Recommended by the librarian" on the detail page. |
| Public church note | Short note shown publicly, e.g. pickup context or suitability. |
| Content/advisory notes | Advisory notes; public rendering is decided by catalog configuration. |

### Librarian/internal notes metabox

| Field | Notes |
|---|---|
| Internal librarian notes | Private operational notes. Never shown in public catalog or REST responses. |

### Categories, tags, and age levels

Use the WordPress taxonomy panels on the right sidebar:

- **Book Categories** — structured browsing categories (e.g. `Bible Studies`, `Fiction`, `Kids`).
- **Book Tags** — freeform tags.
- **Age / Reading Levels** — structured age/reading level labels (e.g. `Children`, `Youth`, `Adult`).

### Publishing

- **Draft** — not visible publicly regardless of visibility setting.
- **Publish** — makes the post active. Public visibility depends on the **Public catalog
  visibility** field in the **Public notes and visibility** metabox.

> Keep uncertain or incomplete records in **Draft** status or set visibility to **Hidden**
> until you have reviewed and corrected all fields. If unsure, leave it hidden and ask the
> librarian lead.

---

## 7. Manage authors and series

Authors and series use **custom database tables**, not WordPress taxonomies. This means
they appear as custom selectors in the book edit screen, not as standard category panels.

### Authors

In the **Authors and series** metabox:

1. The **Authors** multi-select list shows all existing author records.
2. Hold Ctrl (Windows/Linux) or Command (Mac) to select multiple authors.
3. To add a new author not already in the list, type the name in **Add new author name**
   and save. A new author record is created and linked to this book.
4. The new author will appear in the list for future books.

> Author records are shared across all books. Editing an author record's display name
> updates it everywhere. If you notice a duplicate author (e.g. `C.S. Lewis` and
> `Lewis, C.S.`), ask the librarian lead to merge or correct the record.

### Series

1. Select the series from the **Primary series** dropdown. Choose **No series** if the
   book does not belong to a series.
2. To add a new series, type the name in **Add new series name** and save.
3. Optionally enter a **Series number/order** (examples: `1`, `2.5`, `Prequel`, `Book 4`).
   This appears as `Series Name · #2` on the public detail page.

> Like authors, series records are shared and backed by a custom table. Creating a series
> here makes it available for all future books.

---

## 8. Manage public catalog display

### Recommended catalog page

The setup wizard creates a page titled **Library Catalog** (slug: `/library-catalog`) with
the shortcode `[connectlibrary_catalog]` already placed. Visitors browse to this page to
see the full catalog.

To verify: go to **Settings > ConnectLibrary**, find the **Catalog page** field, and
confirm a page is selected. Click the page title link to preview it.

### Available shortcodes

All shortcodes are documented in detail at `docs/shortcodes-and-blocks.md`. Summary:

| Shortcode | Default behavior |
|---|---|
| `[connectlibrary_catalog]` | Full catalog, grid view, title sort, 12 per page, filters shown |
| `[connectlibrary_new_arrivals]` | 6 newest books, no toggle |
| `[connectlibrary_featured_books]` | Books in category slug `featured`, 6 per page |
| `[connectlibrary_category_books category="slug"]` | Books in a category slug |
| `[connectlibrary_author_books author="slug"]` | Books by an author slug |
| `[connectlibrary_series_books series="slug"]` | Books in a series slug |

Useful parameters for the base shortcode:

| Parameter | Example | Effect |
|---|---|---|
| `layout` | `layout="list"` | Show list view instead of grid |
| `limit` | `limit="6"` | Show only 6 books |
| `sort` | `sort="newest"` | Sort by newest first (`title` / `author` / `newest` / `availability`) |
| `category` | `category="fiction"` | Pre-filter to a category slug |
| `title` | `title="Fiction"` | Show a heading above the list |
| `show_view_toggle` | `show_view_toggle="false"` | Hide the Grid/List toggle |
| `show_filters` | `show_filters="false"` | Hide the search and filter form |

### Available Gutenberg block

In the block editor, search for **ConnectLibrary** in the block inserter. Insert the
**ConnectLibrary Catalog** block. On WordPress 6.7 and later you will see these preset
variations:

| Variation | Pre-set behavior |
|---|---|
| New Arrivals | Newest 6 books, toggle off |
| Featured Books | Category `featured`, 6 per page, toggle off |
| Category List | 6 per page, toggle off — set the category in the block sidebar |
| Kids / Youth | Age level `children`, 6 per page, toggle off |
| Bible Studies | Category `bible-studies`, 6 per page, toggle off |

After inserting, use the block sidebar to change category slug, limit, sort, and other
attributes. On WordPress older than 6.7, insert the plain ConnectLibrary Catalog block and
set attributes in the block sidebar, or use the shortcode instead.

### Placing a catalog embed on a page

To add a category list to an existing page:

1. Edit the page in WordPress.
2. Add a **Shortcode** block and enter, for example:
   ```
   [connectlibrary_category_books category="bible-studies" title="Bible Studies" limit="8"]
   ```
   Or insert a **ConnectLibrary Catalog** block variation from the block inserter.
3. Update the page and preview it.

### Search, filter, and sort behavior

When the catalog filter form is visible (default for `[connectlibrary_catalog]`), visitors
see these controls:

| Control | Label | Behavior |
|---|---|---|
| Text input | Search | Keyword search across title, author, and other fields |
| Text input | Category | Filter to a category slug |
| Text input | Tag | Filter to a tag slug |
| Text input | Age Level | Filter to an age-level slug |
| Select | Availability | Filter by status (All / Available / Reserved / Checked Out / Waitlist Available / Unavailable) |
| Text input | Author | Filter to an author slug |
| Text input | Series | Filter to a series slug |
| Select | Sort By | Title / Author / Newest / Availability |
| Button | Apply | Submit the filter form |

The Grid and List toggle buttons switch display layouts without reloading. Grid view shows
book covers in a card grid; list view shows a compact vertical list.

### Public catalog verification checklist

After setup, verify the catalog works:

- [ ] Navigate to the catalog page URL (e.g. `/library-catalog`). Books appear.
- [ ] A book with **Public catalog visibility = Hidden** does **not** appear in any list.
- [ ] Search by a known book title — it appears in results.
- [ ] Filter by a category slug — only matching books appear.
- [ ] Sort by **Newest** — most recently added book appears first.
- [ ] Click a book title — the detail page loads with the cover, author, series, status
  badge, and "Back to catalog" link.
- [ ] The detail page shows the correct availability label (**Available**, etc.).
- [ ] No borrower names, loan history, private notes, or internal IDs appear publicly.

---

## 9. Visibility, status, and privacy

### Visibility field

The **Public catalog visibility** field on each book record controls whether the book
appears publicly.

| Value | Effect |
|---|---|
| **Public** | Book appears in catalog, search, embeds, and detail pages |
| **Hidden** | Book is excluded from all public lists, search, and detail page output |

A book must also be **Published** (WordPress post status) to be visible publicly. Draft
books are never shown regardless of the visibility field.

Default: new books are set to **Public** visibility. Set **Hidden** before publishing when
the record is incomplete or under review.

### Public availability labels

The availability label shown on book cards and detail pages reflects the **Default public
availability wording** set in the wizard/settings, or the per-book status if set via a
later admin workflow. Phase 1 labels:

| Label | Meaning |
|---|---|
| Available | Book is on the shelf and ready to borrow (future Phase 2+ feature) |
| Reserved | Book is held for a borrower (future Phase 2+ feature) |
| Checked Out | Book is currently borrowed (future Phase 2+ feature) |
| Waitlist Available | A waitlist spot is open (future Phase 2+ feature) |
| Unavailable | Book is not available for any reason |

> **Phase 1 note:** Availability labels in Phase 1 are manually set defaults stored in
> settings. They do **not** reflect live checkout or reservation state — that requires
> Phase 2+ circulation workflows.

### Privacy rules

The following information must **never** appear in public catalog output or documentation
examples:

- Borrower names, emails, phone numbers, or addresses.
- Loan history or checkout records.
- Private librarian notes (the **Internal librarian notes** field).
- Library card tokens, QR codes, or raw internal database IDs.
- Audit log entries.

All documentation examples must use fictional or demo data (for example: *Demo Book: The
Helpful Library*, Author: *J. Demo*, Location: *Room A*).

---

## 10. Troubleshooting

### ISBN lookup finds no result or conflicts

- Confirm the ISBN digits are correct (no extra spaces or letters).
- Try the other ISBN format (ISBN-10 vs ISBN-13).
- If Google Books returns no result, Open Library is tried automatically.
- If both fail, you will see a warning notice. Proceed with **manual entry** for all fields.
- Do not publish the book until the title, author, and other key fields are verified.

### Cover import fails or image is missing

- Check the **Book cover image** panel in the right sidebar. If empty, no cover was set.
- To import via ISBN: re-run **Lookup metadata** in the **Metadata source/import details**
  metabox, check **Cover image**, and click **Apply selected lookup fields**.
- To upload manually: click **Set book cover image** in the **Book cover image** panel
  and upload a file from your computer.
- Confirm the WordPress Media Library has sufficient disk space and upload permissions.
- If the cover appears in the admin but not on the public site, clear any caching plugin
  cache and reload the detail page.

### Book does not appear in the public catalog

1. Open the book in the admin. Confirm **Post Status** is **Published** (not Draft).
2. Check the **Public notes and visibility** metabox. **Public catalog visibility** must
   be set to **Public**.
3. Check that no category/tag/age/availability filter is active in the URL that excludes
   this book.
4. If using a caching plugin, clear the cache.

### Search or filter shows unexpected results

- The search and filter controls accept **slugs** (lowercase, hyphenated), not display names.
  For example, use `bible-studies` not `Bible Studies` in the Category field.
- Author and series filters also use slugs. Find the slug on the book edit screen in the
  **Authors and series** metabox (the value stored in the custom table).
- Clear all filter inputs and click **Apply** to reset to the full catalog.

### Catalog shortcode page is blank or shows "No books found."

- Confirm at least one **Published** book has **Public catalog visibility = Public**.
- Check that the page content contains `[connectlibrary_catalog]` (or a block).
- If using a page-builder, confirm shortcodes are enabled for that builder.
- Try the shortcode in a plain WordPress paragraph block to rule out theme/plugin conflicts.

### Permission or capability error

- Administrators see the **Library** menu and all admin screens.
- If a user cannot see **Library > Add New Book**, they do not have Administrator role.
  The ConnectLibrary Librarian role is not yet active in Phase 1; use Administrator for now.
- If the setup wizard shows *"You do not have permission to run ConnectLibrary setup,"*
  the current user needs the WordPress Administrator role.

### Translation or accessibility reminder

When editing any public-facing content (book title, notes, categories), follow the
checklist in `docs/accessibility-i18n-checklist.md`. Key points:

- Book cover images need meaningful `alt` text (the plugin uses the book title by default).
- Status labels use visible text, not colour alone.
- If you add custom PHP or template output, use the `connectlibrary` text domain for all
  strings.

---

## 11. Quick-reference checklist

A one-page checklist for volunteer use. Print or keep open on a second screen.

### Adding a new book

- [ ] Go to **Library > Add New Book**.
- [ ] Enter or paste the book **Title**.
- [ ] In **Metadata source/import details**: enter the ISBN in **Lookup ISBN** and click
  **Lookup metadata**.
- [ ] Review the suggested fields. Check only the ones you have verified are correct.
- [ ] Click **Apply selected lookup fields**.
- [ ] Check **Book cover image** (sidebar): confirm a cover was imported, or click
  **Set book cover image** to upload one manually.
- [ ] Open **Authors and series**: confirm the correct author is selected. Add a new author
  if needed using **Add new author name**.
- [ ] Set series and series position if the book belongs to a series.
- [ ] Open **Catalog details**: verify ISBN-13/ISBN-10, Publisher, Publication date/year,
  Language, Page count.
- [ ] Open **Location and item status** (sidebar): enter Room, Shelf, Section. Set
  Condition and Item status.
- [ ] Assign **Book Categories**, **Book Tags**, and **Age / Reading Levels** from the
  right-sidebar taxonomy panels.
- [ ] Open **Public notes and visibility**: set **Public catalog visibility**:
  - **Hidden** if you need more review.
  - **Public** when the record is complete and verified.
- [ ] Click **Publish** (or **Update**).
- [ ] Click **View Book** to check the public detail page.
- [ ] Go to the catalog page and confirm the book appears (if visibility is Public).

### If something looks wrong

- [ ] Set **Public catalog visibility** to **Hidden** and click **Update** to hide the
  book immediately.
- [ ] Correct the problem fields.
- [ ] Set visibility back to **Public** and click **Update** when ready.
- [ ] If you are unsure, leave it **Hidden** and ask the librarian lead or site
  administrator before publishing.

---

*Phase 1 documentation. Offline/PWA support is Phase 5 and is intentionally not covered
here. Borrower/circulation/reservation/card/report/Sunday-dashboard workflows are later
phases and are not available in Phase 1.*

*See also: `docs/shortcodes-and-blocks.md` · `docs/accessibility-i18n-checklist.md` ·
`docs/development.md` · `docs/catalog-schema.md`*
