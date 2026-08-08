# Registration and Account Foundation Discovery — 2026-07-29

## Purpose

Connect Community Church needs a general public website registration flow that can support ConnectLibrary now and future authenticated site features later, including personal event calendars powered by The Events Calendar / Events Calendar Pro.

The current admin-side borrower-to-WordPress-user linking flow is too clunky for normal librarian use. The desired direction is self-service registration with automatic library patron creation.

## Decisions captured

1. Public registration model
   - Default path: open public registration.
   - Anyone can create an account from the church website.
   - The account system should be a general church website feature, not a library-only hidden workflow.
   - Registration should be visible through both a main menu Register/My Account link and contextual prompts on feature actions like reserving books or saving events.
   - Staff should receive a daily or weekly digest of new accounts rather than an individual notification for every normal registration.

2. Library patron creation
   - Website registration should automatically make the person a library patron.
   - When a WordPress user account is created, ConnectLibrary should automatically create a linked borrower profile.
   - New users can use account features immediately; email verification is a security reminder rather than a hard gate in v1.
   - The normal flow must not require the librarian to manually connect borrower records to WordPress users.
   - Admin link/merge tools remain for edge cases only, such as duplicate records, old manual borrowers, typo corrections, or legacy data cleanup.

3. Registration fields
   - Signup should collect only name and email initially.
   - Password is required for the WordPress account unless the site later moves to passwordless/magic-link login.
   - Phone/address should not be required at registration.
   - Additional contact fields may be collected later only when a feature requires them.

4. Child/youth borrowers
   - Parents should be able to add child borrowers under their own account.
   - Children should not need separate WordPress accounts for the first version.
   - Parent/guardian receives child-related emails and can view/manage child loans/reservations.

5. Event calendar integration
   - First event-calendar feature: registered users can save events into a personal church calendar.
   - Users should also have a downloadable/subscribable iCal feed for their saved events.
   - Users should be able to choose in settings whether their saved-event iCal feed includes upcoming events only or both past and future saved events.
   - The Events Calendar / Events Calendar Pro should remain the source of truth for church events.

6. Library cards and scanner direction
   - Registered patrons should be able to print/request their own library card from My Library.
   - Cards should contain QR and/or barcode codes scannable by the purchased Yoyo 1D/2D Bluetooth/USB scanner.
   - The encoded value must be a secure random card token mapped to the borrower/card record.
   - Do not encode WordPress user IDs, borrower IDs, names, email addresses, or other personal information in the card code.
   - Lost cards should be disabled and replaced with a new token.
   - The user-facing card workflow should be self-service, while still allowing librarians to print cards for patrons who need help.

## Recommended architecture

### Shared website account foundation

Use WordPress users as the identity layer for the church website.

Add a small shared account/profile foundation that can support multiple site modules:

- public registration
- email verification
- basic profile page
- account status
- privacy/export/delete hooks
- future site preferences
- integration points for ConnectLibrary and event calendar features

ConnectLibrary should not become the owner of all website registration. It should integrate with the shared account foundation.

### ConnectLibrary integration

On user registration:

1. WordPress user is created.
2. Email verification reminder is sent, but v1 does not block immediate use while verification is pending.
3. ConnectLibrary creates a borrower profile linked to the WordPress user.
4. Borrower profile status starts as active unless later policy changes require extra safeguards.
5. Borrower/card records are ready for reservation and card generation.

On reservation:

- Logged-in users reserve directly.
- Guest reservations remain available as a fallback for people who do not want to create an account immediately.
- Not-logged-in users should see clear choices:
  - Log in
  - Create account
  - Continue as guest request
- If the user creates an account during reservation, the borrower profile should be auto-created and the reservation attached immediately.
- Guest reservations should continue to require librarian approval.

On admin screens:

- The librarian should search/scan borrowers, not manually reason about WordPress user links.
- Borrower screens can show the linked account status as helpful background.
- Link/merge should be a simple repair workflow, not a required step.

### Parent/child model

- Parent is a normal WordPress user and borrower.
- Child borrower is a borrower record linked to the parent/guardian user.
- Child does not require a login.
- Child card token maps to the child borrower record.
- Parent can view and manage eligible child reservations, loans, renewals, and notifications.
- Librarian checkout screens should show the child plus parent/guardian association clearly.

### Event calendar integration

The Events Calendar / Events Calendar Pro remains authoritative for event content.

Add a user-specific saved-events layer:

- Save/unsave event button on event detail/list views.
- My Church Calendar page for registered users.
- Saved events stored by user ID and event/post ID.
- iCal feed endpoint with a secure per-user feed token.
- Feed includes saved events according to the user's setting: upcoming-only or past-and-future.
- Feed token can be reset from account settings if accidentally shared.

Do not treat saved events as official RSVP/attendance tracking in the first version. RSVP/registration-style features should be a separate later decision.

## MVP scope recommendation

Build in this order:

1. Public registration and email verification.
2. Automatic borrower creation on registration.
3. My Account / My Library foundation.
4. Parent adds child borrowers.
5. Reserve flow updated to prefer login/create-account.
6. Printable/scannable library cards using secure card tokens.
7. Saved events and My Church Calendar.
8. Private iCal feed for saved events.

## Safety and privacy notes

- Send email verification/reminder after registration, but do not make it a hard gate for v1 account use.
- Use nonce, honeypot, rate limiting, and/or CAPTCHA-compatible anti-spam protection on registration.
- Keep encoded library card contents opaque and non-personal.
- Provide card disable/reissue workflow.
- Provide account/borrower suspension tools for staff.
- Avoid collecting phone/address unless a real ministry workflow needs it.
- For children, route communication through parent/guardian accounts.

## Open decisions

No open discovery decisions from this pass. Next step is to turn this into an implementation plan and split it into build phases.
