# ConnectLibrary

ConnectLibrary is the WordPress plugin foundation for Connect Community Church library catalog and circulation work.

This repository currently contains Phase 1 foundation code only. It is installable as a WordPress plugin skeleton so future catalog, admin, schema, REST, and UI tasks can build on a safe base. It is not ready for live church catalog or circulation use.

Offline/PWA support is Phase 5 and is intentionally out of scope for this plugin skeleton task.

## Requirements

- WordPress 6.5 or newer.
- PHP 8.1 or newer.
- Bash plus either `zip` or `python3` for packaging.
- Node/npm is optional, but `package.json` includes a convenience packaging script.

The WordPress/PHP version baseline is a reasonable modern starting point and should be confirmed against the final Bluehost/staging environment before production use.

## Local setup

1. Clone the repository.
2. Install development dependencies when running checks locally:

   ```sh
   composer install
   ```

3. From the repository root, create the plugin ZIP:

   ```sh
   bash ./bin/build-zip.sh
   ```

   or:

   ```sh
   npm run build:zip
   ```

4. The package is written to `dist/connectlibrary.zip`.

## Development checks

The repository includes a CI-ready PHP quality gate for future feature work:

```sh
composer install
composer lint
composer phpcs
composer test
composer build:zip
```

`composer phase2:quality-gate` runs the deterministic Phase 2 privacy/security/accessibility/i18n gate described in `docs/phase-2-privacy-security-accessibility-i18n-test-plan.md`.

`composer check` runs the local lint, WordPress coding standards, Phase 2 quality gate, PHPUnit smoke tests, and ZIP package verification in sequence. These checks use synthetic test stubs only; they do not deploy to live/staging WordPress, do not read church borrower/member data, and do not call external metadata APIs.

See `docs/development.md` for details about each command and the GitHub Actions workflow.

## Librarian and admin documentation

- `docs/phase-1-librarian-admin-workflows.md` — step-by-step workflows for volunteer
  librarians and WordPress administrators: setup wizard, adding books by ISBN or manually,
  managing authors/series, placing catalog shortcodes/blocks, and troubleshooting.
- `docs/phase-1-staging-review-checklist.md` — manual review checklist for the Phase 1
  staging sign-off before Phase 2 work begins.
- `docs/phase-2-dependency-map-rollout-sequence.md` — Phase 2 build card dependency map,
  rollout waves, safe parallelism guidance, review gate rules, privacy/security boundaries,
  and out-of-scope items (Build 12, t_4f7e26f2).
- `docs/phase-2-privacy-security-accessibility-i18n-test-plan.md` — Phase 2 privacy,
  security, accessibility, i18n, synthetic fixture, manual QA, and static quality-gate
  plan for borrower/circulation work.
- `docs/phase-3-dependency-rollout-qa.md` — Phase 3 dependency map, rollout sequence,
  QA/staging checklist, privacy/security guardrails, scanner/keyboard/accessibility
  expectations, demo-data matrix, and build/review verification rules (Build 12,
  t_4fdb59a1).
- `docs/shortcodes-and-blocks.md` — shortcode and Gutenberg block reference.
- `docs/accessibility-i18n-checklist.md` — accessibility and translation-readiness checklist.
- `docs/catalog-schema.md` — catalog database schema reference.

## ZIP install workflow

1. Open a local or staging WordPress admin dashboard.
2. Go to Plugins > Add New > Upload Plugin.
3. Upload `dist/connectlibrary.zip`.
4. Install and activate the plugin.
5. Confirm Tools > ConnectLibrary is visible to an administrator.

Do not deploy this Phase 1 skeleton to the live church site unless a later task explicitly approves that deployment.

## Activation/deactivation smoke test

On a local or staging WordPress site:

1. Activate ConnectLibrary from the Plugins screen.
2. Confirm activation succeeds without fatal errors.
3. Open Tools > ConnectLibrary and confirm the status page says the plugin loaded successfully.
4. Deactivate ConnectLibrary from the Plugins screen.
5. Confirm deactivation succeeds and does not delete data.

Activation currently stores only the safe `connectlibrary_version` option. It does not create catalog data, borrower/member records, sample books, pages, emails, external API calls, or schema-heavy migrations. Deactivation intentionally does not delete plugin data.

## Packaging details

`bash ./bin/build-zip.sh` stages runtime files under a single `connectlibrary/` top-level folder and writes `dist/connectlibrary.zip`.

The package includes:

- `connectlibrary.php`
- `README.md`
- `includes/`
- `assets/`
- `languages/`

The package excludes development-only files such as `.git/`, `node_modules/`, dependency caches, local environment files, logs, previous ZIPs, and temporary build output.

## Current scope

Phase 1 foundation included:

- WordPress plugin bootstrap, namespaced PHP skeleton, activation/deactivation hooks.
- Administrator-only Tools status screen and `Settings > ConnectLibrary`.
- Setup wizard (`Library > Setup Wizard`) for catalog page creation and library defaults.
- Book custom post type with catalog metaboxes, taxonomies (categories, tags, age/reading levels), and custom author/series database tables.
- ISBN metadata lookup via Google Books and Open Library; cover image import to the WordPress Media Library.
- Public catalog shortcode `[connectlibrary_catalog]` and Gutenberg block with grid/list view, filter form (search, category, tag, age level, availability, author, series, sort), and pagination.
- Preset shortcodes: `[connectlibrary_new_arrivals]`, `[connectlibrary_featured_books]`, `[connectlibrary_category_books]`, `[connectlibrary_author_books]`, `[connectlibrary_series_books]`.
- Public book detail pages with cover, metadata, availability label, and author/series display.
- REST endpoint backing catalog queries and book detail pages.
- Reproducible `dist/connectlibrary.zip` package command.

Not included in Phase 1:

- Borrower accounts, manual borrower records, child/guardian workflows, My Library borrower self-service, guest borrower secure links, reservations, holds, waitlists, checkout, return, renewals, due-date reminder emails, audit logging, and admin settings/defaults integration (Phase 2).
- Library cards, QR codes, barcodes, or Sunday scanner/circulation dashboard (Phase 3).
- Reports, exports, or audit-log browsing (Phase 3–4).
- Offline/PWA functionality (Phase 5).
- Live church deployment (requires explicit approval from the project steward).
