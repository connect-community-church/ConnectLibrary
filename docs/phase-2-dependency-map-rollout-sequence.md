# ConnectLibrary Phase 2 — Dependency Map and Rollout Sequence

Spec artifact: Build 12 (Kanban card t_4f7e26f2)
Spec source: parent spec card t_59e6e615, sha256 `d46cf70a79cd4b0126f316ebe79777e09ec4444505ba58acf0fbf495f959f93e`
Phase 1 gate: t_236bf50e

This document is the durable repo record of the Phase 2 dependency map and rollout sequence. All Phase 2 feature builds must reference this document as their sequencing authority. The canonical Kanban card IDs recorded here take precedence over any informal notes.

---

## Contents

1. [Phase 2 scope summary](#1-phase-2-scope-summary)
2. [Phase 1 gate — upstream requirement](#2-phase-1-gate--upstream-requirement)
3. [Phase 2 build cards](#3-phase-2-build-cards)
4. [Dependency graph](#4-dependency-graph)
5. [Rollout waves](#5-rollout-waves)
6. [Safe parallelism and worktree guidance](#6-safe-parallelism-and-worktree-guidance)
7. [Immutable snapshot and review gate rules](#7-immutable-snapshot-and-review-gate-rules)
8. [Staging and manual review sequence](#8-staging-and-manual-review-sequence)
9. [Privacy and security boundaries](#9-privacy-and-security-boundaries)
10. [Explicit out-of-scope items](#10-explicit-out-of-scope-items)

---

## 1. Phase 2 scope summary

Phase 2 delivers borrower/member management and the core lending circulation loop. No Phase 2 item may go live on the church's production site without explicit approval from the project steward; all feature work must pass staging review first.

Phase 2 covers:

| Build | Feature area | Description |
|---|---|---|
| 01 | Borrower/member records and privacy boundaries | Core borrower identity model, guest and manual borrower support, privacy controls |
| 02 | Manual borrowers and child/guardian workflow | Volunteer-entered borrower records; child borrower with linked guardian |
| 03 | My Library borrower self-service | Borrower-facing view of loans, reservations, renewals, and secure guest/reminder links |
| 04 | Reservations and pickup holds | Borrower reserves an unavailable book; librarian marks hold ready for pickup |
| 05 | Waitlists and automatic offer flow | Queue additional borrowers when holds are full; automatic offer on availability |
| 06 | Loan/circulation data model and status transitions | Core data model for checkout, returns, renewals, and due dates |
| 07 | Librarian checkout/return/renewal admin workflow | Librarian-facing admin operations for the circulation loop |
| 08 | Due-date reminder emails and WordPress cron | Opt-in email reminders; child recipients route to guardian; WordPress cron only |
| 09 | Audit logging | Immutable cross-cutting audit trail; every build must add appropriate events |
| 10 | Admin settings/defaults integration | Defaults for loan/hold/reminder/email/privacy settings; consumed by all later builds |
| 11 | Privacy/security/accessibility/i18n test plan | Cross-cutting quality gate; used before phase completion |
| 12 | Dependency map and rollout sequence | This document; board-level planning artifact only |

Phase 2 does **not** include library cards, QR/barcode scanning, the Sunday circulation dashboard, reports/exports, audit-log browsing UI, or Offline/PWA. See [Section 10](#10-explicit-out-of-scope-items).

---

## 2. Phase 1 gate — upstream requirement

**All Phase 2 work is blocked until the Phase 1 human-review gate (t_236bf50e) is accepted.**

Gate criteria (see `docs/phase-1-staging-review-checklist.md`):

- [ ] Phase 1 staging review checklist fully signed off by the project steward (gate card t_236bf50e accepted).
- [ ] No outstanding Phase 1 blocker issues on the Kanban board.
- [ ] `main` branch CI passes (lint, PHPCS, unit tests, ZIP build).
- [ ] Repo hygiene confirmed: no live deployment, no real borrower/member data, no real email delivery active.

The Phase 1 gate is a hard stop. Phase 2 Kanban build cards may be drafted and specced before the gate, but no Phase 2 code may merge to `main` until the gate is cleared.

---

## 3. Phase 2 build cards

Build card IDs are the authoritative identifiers from the Kanban board. The wave column maps to [Section 5](#5-rollout-waves).

| # | Title | Card ID | Review gate(s) before Build | Depends on / feeds |
|---|---|---|---|---|
| 01 | Borrower/member records and privacy boundaries | t_677b6170 | Phase 1 gate (t_236bf50e) only | Foundation for all borrower identity, guest/manual borrower, privacy, cards, reservations, loans, emails, reports, audit subjects |
| 02 | Manual borrowers and child/guardian workflow | t_857a11dd | Review 01 accepted | Extends borrower model; required before child privacy in My Library, reservations, waitlists, checkout, reminders, cards/reports |
| 03 | My Library borrower self-service | t_7af0cc58 | Review 02 accepted | Consumes borrower/guardian model; later integrates reservations, waitlists, loans, renewals, secure guest links, reminder links |
| 04 | Reservations and pickup holds | t_274f5310 | Review 03 accepted where account/guest status surfaces are needed | Requires borrower/guest identity; feeds waitlists, checkout pickup, emails, audit, settings |
| 05 | Waitlists and automatic offer flow | t_e8a5154c | Review 04 accepted | Requires holds/reservations; feeds renewal blocking, availability, high-demand reports, emails, audit |
| 06 | Loan/circulation data model and status transitions | t_8f1784b6 | Reviews 01–02 accepted; coordinate with 04–05 status meanings | Foundation for checkout/returns/renewals, due dates, reminders, reports, audit |
| 07 | Librarian checkout/return/renewal admin workflow | t_4463fe7a | Review 06 accepted; Reviews 04–05 accepted for pickup/waitlist interactions | Main librarian operations; feeds reminders, My Library active loans, reports, audit |
| 08 | Due-date reminder emails and WordPress cron | t_25cd22ec | Review 07 accepted; Review 10 (settings) and Review 09 (audit) either accepted or explicitly staged as integration follow-up | Consumes loans/due dates, borrower/guardian recipients, email/settings/audit |
| 09 | Audit logging | t_ead69124 | Review 01 accepted; may build early service first, then integrate progressively | Cross-cutting service; every later Build must add appropriate audit events |
| 10 | Admin settings/defaults integration | t_cafa6dd6 | Review 01 accepted; can build early after borrower privacy defaults are known | Cross-cutting defaults for loan/hold/reminder/email/privacy/settings; later Builds must consume, not duplicate, defaults |
| 11 | Privacy/security/accessibility/i18n test plan | t_a4a8ebc7 (superseded for final gate by approved Review 11A1 `t_1f379db9`) | Can build after Specs 01–10 are accepted and at least early implementations are reviewable | Cross-cutting test coverage; approved Review 11A1 is the quality gate before phase completion |
| 12 | Dependency map and rollout sequence | t_4f7e26f2 | All Spec parents complete; Phase 1 gate before Build execution | Produces board-level dependency/rollout artifact; no production plugin feature required unless represented as docs/scripts/board metadata |

---

## 4. Dependency graph

Critical-path chain flows top-to-bottom. Cross-cutting builds (09, 10) start after 01 and integrate progressively into every later build. Quality-gate builds (11, 12) close after all others.

```
Phase 1 gate (t_236bf50e)
└── 01: Borrower/member records (t_677b6170)
    ├── 02: Manual borrowers / child/guardian (t_857a11dd)
    │   ├── 03: My Library self-service (t_7af0cc58)
    │   │   └── 04: Reservations / pickup holds (t_274f5310)
    │   │       └── 05: Waitlists / automatic offer (t_e8a5154c)
    │   │
    │   └── 06: Loan/circulation data model (t_8f1784b6)  ◄── also needs 01; coordinate 04–05 status meanings
    │       └── 07: Librarian checkout/return/renewal (t_4463fe7a)  ◄── also needs 04–05 accepted
    │           └── 08: Due-date reminder emails (t_25cd22ec)  ◄── also needs 09 base + 10 integration
    │
    ├── 09: Audit logging (t_ead69124)         [starts after 01; integrates into every later Build]
    └── 10: Admin settings/defaults (t_cafa6dd6)  [starts after 01; consumed by all later Builds]

All Specs 01–10 accepted:
    ├── 11A1: Approved privacy/security/a11y/i18n quality gate (t_1f379db9; replaces broad t_a4a8ebc7 gate)
    └── 12: Dependency map / rollout sequence (t_4f7e26f2)  [this document]
```

---

## 5. Rollout waves

### Wave 0 — Gate and repo hygiene

**Prerequisite for all Phase 2 work. Every Build is gated here.**

- Phase 1 human-review gate t_236bf50e must be accepted before any Phase 2 Build card moves out of Backlog.
- Confirm repo hygiene: no live deployment, no real borrower/member data in any worktree or fixture, no real email delivery active.
- All Phase 2 Kanban build cards remain in Draft/Backlog until Wave 0 clears.

### Wave 1 — Borrower foundation

**Builds:** 01 first; then 02, plus early 09 base service and early 10 defaults slices where safe.

- Build 01 (t_677b6170) **must go first.** No other Phase 2 Build starts until Review 01 is accepted.
- After Review 01 is accepted, Build 02 (t_857a11dd), an early audit-service slice of Build 09 (t_ead69124), and an early defaults slice of Build 10 (t_cafa6dd6) may run in parallel — only with **separate worktrees** and **explicit schema/migration coordination** before any branch diverges.
- 09 and 10 in Wave 1 deliver only their base service/defaults; full integration with later builds is completed in Wave 4.

### Wave 2 — Borrower-facing status and hold pipeline

**Builds:** 03, 04, 05 (sequential within this wave).

- Build 03 (t_7af0cc58): My Library borrower self-service. Starts after Review 02 is accepted.
- Build 04 (t_274f5310): Reservations and pickup holds. Starts after Review 03 is accepted where account/guest status surfaces are needed. Must precede Build 05.
- Build 05 (t_e8a5154c): Waitlists and automatic offer flow. Starts after Review 04 is accepted. **Must not invent a second hold model** — extends the model established in Build 04.

### Wave 3 — Circulation core

**Builds:** 06, then 07 (sequential).

- Build 06 (t_8f1784b6): Loan/circulation data model and status transitions. Requires Reviews 01–02 accepted; coordinate with 04–05 status meanings before schema solidifies.
- Build 07 (t_4463fe7a): Librarian checkout/return/renewal admin workflow. **Must not complete until Review 06 accepts the data model and status transitions.** Also requires Reviews 04–05 accepted for pickup/waitlist interactions.

### Wave 4 — Communications and cross-cutting integration

**Builds:** Complete Settings 10 and Audit 09 integrations across all prior builds; then Build 08.

- Complete Build 10 (t_cafa6dd6) integration — all prior builds must consume, not duplicate, defaults.
- Complete Build 09 (t_ead69124) integrations — every prior build must have added appropriate audit events.
- Build 08 (t_25cd22ec): Due-date reminder emails and WordPress cron. Requires Review 07 plus settings/audit hooks. Emails remain local/test only — no real church email addresses. Child borrower emails route only to the linked parent/guardian recipient.

### Wave 5 — Quality gate and closeout

**Builds:** 11, 12; phase smoke test; completion report.

- Review 11A1 (t_1f379db9): Privacy/security/accessibility/i18n quality gate reviewed and accepted. This approved Review 11A1 gate replaces the earlier broad Build/Review 11 card (t_a4a8ebc7) for final Phase 2 closeout.
- Build 12 (t_4f7e26f2): Rollout artifact (this document) — finalize or confirm if needed.
- Final local/staging-only phase smoke test.
- Concise phase-completion report.

---

## 6. Safe parallelism and worktree guidance

### Which builds may run in parallel

| Earliest start point | Builds that may parallelize | Coordination required |
|---|---|---|
| After Review 01 accepted | 02 + early 09-base slice + early 10-defaults slice | Separate worktrees; explicit schema/migration plan agreed before any branch diverges |
| Wave 2 | 03 → 04 → 05 (sequential within wave) | 04 cannot start until Review 03; 05 cannot start until Review 04 |
| After Reviews 01–02 accepted | 06 may begin alongside Wave 2 progress | Coordinate with 04–05 status meanings before 06 schema solidifies |
| Wave 4 | 10 integration and 09 integrations complete in parallel, then 08 | Settings/audit hooks must be present before 08 begins |

Wave 2 (03 → 04 → 05) and Wave 3 (06 → 07) run sequentially within each wave. Builds 09 and 10 are the primary cross-cutting builds that may overlap other waves once their base service is established.

### Worktree rules

1. **One worktree per build card.** Never combine two build cards in one worktree.
2. **Schema changes require advance coordination.** Draft and agree on all schema additions before parallel branches diverge. The branch that merges first owns the migration; the second rebases and verifies the upgrade path.
3. **Capability constants are shared.** `ConnectLibrary\Support\Capabilities` — add new constants only; never rename or remove an existing constant in a feature branch.
4. **Settings keys are shared.** New Phase 2 settings keys must be routed through Build 10; prefix with `cl2_`; coordinate to avoid collisions.
5. **Audit events are cross-cutting.** Every build that creates or modifies borrower/loan/hold/settings data must write to the audit service from Build 09 — do not create local ad-hoc audit logging in individual builds.
6. **No real borrower data in worktrees.** Use synthetic stubs only (e.g., `Jane Reader`, `jane@example.test`). Never import, seed, or commit real church member or borrower records.
7. **Merge order within a wave is not fixed** unless one build explicitly gates the next — first-ready build merges first.

---

## 7. Immutable snapshot and review gate rules

### Immutable snapshots

Each build's review artefact is stored as an immutable snapshot at:

```
/home/mike/connectlibrary-review-snapshots/<build-id>/
```

Where `<build-id>` is the Kanban card UUID for that build (e.g., `t_677b6170` for Build 01).

**Rules:**

- The snapshot directory is written once, when review begins, and is never modified afterward.
- Review workers verify the snapshot SHA-256 and confirm the working repo has **not moved** since the snapshot was taken. If the repo has moved, review is restarted from a fresh snapshot of the new commit.
- Snapshot contents must include at minimum: the plugin ZIP, a SHA-256 file for the snapshot archive or plugin ZIP, a manifest naming changed files and verification commands/results, and the available source-control/CI evidence.
- When the repo has a Git `HEAD` or pull-request CI run, record the commit SHA and attach or summarize the CI check log. When the shared repo/workspace has no commit `HEAD` or no remote CI run yet, the manifest must state that explicitly and include the local/Docker `composer check` evidence used by the board as the review gate.
- Snapshots are cumulative across builds — do not delete earlier build snapshots.

### Phase 1 snapshot

Before any Phase 2 code merges to `main`, a Phase 1 snapshot tag must exist:

```
git tag -a phase-1-complete -m "Phase 1 complete — staging review approved"
```

The snapshot tag is immutable once created. Do not re-tag or move it.

### Per-build CI gate

Every Phase 2 build must pass the full CI gate before merging:

```sh
composer check
```

Runs lint, PHPCS, PHPUnit (synthetic stubs only), and ZIP verification. The GitHub Actions workflow (`.github/workflows/ci.yml`) must show green on the pull request before merge.

### Review failure classification

| Outcome | Action |
|---|---|
| Fixable failure (code defect, missing test, style violation, integration gap, etc.) | Create a **Remediation** card on the Kanban board. The build is **not** moved to Blocked. Fix and re-review. |
| Unresolvable blocker (schema conflict, privacy boundary violation, gate prerequisite not met, etc.) | Card moves to **Blocked**. Jarvis notifies Mike for intervention. |

**Fixable Review failures create a Remediation card — not a Blocked status.** Only violations that cannot be resolved without external intervention move a card to Blocked.

### Monitoring and notification

- **Jarvis (default agent)** owns continuous monitoring of all Phase 2 build and review progress.
- **Mike is notified only for:**
  - Intervention-needed blockers that Jarvis cannot resolve autonomously.
  - Phase completion (Wave 5 smoke test and completion report approved).
- Routine review results, Remediation card creation, CI reruns, and wave progression are handled by Jarvis without escalation.

### Phase 2 staging review gate

The Wave 5 quality gate (approved Review 11A1, t_1f379db9) is the only authorisation for production use of any Phase 2 feature. It requires:

- All Builds 01–10 merged to `main` and CI green.
- Review 11A1 (privacy/security/accessibility/i18n quality gate, t_1f379db9) accepted.
- No outstanding Blocked cards on the Phase 2 board.
- Project steward confirmation that no real church member or borrower data was used at any point during Phase 2 development or review.

Do not deploy Phase 2 features to the live church site until this gate is cleared.

### Spec artifact lock

This document is derived from accepted spec sha256 `d46cf70a79cd4b0126f316ebe79777e09ec4444505ba58acf0fbf495f959f93e` (spec source card t_59e6e615). Material changes to Phase 2 scope, build order, or wave assignment require a new spec accepted on the Kanban board and a corresponding update to this document. Cosmetic edits (typos, formatting) do not require a new spec.

---

## 8. Staging and manual review sequence

Use this sequence when deploying Phase 2 builds to a local or staging site for review. No Phase 2 build is reviewed against real borrower/member data or live email delivery.

1. **Confirm Phase 1 is stable on staging.**
   - Plugin activated, no errors, catalog functional, Phase 1 gate t_236bf50e accepted.
   - Phase 1 snapshot tag (`phase-1-complete`) exists and is immutable.
   - Snapshot at `/home/mike/connectlibrary-review-snapshots/t_236bf50e/` is present.

2. **Wave 1 — Borrower foundation.**
   - Deploy Build 01 and confirm activation does not break existing catalog or settings.
   - Confirm borrower identity tables and privacy boundary structures created on activation.
   - Deploy Build 02 and confirm manual borrower and child/guardian records can be created and edited.
   - Confirm guardian email is stored admin-only; no public surface.
   - Confirm early audit (09-base) and settings defaults (10-base) are present and do not conflict.

3. **Wave 2 — My Library, reservations, waitlists.**
   - Deploy Build 03 and confirm My Library self-service is accessible only to the authenticated borrower (or via scoped secure guest link) — no public surface for any other borrower's data.
   - Deploy Build 04 and confirm a borrower can place a reservation; librarian can mark hold ready for pickup; hold status is visible in admin and My Library only.
   - Deploy Build 05 and confirm waitlist advances correctly when a hold is released; confirm no second hold model was introduced.

4. **Wave 3 — Circulation core.**
   - Deploy Build 06 and confirm loan data model and status transitions are correct; no schema ambiguity with hold statuses from Builds 04–05.
   - Deploy Build 07 and confirm librarian checkout/return/renewal workflows function; availability label updates on the public catalog with no borrower identity exposed.

5. **Wave 4 — Settings, audit, and email.**
   - Confirm Build 10 settings defaults are consumed by all prior builds — no duplicated or locally overridden defaults.
   - Confirm Build 09 audit events are present for borrower, loan, hold, waitlist, and settings operations across all prior builds.
   - Deploy Build 08 and confirm due-date reminder emails trigger via WordPress cron; emails send only to opted-in borrowers; child borrower emails route to guardian recipient only; no external mail API is called.

6. **Privacy spot checks (every wave).**
   - Confirm no borrower names, emails, loan history, private notes, or guardian details appear on any public-facing page or in public REST responses.
   - Confirm a logged-out visitor cannot access any `connectlibrary/v1/borrowers/` route (expect 401 or 403).
   - Confirm My Library and reservation/waitlist status are never visible to unauthenticated users.
   - Confirm no real church email addresses were used in any test or fixture.

7. **Wave 5 — Quality gate and sign-off.**
   - Review 11A1 privacy/security/accessibility/i18n quality gate (t_1f379db9) reviewed and accepted.
   - Project steward confirms no real church member or borrower data was used at any point.
   - Phase completion report generated and filed; Jarvis notifies Mike.

---

## 9. Privacy and security boundaries

These rules are non-negotiable across all Phase 2 builds.

### Data boundaries

| Data type | Allowed surfaces |
|---|---|
| Borrower display name | Admin screens, admin REST only |
| Borrower email / phone | Admin screens, admin REST only |
| Guardian name / email / phone | Admin screens, admin REST only — never exposed to child or public |
| Private notes | Admin screens only — never REST, never public |
| Loan history / checkout records | Admin screens, admin REST, My Library (authenticated borrower's own records only) |
| Reservation / hold status | Admin screens, admin REST, My Library (own reservations only) |
| Waitlist position | Admin screens, admin REST, My Library (own position only) |
| Audit log | Admin screens only — never REST, never public |
| Borrower status | Admin screens, admin REST only |
| `wp_user_id` link | Admin screens, admin REST only |
| Public catalog availability label | Public catalog (status label only — no borrower identity) |
| Secure guest / reminder links | Scoped to the individual borrower's own data only — no cross-borrower access |

### Capability gates

All borrower and circulation operations require the `manage_borrowers` capability, or explicit per-borrower authentication scoped to the borrower's own data (My Library / secure guest link). No borrower data is readable by subscribers, contributors, authors, or editors beyond their own My Library view.

### REST endpoint rules

- All routes under `connectlibrary/v1/borrowers/` must include a `permission_callback` that returns `WP_Error` (403) for unauthenticated or insufficiently privileged requests.
- No public route may return a borrower record, borrower ID, loan record, reservation detail, or any field from the borrower or loan tables.
- My Library REST endpoints may return only the authenticated borrower's own data.
- The public books/catalog REST endpoints must continue to return only visibility-safe catalog fields — no availability state that reveals a borrower's identity.

### Email notices

- Email notices are sent only when `email_notices_allowed = 1` on the borrower record.
- The field defaults to `0` (opt-out). A librarian must explicitly enable it per borrower.
- Child borrower email notices route to the linked guardian/parent recipient only — never to the child.
- Email content must not include other borrowers' names, private notes, or loan history.
- WordPress `wp_mail` is used directly; no third-party email service, API key, custom SMTP credential, or external API is introduced in Phase 2.
- All email testing uses local/staging WordPress only — no real church email addresses.

### Test and development data

- No real borrower or member records from the church may appear in unit tests, fixtures, commits, or worktrees.
- Test data uses clearly synthetic names and addresses (e.g., `Jane Reader`, `jane@example.test`).
- All service and REST tests must use in-memory stubs only.

---

## 10. Explicit out-of-scope items

The following are **not** part of Phase 2. Any work on these items requires a separate accepted spec on the Kanban board and an update to this document.

| Item | Status |
|---|---|
| Library cards (physical or digital) | Phase 3 |
| QR codes and barcodes for books or borrowers | Phase 3 |
| Sunday circulation dashboard | Phase 3 |
| USB / Bluetooth ISBN scanner integration | Phase 3 |
| Reports and export dashboards | Phase 3–4 |
| Audit-log browsing UI | Phase 3–4 |
| Fines, fees, borrowing limits, donations, or acquisitions | Not phased — explicitly out of scope |
| Overdue reminder campaigns | Out of scope in Phase 2 (Phase 2 covers pre-due and on-due reminders only) |
| SMS / push notifications | Not phased — explicitly out of scope |
| Custom SMTP credential setup | Out of scope in Phase 2 |
| Full public borrower history | Out of scope |
| Arbitrary custom circulation statuses beyond accepted spec | Out of scope |
| Large UX redesign beyond accepted specs | Out of scope |
| Offline / PWA functionality | **Phase 5 — explicitly out of scope for all earlier phases** |
| Live production church site deployment | Requires project steward approval after Phase 2 quality gate (Review 11A1, t_1f379db9) |

> **Important:** My Library borrower self-service (Build 03), secure guest links, reservations and holds (Build 04), waitlists (Build 05), librarian checkout/return/renewals (Build 07), and due-date reminder emails (Build 08) are all **Phase 2** items. They are **not** deferred to Phase 3. Any plan, branch, or card that labels these as Phase 3 is incorrect and must be corrected.

Offline/PWA is Phase 5 and must not be designed into any Phase 2 feature build, even as an optional enhancement. Mention of offline capability, service worker, or PWA manifest in a Phase 2 pull request is grounds for rejection.
