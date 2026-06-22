# ConnectLibrary Phase 1 — Staging Review Checklist

Use this checklist for Mike's final human review on staging after the Phase 1 plugin is installed.

Staging site: https://staging.connectcommunitychurch.ca
Plugin: ConnectLibrary 0.1.0
Milestone: PHASE COMPLETE: ConnectLibrary Phase 1

## 1. Basic staging health

- [ ] Staging homepage still loads normally.
- [ ] `/wp-admin/` login still works.
- [ ] Existing church pages, Events Calendar, giving links, and forms still behave normally.
- [ ] Site remains noindex / discourages search engines.

## 2. Plugin activation and admin surfaces

- [ ] ConnectLibrary appears as active in WordPress Plugins.
- [ ] ConnectLibrary admin/status screen loads without errors.
- [ ] Settings > ConnectLibrary loads.
- [ ] Settings sections appear: General/contact, Catalog display, Metadata lookup, Lending defaults, Advanced/privacy-safe defaults.
- [ ] Saving settings accepts valid values and rejects/sanitizes invalid values.
- [ ] First-run setup wizard loads for an administrator.
- [ ] Setup wizard can create/reuse the library catalog page idempotently.

## 3. Book catalog admin

- [ ] Book custom post type appears in wp-admin.
- [ ] A librarian/admin can create a draft book.
- [ ] Core taxonomy/meta fields are available: category, tag, age/reading level, authors, series, ISBN/source/cover metadata, public visibility, availability/status.
- [ ] Private/internal notes are visible only in admin and never on public output.
- [ ] ISBN metadata lookup can be tested with a safe sample ISBN, then manually edited before publishing.
- [ ] Imported/replaced cover images are stored in WordPress Media Library.

## 4. Public catalog page/shortcode/block

- [ ] Catalog shortcode/block renders a responsive grid by default.
- [ ] Visitor can switch grid/list view.
- [ ] Search/filter/sort controls render with labels.
- [ ] Filters work for search, category, tag, age/reading level, availability, author, series, and sort.
- [ ] Pagination preserves active filters/sort.
- [ ] Hidden/private books do not appear publicly.
- [ ] Public catalog never exposes borrower data, private notes, due dates, or waitlist position.

## 5. Public book detail

- [ ] Public book detail page renders title, metadata, cover, authors/series, categories/tags, visibility-safe availability/status.
- [ ] Hidden/private books are not publicly accessible through direct REST/detail URLs.
- [ ] No private notes or internal IDs are visible.

## 6. REST/API privacy smoke checks

- [ ] Public books API returns only visible/public-safe fields.
- [ ] Lookup lists for authors/series include only relationships attached to public visible books.
- [ ] Hidden/private-only authors/series do not leak into public lookup/filter lists.

## 7. Accessibility and translation-readiness spot checks

- [ ] Catalog controls have visible labels or screen-reader labels.
- [ ] View toggle indicates selected state (`aria-pressed` or equivalent).
- [ ] Status/filter messages are understandable for screen readers.
- [ ] Admin and public text is translation-ready where practical.

## 8. Explicit out-of-scope confirmation

Confirm these are not expected in Phase 1:

- [ ] No borrower accounts / My Library workflows yet.
- [ ] No checkout, return, renewal, reservation, hold, or waitlist workflows yet.
- [ ] No live due-date reminder emails yet.
- [ ] No library cards / QR codes / Sunday scanner dashboard yet.
- [ ] No reports/exports/audit-log browsing yet.
- [ ] No Offline/PWA functionality — remains Phase 5.

## 9. Final approval decision

- [ ] Approved to proceed with Phase 2 planning/build sequence.
- [ ] Approved to keep plugin installed on staging for continued testing.
- [ ] Changes requested before Phase 2 starts:

Notes:

-
