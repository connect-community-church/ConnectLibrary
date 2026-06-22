# Phase 2 privacy, security, accessibility, and i18n QA plan

This plan is the quality gate for Phase 2 borrower, guardian, circulation, reservations, reminders, settings/defaults, and audit-log work in ConnectLibrary. It uses synthetic fixtures only, is safe for local/CI review, and does not read church borrower/member data, send real emails, or deploy to live/staging WordPress.

Offline/PWA support remains Phase 5 only. Do not add service workers, manifests, offline cache, installable PWA prompts, sync queues, conflict resolution, cache-retention UI, or offline audit sync as part of this Phase 2 plan or gate.

## Roles and required workflows

Validate each Phase 2 workflow from the perspective of these roles:

| Role | Expected access | Must not access |
| --- | --- | --- |
| Public/guest visitor | Public catalog, public book detail, and intentionally exposed reservation request forms. | Borrower PII, checkout history, guardian/child relationships, admin-only circulation actions, audit events, settings, raw tokens. |
| Borrower with a WordPress account | Their own My Library loans, holds, waitlist position when exposed, renewal/cancel actions permitted by policy. | Other borrower records, guardian-only child records, audit internals, admin notes, settings. |
| Guardian | Their own record plus linked child borrower self-service records where the guardian relationship authorizes access. | Unlinked child/adult borrower records, librarian-only private notes, raw guest-token hashes. |
| Librarian | Circulation dashboard actions: checkout, return, renew, hold pickup/expiry, borrower lookup needed for library work, audit visibility allowed by capability. | WordPress site administration outside library capabilities; unauthorized settings changes. |
| Admin | Plugin setup/settings/defaults, librarian management, data-retention choices, and review of audit/security gates. | Unapproved live deployment or destructive deletion without explicit approval. |
| Unauthorized user | No protected REST/admin/self-service data; receives sanitized errors and redirect/permission denial. | Any private borrower, guardian, circulation, audit, token, settings, or export data. |

Use .test addresses and clearly fake names in all examples, for example `alex.borrower@example.test`, `guardian.one@example.test`, and `public.guest@example.test`.

## Privacy and security boundaries

1. Borrower PII is private by default: name, email, phone, address, notes, guardian links, guest access tokens, current loans, historical loans, holds, waitlists, reminder state, and audit details must only be shown to an authorized actor.
2. Public catalog data must not leak borrower names, item-level private notes, checkout actors, reminder recipients, token hashes, raw provider payloads, or admin settings.
3. Guardian access is object-specific: a guardian may only see a child borrower when the stored relationship authorizes that link.
4. Guest secure-link access must be token-scoped, expiration-aware, hash-only at rest, and limited to the exact borrower/action the token authorizes.
5. REST endpoints must use permission callbacks and object authorization, not only route registration or client-side hiding.
6. Admin actions must verify WordPress capabilities and nonces before state changes.
7. Exports and audit views must redact secrets and internal token material. CSV formula safety is required before any CSV/spreadsheet export: prefix or otherwise neutralize cells beginning with `=`, `+`, `-`, or `@`.
8. SQL reads/writes must use `$wpdb->prepare()` or safe insert/update helpers with explicit formats; no interpolated user input in SQL.
9. All inbound request values must be sanitized and validated by type, enum, date, and object ownership before use.
10. All outbound UI strings, attributes, URLs, and HTML fragments must be escaped for context.
11. Audit events should record high-signal actions without storing unnecessary PII, raw tokens, message bodies, or full export payloads.

## WordPress capability, nonce, REST, and object-authorization checks

For every Phase 2 admin, REST, AJAX, form, or self-service action, review:

- Capability: `current_user_can()` or the central ConnectLibrary capability helper is checked for librarian/admin operations.
- Nonce: form handlers use `wp_nonce_field()` plus `check_admin_referer()` or REST/AJAX equivalent verification for state changes.
- REST: every route has a `permission_callback` that denies by default and checks both role/capability and the specific object being requested.
- Object authorization: borrower ID, loan ID, hold ID, waitlist ID, audit event, token, and child/guardian links are checked server-side.
- Failure mode: unauthorized requests return sanitized `WP_Error`/REST responses and do not reveal whether another private object exists beyond what the actor may know.
- Regression tests: each protected path includes at least one authorized and one unauthorized synthetic fixture.

## Escaping, sanitization, CSV, and prepared SQL

Manual review and static checks must cover:

- `sanitize_text_field()`, `sanitize_email()`, `sanitize_key()`, `absint()`, date validation, and enum allow-lists for request input.
- `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`, and translated escaping helpers for output.
- `wp_json_encode()` for JSON in HTML contexts and no raw JSON embedded without escaping.
- `$wpdb->prepare()` for SQL containing request-derived values; `$wpdb->insert()`/`update()` formats for writes.
- CSV formula safety before any CSV export, even when values came from trusted-looking borrower fields.
- No raw borrower PII in logs, cron output, test snapshots, review comments, or package metadata.

## Accessibility checklist

Keyboard, focus, and screen-reader review must include:

- All controls reachable by keyboard in a logical order; no mouse-only checkout, return, renew, hold, waitlist, reminder, or borrower actions.
- Visible focus indicators on links, buttons, form controls, tabs, filters, and modal/drawer controls.
- Forms have explicit labels, help text, validation messages, and error summaries tied to fields where practical.
- Buttons use `<button>` for actions and links use `<a>` for navigation.
- Live regions announce async state changes such as hold placed, renewal failed, checkout complete, due reminder queued, or borrower lookup errors.
- Data tables use captions or headings, `<thead>`, `<th scope="col">`, row context, and text alternatives for statuses.
- Date text is human-readable and unambiguous, for example “Due June 28, 2026” instead of only `2026-06-28`.
- Color is never the only indicator for availability, overdue state, errors, or role restrictions.
- WordPress admin notices use standard `.notice` markup; front-end notices use appropriate `aria-live` behavior.

## Internationalization checklist

Translation readiness must include:

- PHP user-facing strings use the `connectlibrary` text domain through WordPress i18n functions.
- Placeholder strings use ordered placeholders and translator comments where context is not obvious.
- JavaScript-visible strings are passed through localized script data or `@wordpress/i18n`, with script translations wired when a bundled script is present.
- Email subject/body strings are translatable and assembled without breaking natural-language phrase order.
- Dates and numbers use localized WordPress helpers such as `date_i18n()` and `number_format_i18n()` for user-facing text.
- Plurals use `_n()`/`_nx()` where counts appear.
- REST/API validation messages intended for users are translatable and escaped at display.
- Do not add machine translation output, `.po`, or `.mo` promises in Phase 2 unless a later card explicitly scopes them.

## Synthetic fixtures only

Use fixtures like these in tests, screenshots, and manual QA notes:

- Borrower: `Alex Example`, `alex.borrower@example.test`.
- Guardian: `Jordan Guardian`, `guardian.one@example.test`.
- Child borrower: `Taylor Child`, `taylor.child@example.test`.
- Librarian: `Casey Librarian`, `casey.librarian@example.test`.
- Item: `Synthetic Book Title`, barcode `TEST-0001`.

Never paste real church member names, emails, phone numbers, addresses, circulation history, or screenshots into tests, snapshots, comments, or docs.

## Manual QA checklist

Before Phase 2 sign-off, verify on local/staging with synthetic data:

- Public/guest catalog and reservation request paths expose no borrower PII.
- Borrower self-service shows only the signed-in borrower or valid guest-token borrower.
- Guardian can view linked child borrower state and cannot view an unlinked child/adult borrower.
- Librarian can perform checkout, return, renew, hold, waitlist, reminder, and borrower lookup actions with successful audit events.
- Unauthorized users receive denial for borrower, circulation, audit, settings, and export routes.
- Admin settings/default changes require capability and nonce and produce expected audit/default behavior.
- Every form has labels, useful validation messages, keyboard submit/cancel paths, and visible focus.
- Async status changes are announced or visible in notices/live regions.
- User-facing dates, counts, email strings, and REST errors are translation-ready.
- Review no Offline/PWA assets or behavior were introduced.

## Automated and static checks

Run from the repository root:

```sh
composer install
composer phase2:quality-gate
composer lint
composer phpcs
composer test
composer build:zip
```

`composer check` includes the Phase 2 quality gate along with lint, PHPCS, PHPUnit, and ZIP packaging. The quality gate is intentionally deterministic: it checks this plan, linked docs, composer wiring, and high-signal code patterns that should exist before review. It is not a replacement for manual accessibility testing, a browser-based audit, or human code review.

## Known pending gaps to track in build/review cards

- Browser-based axe/Playwright coverage is not required by this micro-slice and can be scoped later.
- Full WCAG 2.1 AA certification is not promised by this plan.
- French or other translation files are post-launch unless separately approved.
- Real SMTP/email deliverability, SMS, and external notification integrations are out of scope for this local gate.
- Live church deployment is not approved by this plan.
- Offline/PWA remains Phase 5 only.
