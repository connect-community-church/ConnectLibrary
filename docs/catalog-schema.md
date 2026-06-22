# ConnectLibrary Phase 1 Catalog Schema

This document describes the catalog-only custom tables created by `ConnectLibrary\Database\Schema` during activation. The schema uses the active WordPress database prefix and stores its installed schema version in the `connectlibrary_schema_version` option.

## Tables

- `connectlibrary_authors`: custom-table-backed author records for searching, sorting, external IDs, and future author management. Authors are not represented only as taxonomies.
- `connectlibrary_book_authors`: ordered many-to-many links between WordPress book post IDs and author records. The default role is `author`, with room for future illustrator/editor roles.
- `connectlibrary_series`: custom-table-backed series records for searching, sorting, descriptions, and external IDs. Series are not represented only as taxonomies.
- `connectlibrary_book_series`: ordered links between WordPress book post IDs and series records. It stores both display positions such as `Prequel` and numeric sort positions when available.
- `connectlibrary_copies`: physical copy/item records. Phase 1 can use one copy per title, but the table supports multiple copies later. Barcode and private notes are operational/librarian data and are not public API output in this card.
- `connectlibrary_book_metadata`: structured title metadata linked to the WordPress book post ID, including ISBNs, publisher/date/language, reading guidance, public notes, recommendation flag, cover attachment ID, metadata source, and raw provider payload provenance.
- `connectlibrary_import_sources`: catalog-only metadata/source lookup history for later ISBN import work. This card does not call external providers.

## Scope boundaries

The schema intentionally does not create borrower, guardian, reservation, waitlist, loan, borrower-card, audit-log, offline cache, sync queue, or PWA tables. Deactivation does not drop tables or delete catalog data.

## Central values

Catalog-safe value lists live in `ConnectLibrary\Support\Statuses`:

- item status: `active`, `damaged`, `lost`, `retired`
- condition status: `new`, `good`, `fair`, `poor`
- metadata source: `manual`, `google_books`, `open_library`, `unknown`
- public availability: `available`, `reserved`, `checked_out`, `waitlist_available`, `unavailable`
- public visibility: `public`, `hidden`

## Public availability model

Phase 1 stores title-level public availability in WordPress post meta on the Book post type:

- `_connectlibrary_public_availability`: fixed manual status for public catalog display. Invalid or unknown values are normalized to `unavailable`.
- `_connectlibrary_public_visibility`: `public` or `hidden`. Hidden titles are excluded from public book queries by default and resolve to the internal public status `hidden` when checked directly.

`ConnectLibrary\Catalog\Availability` is the canonical resolver for public catalog responses. It returns only:

```json
{"status":"available","label":"Available","request_action":"reserve"}
```

The resolver and REST field intentionally do not expose copy IDs, borrower identity, child/guardian details, due dates, waitlist position, private notes, checkout history, or audit data. Query integration supports `connectlibrary_availability` filters and `orderby=availability`, ranking public titles as available, waitlist available, reserved, checked out, then unavailable.
