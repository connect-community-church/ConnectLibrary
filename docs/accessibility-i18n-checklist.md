# ConnectLibrary accessibility and i18n checklist

A short developer checklist for every ConnectLibrary UI card.
Apply before merging any card that adds or changes user-visible output.

## Translation-readiness

- [ ] Every user-facing PHP string uses a WordPress i18n function with the `connectlibrary` text domain: `__()`, `esc_html__()`, `esc_attr__()`, `esc_html_e()`, `esc_attr_e()`.
- [ ] Translated strings are escaped with the right function for context:
  - HTML text → `esc_html__()` / `esc_html_e()`
  - HTML attributes → `esc_attr__()` / `esc_attr_e()`
  - URLs → `esc_url()` (not translated; sanitize the surrounding label separately)
  - HTML with intentional markup → `wp_kses_post( __() )`
- [ ] Strings with placeholders use `sprintf()` / `printf()` with ordered placeholders (`%1$s`, `%2$s`) and carry a `/* translators: … */` comment immediately before the function call.
- [ ] No string concatenation that would prevent translation of a complete natural-language phrase.
- [ ] JavaScript strings visible to users are exposed via `wp_localize_script()` data or `wp.i18n` (`__` from `@wordpress/i18n`), and `wp_set_script_translations()` is called for the script handle.
- [ ] REST/API error and validation messages returned to the UI use `__( '…', 'connectlibrary' )`.
- [ ] Date and number output uses WordPress helpers (`date_i18n()`, `number_format_i18n()`) rather than raw PHP `date()` or `number_format()` where the value is user-facing.
- [ ] `load_plugin_textdomain()` is called on the `init` action (already wired in `Plugin::load_textdomain()`; confirm it is not removed).

## Semantic HTML and keyboard access

- [ ] Buttons that trigger actions use `<button type="button">` (or `type="submit"` in forms); links that navigate use `<a href="…">`. Never swap them.
- [ ] Every form field has an explicit `<label for="…">` or an `aria-label` / `aria-labelledby` accessible name.
- [ ] Icon-only controls carry an `aria-label` or `<span class="screen-reader-text">` visible only to assistive technologies.
- [ ] Tables that present data use `<thead>` with `<th scope="col">` column headers and/or `<th scope="row">` row headers. Do not use `role="presentation"` on a table that conveys data.
- [ ] Heading hierarchy is logical within ConnectLibrary output: page title `<h1>`, section headings `<h2>`, sub-items `<h3>`. Never skip levels for styling purposes.
- [ ] Larger ConnectLibrary front-end regions use a landmark element (`<nav>`, `<main>`, `<section>`) or `role="region"` with an `aria-label`, so screen-reader users can jump to them.
- [ ] Navigation lists (breadcrumbs, pagination, step indicators) are wrapped in `<nav aria-label="…">`.

## Focus and visual feedback

- [ ] All interactive elements (links, buttons, inputs) receive a visible focus indicator. Do not use `outline: none` or `outline: 0` without providing an accessible replacement.
- [ ] Status and availability labels always include visible text, not color alone.
- [ ] Cover images carry meaningful `alt` text based on book title; purely decorative covers (context-dependent) use `alt=""`.
- [ ] Success, error, and validation messages are in markup that assistive technologies read: WordPress admin notices (`.notice .notice-success / .notice-error`) in admin, or an `aria-live` region on the front end.

## Admin patterns

- [ ] WordPress admin notices use the standard `.notice.notice-info / .notice-success / .notice-warning / .notice-error` structure with a `<p>` inside.
- [ ] Settings API fields registered with `add_settings_field()` have their label wired via the `add_settings_field` `$title` parameter or an explicit `<label for="…">` in the callback.
- [ ] `wp_die()` messages are escaped with `esc_html()` or `esc_html__()`.

## Phase 2 quality gate

For borrower, guardian, circulation, reservations, due-reminder, settings/defaults, and audit-log work, also run the Phase 2 privacy/security/accessibility/i18n gate:

```sh
composer phase2:quality-gate
```

The detailed Phase 2 plan lives at `docs/phase-2-privacy-security-accessibility-i18n-test-plan.md`. It extends this checklist with role-based privacy boundaries, WordPress capability/nonce/REST/object-authorization checks, CSV formula safety, prepared SQL checks, synthetic .test fixtures, live-region/date-text expectations, translation readiness for JavaScript/email strings, manual QA, and known pending gaps. Offline/PWA remains Phase 5 and is not part of the Phase 2 gate.

## Out of scope (Phase 1)

The following do not need to be addressed until the relevant Phase card:

- Full WCAG 2.1 AA certification or automated axe/Playwright audit.
- Offline/PWA status regions (Phase 5).
- Borrower/circulation ARIA live regions (Phase 2).
- French or other translation `.po`/`.mo` files (post-launch).
