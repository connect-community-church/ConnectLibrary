# Phase 3 librarian accessibility, keyboard, scanner, and privacy checklist

This checklist covers the Phase 3 librarian-facing admin surfaces: dashboard, quick circulation, ISBN add/lookup, borrower/card scan, printable cards/sheets, lost-card replacement, reports/exports, audit/history, and safe overrides.

Offline/PWA remains Phase 5 and is out of scope for this pass. Do not describe service-worker, offline sync, install prompts, or mobile PWA behavior as available in Phase 3.

## Sunday quick start

1. Open Library > Dashboard and enable Sunday mode if volunteers need larger tap targets and queue-first layout.
2. Put keyboard focus in the visible Quick Lookup field for the task at hand: borrower card/token, borrower name, or item ISBN/barcode.
3. Scan or type into that focused field. USB/Bluetooth scanners are treated like keyboards and may send Enter, CR, LF, or Tab suffixes; the field value is trimmed before lookup.
4. Review the result on the circulation screen before choosing any checkout, return, renewal, lost-card, export, correction, or override action.
5. For corrective actions, type a reason and deliberately check the confirmation box. Scanner Enter alone must not confirm dangerous actions.

## Keyboard-only workflow matrix

| Surface | Keyboard path | Expected accessibility/privacy behavior |
| --- | --- | --- |
| Dashboard quick lookup | Tab to the visible lookup field, scan/type, Enter on the Lookup/Search/Find button. | Forms use explicit labels or screen-reader labels and route to Quick Circulation without changing loan/card state. |
| Quick circulation borrower lookup | Tab to Scan card or item, borrower token, borrower name, or copy search; submit with the visible button. | Dynamic notices render in a polite status region; IDs/tokens are not exposed as labels. |
| Quick circulation actions | After borrower and item are selected, Tab to Checkout/Return/Renew controls and read any visible status text before submit. | Checkout/return/renew require deliberate button activation; scanner input only resolves lookup context. |
| ISBN add/lookup | On Add/Edit Book or Setup Wizard, focus the ISBN field, scan/type, then activate Lookup metadata or continue. | ISBN input is normalized and suggestions must be explicitly reviewed/applied. |
| Borrower/card scan and lost-card replacement | From Borrowers, Tab to card lifecycle controls, type replacement reason/note, check lost-card confirmation, then submit. | Old cards stop only after explicit confirmation; no raw card token is shown in labels or examples. |
| Printable cards/sheets | Tab through search, layout, size, orientation, cut guides, borrower checkboxes, then print buttons. | Cards contain opaque QR/barcode data only; no contact details, guardian details, notes, or borrowing history. |
| Reports/exports | Tab through report type, date/status/search filters, Apply, then Export CSV when needed. | Export is deliberate, audit-logged as metadata, and should not contain raw card tokens or private borrower history beyond the selected report columns. |
| Audit/history | Tab through date/action/source/correlation/search filters and pagination. | Filters are normalized; rendered event summaries use privacy-safe labels and metadata redaction. |
| Safe overrides | Tab to reason/date/status fields, read warning text, check confirmation, then submit. | Override confirmations are visible and required; scanner Enter should stay in the field flow and must not be enough by itself. |

## Scanner setup and troubleshooting

- Use a USB or Bluetooth keyboard-wedge scanner configured to type into the focused field.
- Recommended suffix: Enter, CR, LF, Tab, or no suffix. Phase 3 trims trailing CR/LF/TAB/space from explicit scanner/manual fields.
- Avoid scanner prefixes that add branch, volunteer, borrower, or device identifiers; these may create lookup failures and privacy risk.
- If scans do not work, click into the intended field and scan into a plain text editor to confirm the scanner types the expected ISBN, barcode, or opaque card token.
- If a scan opens the wrong result, clear the field and type the value manually. Do not keep scanning repeatedly into a destructive or corrective form.
- There is no hidden global key listener in Phase 3; scanning works only while focus is in a visible field.

## Card replacement guidance

- Treat a reported lost card as sensitive. Search for the borrower by name or scan the current card only if present.
- Use the Borrowers card action controls, enter a reason such as `Lost card`, add a short internal note if needed, check the lost-card confirmation box, then replace.
- Do not copy raw card tokens into notes, labels, screenshots, examples, logs, or chat messages.
- Reprint or replace cards only from protected librarian screens.

## Report/export privacy notes

- Export only the report needed for the service task.
- Do not publish borrower IDs, WordPress user IDs, guardian/contact details, raw card tokens, or borrower history outside protected librarian workflows.
- Audit/export evidence should describe filter choices, report names, row counts, and metadata, not raw borrower/card secrets.
- Delete local CSV copies after service if they are no longer needed and church policy permits deletion.

## Override safety

- Safe overrides are for librarian corrections, not routine circulation speedups.
- A scan may fill a date, reason, barcode, or correlation field, but the override must still require a deliberate confirmation checkbox and submit button.
- Always enter a human-readable reason that does not include raw card tokens, borrower IDs, guardian/contact details, or private history.
- Verify visible status text after an override and use Audit/History to confirm privacy-safe logging.

## End-of-service checklist

- Clear scanner/search fields and close protected admin tabs.
- Confirm no borrower/card tokens are left visible in shared notes, labels, or documents.
- Put exported CSVs and printed card sheets in their approved church location or destroy extras.
- Return scanners to their usual suffix/profile if it was changed for troubleshooting.
- Leave Offline/PWA expectations for Phase 5; do not promise offline circulation for Phase 3.

## Reviewer verification checklist

- Confirm `includes/Support/ScannerInput.php` or equivalent exists and is used from explicit request/input fields only.
- Confirm CR/LF/TAB suffixes and manual whitespace are trimmed by automated tests.
- Confirm dashboard, circulation, ISBN setup/add, borrower/card, print-card, reports/export, audit/history, and override paths use field-scoped normalization or an intentionally documented equivalent.
- Confirm touched controls have labels or accessible names, status/help text is visible and not colour-only, and dynamic notices use WordPress notices or `role="status"` / `aria-live` where practical.
- Confirm scanner input alone does not trigger checkout, return, lost-card replacement, export, correction, or override without a deliberate button/confirmation path.
- Confirm documentation and examples avoid raw borrower/card tokens, borrower IDs, WP user IDs, guardian/contact details, and private borrower history.
- Confirm Offline/PWA remains Phase 5/out of scope.
