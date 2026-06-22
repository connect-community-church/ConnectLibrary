# ConnectLibrary Phase 3 — Dependency map, rollout sequence, and QA/staging plan

Spec card: `t_fbd03450` · Build card: `t_4fdb59a1` · Review card: `t_ac8b4c7f`

---

## 1. Development start gate

Phase 3 planning and development may begin after **all Phase 3 Spec cards are complete** and the post-spec estimate card (`t_78d0040d`) has notified Mike with the Phase 3 estimate.

Phase 3 development is **not gated on separate Phase 2 human approval**. Phase 2 is considered done enough for Phase 3 planning and development to proceed. The only remaining pre-development gate is the post-spec estimate/start-development card. Mike should be notified for:

1. The Phase 3 estimate.
2. Any true intervention-needed blocker.
3. Phase 3 completion.

No other Mike decisions are required before development unless the estimate discovers a real blocker.

---

## 2. Phase 1 / Phase 2 foundations required before Phase 3 Build lane

Phase 3 assumes these foundations already exist or are sufficiently implemented:

- Book CPT/admin menu and public book/copy model.
- ISBN import, metadata provider settings, local cover import, and librarian book editing.
- Structured copy/location/status/condition concepts or compatible schema migration path.
- Borrower records for WordPress users, manual/guest borrowers, and child borrowers linked to parent/guardian.
- Borrower card/token table or compatible Phase 3 migration path.
- Reservations, guest request approval, waitlists, automatic hold promotion, loans, renewals, due dates, and email foundations.
- Custom Librarian capability/role plus WordPress admin nonce/capability checks.
- Audit/schema infrastructure that can support circulation/admin/settings/override history.
- Test harness capable of running PHP lint/unit/integration checks for changed areas.

---

## 3. Phase 3 Spec / Build dependency pairs

| # | Spec card | Build card | Primary output | Depends on |
|---|:---:|:---:|---|---|
| 01 Dashboard/Sunday shell | `t_99110596` | `t_ae67a161` | Librarian dashboard, Sunday mode shell, deep links | Phase 1/2 admin, repositories/services, settings, capabilities, audit |
| 02 Fast circulation screen | `t_82fb5a75` | `t_6001f5b0` | Checkout/return/renewal/reservation pickup | 01; Phase 2 borrower/reservation/waitlist/loan rules; 03/04/05/07/09/10 integration points |
| 03 ISBN scanner add-book | `t_66ca8435` | `t_cdfe6621` | Scanner-friendly ISBN add workflow | Phase 1 ISBN/metadata/book admin; 01 dashboard links; 09 audit |
| 04 Borrower/card scan workflow | `t_17776c1b` | `t_71ae2e29` | Active card scan, inactive-card safety, borrower lookup context | Phase 2 borrowers/cards; 05 card token model; 07 lifecycle; 02 circulation; 09 audit |
| 05 QR/barcode library card generation | `t_1a29f6ba` | `t_f7ee0dbe` | Secure token generation/rendering | Phase 2 borrower model; capability checks; 04 scan; 06 print; 07 replacement; 09 audit |
| 06 Printable cards/sheets | `t_5f27cc49` | `t_de6d0687` | Individual cards and grouped printable sheets | 05 tokens; 04 scan; 07 replacement; borrower child/guardian model; 09 audit |
| 07 Lost-card lifecycle | `t_ace1526b` | `t_82f9d77e` | Disable lost token, issue replacement, preserve history | 05 tokens; 04 scan; 06 print; 09 audit |
| 08 Operational reports | `t_46c3f204` | `t_505df469` | Loans/due/reservation/waitlist/inventory/borrower-history/high-demand reports | Phase 1/2 data; 02 circulation events; 09 audit/export privacy; 11 accessibility |
| 09 Expanded audit/history | `t_845f260e` | `t_c87b9b95` | Staff audit browser and history views | All data-changing Phase 3 items; Phase 1/2 audit base; 10 overrides |
| 10 Safe overrides/exceptions | `t_5e20de7c` | `t_ea216c15` | Due-date/copy/hold/checkout-return corrections | 02 circulation; 08 reports; 09 audit; librarian capabilities |
| 11 Accessibility/i18n/docs | `t_bbfe348c` | `t_38b05ce6` | Keyboard/scanner focus, a11y labels/status/focus, translation readiness, docs | Cross-cutting all Phase 3 screens |
| 12 Dependency/rollout/QA | `t_fbd03450` | `t_4fdb59a1` | This plan plus QA/staging checklist | All Phase 3 specs; post-spec estimate before development start |

---

## 4. Dependency lanes and safe parallelism

### Lane A — Shell and navigation

1. Build 01 (`t_ae67a161`) — dashboard/Sunday shell. Must complete first; all other screens link into it.
2. Build 12 (`t_4fdb59a1`) — documentation artifact; may run **in parallel with Build 01** after estimate completion.
3. Build 11 (`t_38b05ce6`) — accessibility/docs baseline; may begin once screen structure exists, then continue as cross-cutting follow-up. Final acceptance requires all Phase 3 screens to be present.

### Lane B — Core Sunday circulation (sequential within lane)

1. Build 05 (`t_f7ee0dbe`) — secure QR/barcode token generation/rendering. Must establish token model before print or replacement.
2. Build 04 (`t_71ae2e29`) — borrower/card scan workflow. May run in parallel with portions of Build 02 if the borrower card token contract is stable.
3. Build 02 (`t_6001f5b0`) — fast checkout/return/renewal/reservation pickup. Requires dashboard shell and Phase 2 loan/reservation services.
4. Build 07 (`t_82f9d77e`) — lost-card lifecycle. Must wait for card generation/scan semantics verified by scan tests.

Build 03 (`t_cdfe6621`) ISBN scanner add-book may run in **safe parallel with Lane B** (after the dashboard shell exists), because it depends on Phase 1 ISBN foundations rather than borrower/card work.

### Lane C — Inventory/add-book

1. Build 03 (`t_cdfe6621`) — after dashboard shell and Phase 1 ISBN/book foundations. Low dependency on borrower/card work.

### Lane D — Operational visibility and correction

1. Build 09 (`t_c87b9b95`) — expanded audit/history; start early enough to define shared audit event display/filters, but may need integration follow-up after other screens emit final events.
2. Build 10 (`t_ea216c15`) — safe overrides; must wait until circulation and audit event contracts are clear.
3. Build 08 (`t_505df469`) — operational reports; must wait until enough circulation/card/audit data exists to query reliably.

### Lane E — QA plan artifact

1. Build 12 (`t_4fdb59a1`) — codifies this dependency map into repo documentation/checklists after all specs and post-spec estimate are complete. Does not start feature builds; the watcher/coordinator promotes Build cards after the estimate.

---

## 5. Rollout sequence

### Stage 0 — Spec closeout and estimate gate

- Complete all Phase 3 Spec cards, including `t_fbd03450`.
- Run the post-spec estimate/start-development card `t_78d0040d`.
- Notify Mike with the Phase 3 estimate and any true decisions needed.
- Development may then start without waiting for Phase 2 human approval, unless the estimate discovers a real blocker.

### Stage 1 — Foundation shell and contracts

**Build order:**

1. Build 01 `t_ae67a161` — dashboard/Sunday shell.
2. Build 12 `t_4fdb59a1` — commit dependency map/rollout/QA artifact to repo.
3. Build 11 `t_38b05ce6` — establish cross-cutting accessibility/scanner/i18n/docs checklist or baseline.

**Safe parallelism:** Build 12 is documentation only and can run alongside Build 01 after estimate completion. Build 11 may begin once screen structure is visible.

### Stage 2 — Sunday circulation MVP path

**Build order:**

1. Build 05 `t_f7ee0dbe` — secure QR/barcode token generation/rendering contract.
2. Build 04 `t_71ae2e29` — borrower/card scan workflow.
3. Build 02 `t_6001f5b0` — fast checkout/return/renewal/reservation pickup.
4. Build 03 `t_cdfe6621` — ISBN scanner add-book; may run in parallel with 04/05/02 if dashboard links and Phase 1 ISBN foundations are stable.

**Acceptance milestone:** A Sunday volunteer can scan/search borrower, scan/search item, check out, return, renew where allowed, and add a book by ISBN without touching unrelated admin screens.

### Stage 3 — Cards, replacement, print

**Build order:**

1. Build 06 `t_de6d0687` — printable individual cards and sheets.
2. Build 07 `t_82f9d77e` — lost-card replacement/lifecycle controls.

**Safe parallelism:** Printable output can start after card token rendering is stable. Lost-card replacement must wait until active/inactive token semantics are verified by scan tests.

**Acceptance milestone:** Librarian can issue, print, scan, replace, and retire cards without exposing private borrower/card identifiers or breaking borrower history.

### Stage 4 — Visibility, audit, reports, overrides

**Build order:**

1. Build 09 `t_c87b9b95` — expanded audit/history browser.
2. Build 10 `t_ea216c15` — safe overrides/corrections.
3. Build 08 `t_505df469` — operational reports and exports.

**Safe parallelism:** Audit/history can begin as soon as core events are defined. Reports should wait for data model/event stability. Overrides must not ship without audit evidence.

**Acceptance milestone:** Librarians can investigate, report, and correct operational issues without silent data rewrites or privacy-heavy exports.

### Stage 5 — Cross-cutting QA, review, staging readiness

- Re-run Build 11 checks across all final Phase 3 screens.
- Confirm every Build produced an immutable review snapshot/artifact path and sha256 checksum.
- Reviewers verify snapshots and record results.
- Fixable review failures route to Remediation; only true human/operator interventions are Blocked.
- Prepare staging package/checklist; **do not deploy to live church systems from this board**.

---

## 6. Integrated Sunday-service and librarian workflows

### 6.1 Before service

Actor: librarian or trained Sunday volunteer.

1. Open WordPress admin and enter the ConnectLibrary Sunday dashboard.
2. Confirm the dashboard summary loads without exposing private borrower details on first glance.
3. Confirm scanner focus lands in the primary scan/search field.
4. Print or reprint borrower cards/sheets only from authenticated librarian screens when needed.
5. Review due/overdue, pending guest requests, reservation pickups, and recent returns from dashboard cards/deep links.

Expected system behavior:

- The dashboard is responsive and usable on laptop/tablet/mobile admin screens.
- Sunday mode uses large targets, simple labels, predictable keyboard order, and scanner-safe focus.
- Any future offline/sync indicator may be present only as a non-functional Phase 5 placeholder if needed; no offline/PWA implementation is included in Phase 3.

### 6.2 Add or prepare a book by ISBN

Actor: librarian.

1. From the dashboard or add-book screen, scan or type ISBN.
2. System normalizes ISBN-10/ISBN-13 input and rejects invalid scans with an accessible inline error.
3. System fetches metadata from approved providers, saves covers locally when configured, and presents a review/edit screen.
4. Librarian confirms or edits title/author/series/location/status/condition/visibility fields.
5. System creates or updates the book/copy record and audit-logs the action.

Acceptance notes:

- Scanner input must work as keyboard wedge input ending in Enter.
- Manual ISBN entry must be equivalent to scan entry.
- No borrower details are needed or displayed in this workflow.

### 6.3 Checkout / reservation pickup

Actor: librarian.

1. Scan/search the item by ISBN or existing book/copy identifier.
2. Identify the borrower by scanning a library card token or searching by permitted borrower fields.
3. If the item is reserved for that borrower, use the reservation pickup path.
4. Confirm checkout and optionally adjust due date.
5. System updates loan/reservation/copy status, creates audit events, and shows a concise success message.

Expected system behavior:

- If the scanned borrower card is active, borrower context loads.
- If the card is lost/disabled/replaced, the system does not reveal borrower identity from the old token; it shows a safe message and routes to replacement/lookup if permitted.
- Child borrower flows show only minimal guardian association needed for safe service; child notifications continue to route to guardian/parent according to Phase 2 rules.
- Due-date override is allowed for librarians and audit-logged.

### 6.4 Return / renewal

Actor: librarian.

1. Scan/search the item.
2. System finds the active loan if present.
3. Librarian chooses return or renewal.
4. Renewal is allowed only if Phase 2 rules allow it, including waitlist checks.
5. System updates loan/copy/reservation/waitlist state and audit-logs the event.

Expected system behavior:

- Recent returns are visible enough for Sunday workflow without exposing unnecessary borrower history.
- Renewal failures explain the reason in plain language.
- Corrections use explicit override/correction flows rather than silently editing historical rows.

### 6.5 Borrower card issue, print, and lost-card replacement

Actor: librarian.

1. Locate borrower from borrower admin or Sunday workflow.
2. Generate an active QR/barcode card token if one does not exist.
3. Print an individual card or include borrower in a sheet/label batch.
4. If a card is lost, mark the old card lost/disabled and issue a new active token atomically.
5. Reprint only the active token unless intentionally printing archival/admin views is separately authorized.

Expected system behavior:

- Card payloads contain only secure random tokens, never WordPress user IDs, borrower IDs, email, phone, address, child/guardian details, loan data, or private notes.
- Replacement preserves borrower/loan history while retiring the previous token.
- Reactivating old lost cards is out of scope for Phase 3; issue another fresh replacement instead.

### 6.6 End of service / operational follow-up

Actor: librarian.

1. Review current loans, due-soon/overdue, reservations, waitlist, inventory, borrower history, and high-demand reports as needed.
2. Export/print only permitted columns for the specific operational need.
3. Review audit/history entries when investigating a problem.
4. Use safe override/correction screens for due-date, copy status, hold-expiry, checkout/return corrections.

Expected system behavior:

- Reports default to privacy-minimized columns and avoid public/member-facing analytics dashboards.
- Audit/history views preserve original actions and show compensating corrections rather than rewriting history.
- Overrides may prompt for a reason; reason may remain optional/null to preserve existing requirements, but the override itself must be logged.

---

## 7. Privacy and security guardrails

### 7.1 Capabilities and access

- All Phase 3 librarian screens require authenticated WordPress admin access and appropriate librarian/admin capabilities.
- Every state-changing action uses WordPress nonce/capability checks.
- Public catalog and borrower-facing screens must not expose librarian-only dashboard/report/audit endpoints.
- REST/API endpoints added for Phase 3 must enforce the same permissions as admin screens.

### 7.2 Borrower and child/guardian privacy

- Borrower search results show only operational fields needed for librarian tasks.
- Child borrower views show parent/guardian association only where required for safe service.
- Child emails/notifications continue to route to parent/guardian only.
- Private borrower notes, raw contact data, and borrower history are not shown in scan success messages unless the specific authenticated workflow requires them.

### 7.3 Card token safety

- QR/barcode payloads contain secure random card tokens only.
- Payloads must not include WordPress user IDs, borrower IDs, emails, phone numbers, addresses, guardian links, loan IDs, reservation IDs, or private notes.
- Old/lost/disabled card scans do not reveal borrower identity from the token.
- Replacement creates a fresh active token and retires the old token atomically.

### 7.4 Audit and history

- Original circulation/admin actions remain logged.
- Corrections/overrides create append-only or compensating events; history must not be silently rewritten.
- Audit views avoid dumping raw PII by default and support narrow filters.
- Audit events include actor, timestamp, action, target, and safe contextual metadata.

### 7.5 Reports and exports

- Reports default to minimum useful columns.
- CSV/print/PDF exports require librarian capability and nonce checks.
- Borrower-history report is staff-only and must be filtered/scoped before export.
- Phase 3 excludes: bulk email/overdue campaign automation, destructive bulk report actions, public analytics dashboards, and member-facing report dashboards.

### 7.6 Production safety

- **No live church deployment from this board.**
- No real borrower/member data should be used in development verification unless Mike explicitly provides/approves a staging-safe dataset.
- All QA must use demo/sample borrower, child/guardian, card, loan, reservation, and report data.

---

## 8. Scanner, keyboard, accessibility, and i18n expectations

These apply to all Phase 3 screens unless a narrower spec says otherwise:

- Hardware scanners are treated as keyboard wedge devices; scanned input followed by Enter submits or advances the intended form.
- Manual typing/paste must work anywhere scanner input works.
- Primary scanner fields have predictable focus on page load and after success/error.
- No workflow relies on hover-only controls, drag-only interactions, or pointer-only camera UI.
- Buttons and links have visible focus states and descriptive labels.
- Inline errors are associated with fields and announced through accessible status regions where practical.
- Success/failure messages are short, plain-language, and privacy-safe.
- Tables/reports support keyboard navigation, accessible headings, and meaningful empty states.
- Print layouts are usable from browser print preview without requiring special hardware.
- All user-facing strings are translation-ready using WordPress i18n functions; English is the launch language.
- Documentation explains scanner behavior, card replacement, print steps, and Sunday workflow in volunteer-friendly language.

**Camera scanning note:** Phone/tablet camera scanning is desired for modern mobile browsers. Phase 3 screens must not block future camera scanning, but if a Build does not explicitly include camera scanning it must at minimum preserve manual/hardware-scanner input and mark full camera/offline scanning as later work. No PWA behavior may be implemented in Phase 3.

---

## 9. Out of scope for Phase 3

- **Offline/PWA, installable app, offline cache, sync queue, conflict resolution, and offline audit synchronization — these remain Phase 5.**
- Live production deployment to church systems.
- Public self-service card printing.
- Wallet passes, NFC cards, plastic card vendor integration, or family-shared card tokens.
- Reactivating old lost cards; issue another replacement instead.
- Fees/fines, borrowing limits beyond existing Phase 2 rules, acquisition/donation tracking, paid metadata providers without Mike approval.
- Bulk email campaigns, overdue campaign automation, destructive bulk report actions, public/member analytics dashboards.
- Cryptographic audit ledger/signing, advanced anomaly detection, staff performance scoring.
- Perfect historical reconstruction for actions that occurred before audit logging existed.
- New public/community features from Phase 4 unless already needed by Phase 3 implementation.

---

## 10. QA and staging checklist

### 10.1 Build-level verification required for every Build card

Each Build card must report real command output for:

- [ ] PHP syntax/lint for changed PHP files or the project lint script.
- [ ] Relevant unit/integration tests for changed services/repositories/controllers/pages.
- [ ] Capability-denied tests for unauthorized users.
- [ ] Nonce/security checks for state-changing actions.
- [ ] Privacy checks for borrower/card/report/audit outputs.
- [ ] Keyboard/scanner smoke checks for affected screens.
- [ ] Immutable review snapshot/artifact path and sha256 under `/home/mike/connectlibrary-review-snapshots/<task-id>/`.

### 10.2 Cross-feature Sunday workflow smoke test

Using demo data only:

1. [ ] Open dashboard as Librarian; confirm summary cards/deep links and scanner focus.
2. [ ] Add a book by scanned/typed ISBN; verify audit event.
3. [ ] Create/find borrower, including at least one child linked to guardian.
4. [ ] Generate card token; print preview individual card and sheet.
5. [ ] Scan active card; verify borrower context is privacy-safe.
6. [ ] Checkout book; optionally adjust due date; verify loan/copy/reservation/audit state.
7. [ ] Return book; verify recent return and audit state.
8. [ ] Attempt renewal with and without waitlist conflict; verify rule enforcement.
9. [ ] Mark card lost; scan old token; verify no identity leak; issue replacement; scan new token.
10. [ ] Run reports for current loans, due-soon/overdue, reservations, waitlist, inventory, borrower history, high-demand books.
11. [ ] Export/print allowed report columns; verify private fields are excluded by default.
12. [ ] Perform safe override/correction; verify original event plus compensating event/history.
13. [ ] Review audit/history filters for each action type.
14. [ ] Navigate all new screens by keyboard only and repeat one scan workflow without mouse.

### 10.3 Demo-data matrix

Minimum demo records required for testing and staging verification:

| Record type | Required state |
|---|---|
| Book/copy | One available |
| Book/copy | One checked out |
| Book/copy | One reserved |
| Book | One waitlisted |
| Borrower | One WordPress-user borrower |
| Borrower | One manual/guest borrower |
| Borrower | One child borrower linked to parent/guardian |
| Card token | One active borrower card token |
| Card token | One lost/disabled/replaced token |
| Loan | One due-soon loan |
| Loan | One overdue loan |
| Event | At least one correction/override event |
| Private note | At least one private note that must not appear in public/scan/print/report defaults |

### 10.4 Build and review verification

Reviewers verify immutable snapshots, not a moving shared worktree. For each Build review:

- [ ] Confirm snapshot path exists: `/home/mike/connectlibrary-review-snapshots/<task-id>/`
- [ ] Confirm sha256 checksum matches the value reported in the Build handoff.
- [ ] Inspect changed files for scope creep and Phase 5 leakage.
- [ ] Run or inspect reported verification commands.
- [ ] Validate capability/nonce/privacy tests around new endpoints/actions.
- [ ] Validate child/guardian and card-token safety where affected.
- [ ] Confirm scanner/keyboard/a11y expectations are either implemented or explicitly deferred only where allowed.
- [ ] If issues are fixable by workers, create/route Remediation and schedule Review to waiting-on-remediation; do not mark Blocked.
- [ ] Block only for real Mike/operator decisions, credentials, production approvals, or external prerequisites workers cannot clear.

**Snapshot path format:** `/home/mike/connectlibrary-review-snapshots/<task-id>/`

Each Build handoff must include:
- Changed file(s).
- Commands run and exact outputs, including lint/spell/markdown check if available; at minimum file existence, line count, and sha256.
- Review snapshot path and sha256 checksum.
- Confirmation that no live deploy, no production email, and no real borrower data were used.
- Linked Review card ID.

### 10.5 Staging readiness checklist

Before any staging deployment request:

- [ ] All Phase 3 Build cards are implemented and reviewed/remediated.
- [ ] All immutable snapshots/checksums are recorded.
- [ ] Cross-feature Sunday workflow smoke test passes on demo data.
- [ ] No live borrower data is required for verification.
- [ ] No production email sends are triggered during staging QA except explicitly approved test recipients.
- [ ] Any database migrations are documented with backup/rollback expectations.
- [ ] Settings/defaults reviewed: loan/hold/reminder, librarian email, guest reservation toggles, metadata preferences, card printing, reports, privacy.
- [ ] Mike receives a concise Phase 3 completion notification; noisy per-card notifications are not sent unless intervention is needed.
