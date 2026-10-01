# VENUSeP — Database Decisions (locked)

Decisions settled with the team on **2026-07-19**, reconciling the team's meeting
minutes (07/19) against the first-draft `venusep_schema.sql`. This is the
authoritative "what we agreed" list — apply the schema to match it. Read
alongside `PROJECT-HANDOFF.txt` (system context) and `DB-TRANSITION.md` (the
technical checklist). Where this file and the first-draft SQL disagree, **this
file wins.**

> How to use: hand this to whoever applies the schema (human or Claude). It's a
> decision list, not a full DDL — it says what to change and why.

---

## 1. Statuses — two lookup tables (not inline ENUMs)
So status values can be added/edited as data, and staff-vs-customer wording
lives in one place.

```
reservation_statuses(code PK, staff_label, customer_label, sort_order, is_terminal)
payment_statuses    (code PK, staff_label, customer_label, sort_order)

bookings.reservation_status -> FK reservation_statuses.code
bookings.payment_status     -> FK payment_statuses.code
```
- Keep the **code as the key** (`'confirmed'`, `'await_pos'`) — readable queries, and adding a status is an `INSERT`, not an `ALTER`.
- `staff_label` = what staff see; `customer_label` = the softened wording the customer sees. One row, both vocabularies.

## 2. Pricing & discount
- Discount is **percentage-based, default 20%, dynamic** (staff can change it).
- The live % is config in **`system_settings`** (`discount_percent`).
- **The system computes the discounted price and stores it in `bookings.total_amount`. The GCash checker only validates that stored price — it never applies the discount itself.**
- **Each booking snapshots the % it received** — store `discount_percent` + `discount_amount` **on the booking**. Changing the live % later must never rewrite past bookings.
- Rename `bookings.rate_snapshot` → **`room_price`** (the pre-discount room price at booking time).
- Discount applies to **both venue and hostel** bookings.

## 3. Affiliation & IDs (unified with the discount)
- **Per booking, re-verified every booking** — NOT a once-per-customer flag.
- Flow: at ID upload the customer picks **USeP-affiliated / non-affiliated** → uploads the matching ID → **staff verify it** → if affiliated **and** verified, the discount applies to **that** booking.
- The affiliation choice + discount fields live **on the booking**; the uploaded ID lives in `booking_documents` (staff-verified).
- Two ID types (affiliated vs non-affiliated) are driven by this same choice.

## 4. Customers & walk-ins
- **`customers` is standalone with a nullable `user_id`.**
  - Online customer → has a `user_id` (a login in `users`).
  - **Walk-in → `customers` row with `user_id = NULL`** (no login).
- `bookings.customer_id` → `customers.id` in both cases.

## 5. GCash accounts
- **One GCash account per venue**, admin/staff-managed. Every room under a venue uses that venue's account.
- Do **not** rename to "Customer GCash Accounts" (it's the venue's *receiving* account, not a customer's). Keep `gcash_accounts` or use `venue_gcash_accounts`.

## 6. GCash receipt verdicts — three
`accepted` / `rejected` / `manual_review`:
- **Reject** only if **0 fields correct** OR **duplicate reference number**.
- **Either amount OR account correct → `manual_review`** (staff look).
- Amount AND account correct, reference clean → **accepted**.
- **5-attempt rule (minutes):** after 5 receipt submissions, staff force manual review and note it rejected.
- ⚠️ Consequence for the app (not the DB): the built client checker currently auto-rejects on a *single* mismatch — its verdict logic needs realigning to the above. That's a UI/app change, done later.

## 7. Hostel beds — exact-bed model
- **Customer picks which specific bed** (UI catches up later). Keep `hostel_beds` + `bed_reservations` + `bed_reservation_nights`.
- Last-bed race stays enforced by `UNIQUE(active_bed_id, night_date)`.
- Bed count stays **derived**: room capacity = `COUNT(hostel_beds)`; a booking's beds = `COUNT(bed_reservations)`. No stored bed count.
- Rename `booking_occupants` → **`hostel_occupants`**.
- Gender `ENUM('male','female','other')` — drop only `prefer_not_to_say`, keep `other`. (UI currently offers Female/Male only; add "Other" when the UI is updated.)
- A **single broken bunk** = `hostel_beds.is_active = false` (makes a 6-bed room a 5-bed room). This is bed-level, separate from a room maintenance window.

## 8. Venue per-day hours
- The DB sets a **time per day**, not one time for the whole stay.
- `venue_booking_slots` (one row per day, each with its own `start_time`/`end_time`) is authoritative.
- **Drop the single `start_time`/`end_time` from `venue_booking_details`** (keep `event_name`, `start_date`, `end_date`, `attendee_count`, `purpose`).

## 9. Amenities
- Amenities **and** attributes (the tap-able icon chips) are **hard-coded in PHP** → **remove the `amenities` and `room_amenities` tables.**
- "At a glance" facts (rate, bookable hours, best-for, catering, accessibility) are **not** amenities — a separate thing. *(Open: keep them hard-coded per room, or make them editable room columns. See Open Items.)*

## 10. system_settings — kept and improved
- **Keep the table.** It's the home for tunable, staff-editable config.
- Add **`updated_by_user_id`** (these are money/policy values — track who changed them).
```
system_settings(setting_key PK, setting_value, value_type, description,
                updated_by_user_id, updated_at)
```
- Seed keys:
  - `discount_percent = 20`  — the dynamic discount
  - `hostel_pos_deadline_hours = 72`
  - `hostel_advance_booking_max_days = 7`  — hostel can't be reserved more than ~1 week before check-in; **events can be booked any time ahead.**

## 11. Maintenance — KEEP, system-wide (all venues AND the hostel)
A **maintenance window is a dated closure attached to a room.** There is no room
"status" field — availability is derived from bookings + maintenance windows.
Applies to **every room, venue and hostel.** (The minutes' "No maintenance for
venues" is **overridden** — maintenance is general.)

`maintenance_windows` fields:

| Field | Meaning |
|---|---|
| `room_id` | which room |
| `from_date` | closure start |
| `until_date` | closure end — **NULL = indefinite** |
| `reason` | free text ("Roof repair") |
| `blocks_booking` | **true = HARD**, **false = MEDIUM** |
| `created_by_user_id`, `created_at`, `updated_at` | audit |

Rules:
- A window **covers a date** when `from_date <= date AND (until_date IS NULL OR date <= until_date)`. Constraint: `until_date IS NULL OR until_date >= from_date`.
- **HARD (`blocks = true`)** → cannot be booked on covered dates.
- **MEDIUM (`blocks = false`)** → still bookable; the customer is just shown a notice. Never blocks.
- **Indefinite (`until_date = NULL`)** → no end date. An indefinite HARD closure open **≥ ~14 days** raises a **"still closed?" review prompt** in Venue Management so a room can't be closed and forgotten.
- **Venue availability:** a HARD-covered date is unavailable; a multi-day booking **books *around* it** — e.g. pick 23–27 with HARD maintenance on 24–26 → books **23 and 27 only** (pays for 2 days), the excluded days are shown to the customer. Same treatment as days already booked by someone else. Medium windows are **not** excluded (booking takes all days + shows the notice).
- **Hostel availability:** a stay must be **contiguous** — a HARD-covered night **rejects the whole stay** (a guest can't leave a bed and return mid-stay).
- **Disruption flow (stays, because maintenance stays):** setting a **HARD** closure on a room that already has **paid bookings inside the window** triggers the disruption/refund flow — offer a replacement room, or an **involuntary full refund** → `reservation_status = 'disrupted'`. Designed, not yet built; keep the `disrupted` status reserved.

## 12. Renames (quick list)
- `bookings.rate_snapshot` → **`room_price`**
- `booking_occupants` → **`hostel_occupants`**
- `staff.position_title` → **`position_role`**
- `venues.venue_kind` → **`venue_type`**

## 13. Keep in schema AND add to the UI
These exist in the schema but not the UI yet — **keep them and add UI for them:**
`reviewed_by` / `verified_by`, `venues.contact_email`, `staff.employee_no`.

## 14. Procs / triggers / views / indexes
- **Secondary — get the tables right first.** Add/adjust logic where it fits once tables settle.
- Until re-added, two things are app-level, not DB-enforced: **venue-overlap prevention** and **audit-timeline writes** (they lived in the stored procedure + trigger).

## 15. What the first-draft SQL already got right (keep as-is)
- One `bookings` table: customer submit = `INSERT`, admin queue = `SELECT` over the same table.
- One DB-owned booking ID (reference derived from it).
- Concurrency guards (venue lock/overlap; hostel `UNIQUE(active_bed_id, night_date)`).
- `gcash_receipts.reference_number` UNIQUE + file-hash UNIQUE.
- Two-axis status (reservation + payment).

## 16. Refund switch — decided 2026-09-16
USeP does not do refunds: every transaction is non-refundable. The teacher's
recommendation was to **keep the refund module but let the admin turn it on and off.**

- **One global switch**, `system_settings.refunds_enabled`, **default `0` (OFF)**.
- **Admin only** (`users.account_type = 'admin'`). Staff see it read-only.
- **Covers customer-requested refunds only.** A closure by USeP (maintenance or
  any other reason) is never affected: the customer is offered a replacement room
  or a new date, and refunded if they decline both (#11 disruption flow).
- **Per-booking snapshot — `bookings.refunds_allowed`.** Copied from the switch
  when the booking is **made**, never changed after. Turning the switch OFF does
  not take refunds away from bookings made while it was ON; turning it ON does not
  make older bookings refundable. Customers are held to the policy they agreed to.
- **Turning it OFF:** new requests are blocked; **open requests are still finished
  by staff**; refund history stays visible to admins.
- **Customers are told before they book.** While OFF, the landing page, FAQ and
  booking policies say bookings are non-refundable, and the booking review screen
  requires an "I understand this booking is non-refundable" checkbox.
- **Changing it needs password re-entry.** 5 wrong passwords in a row = a lock,
  each longer than the last: **10s → 30s → 1m → 5m → 15m (max)**. A correct password
  resets it. Stored on the account (`users.reauth_failed_attempts`,
  `reauth_lock_level`, `reauth_locked_until`), not the session.
- **Every change is an event** in **`system_settings_history`** (who, when, old,
  new). Shared with the discount rate once that is wired.
- **Fail safe:** if the database is unreachable, the customer side treats refunds as OFF.

Refund-table gaps closed in the same change (DB-TRANSITION "Schema gaps"):
`refunds.refund_status` gains `returned_for_correction` + `withdrawn`;
`payment_statuses` gains `refund_correction`, `refund_await_or`, `refund_denied`;
`refunds` gains `reason_category`, `refund_to_number`, `official_receipt_pending`,
`correction_attempts`, `correction_due_at`, `payout_reference`, `resubmitted_at`,
`withdrawn_at`; and **one OPEN request per booking** is enforced by a generated
`open_booking_id` + UNIQUE (finished/withdrawn requests stay as history).

## 17. Live database realigned — 2026-09-16
The local `venusep` database had been built from the **first-draft** SQL
(inline status ENUMs, `amenities`/`room_amenities`, `booking_occupants`, no
`updated_by_user_id`). It was backed up, then rebuilt from `venusep_schema.sql`,
so the live DB now matches this file. Kept from it: the test logins
`admin@gmail.com` (admin) and `customer@gmail.com` (customer, profile *Brent
Cajipoe*) — **test passwords only**. `customers` now has its own `id` (#4), so
`customer-login.php` reads `c.id AS customer_id`.

## 18. Payment timing follows the refund switch — 2026-09-17
USeP does not refund, so it must not hold money for a service it may not be
able to deliver (a room closed for maintenance after payment). The **same
switch** (#16) therefore decides **when** a booking is paid, and each booking
keeps the policy it was made under (`bookings.refunds_allowed`, already
snapshotted). No second setting.

| | refunds ON = **pre-pay** (the original flow) | refunds OFF = **post-pay** (USeP default) |
|---|---|---|
| Payment opens | at approval | **after the last booked day** (event end / check-out) |
| Pay by | 23:59 the day before the first day; if already past, before it starts | **`postpay_grace_days` (3) after the last day**, 23:59 |
| Missed | hold **released**, payment `expired` | booking stays; payment **`overdue`** — chasing it is staff's job |
| Can still pay after | no | **yes** — it simply turns Paid late |
| Hostel POS (CEDU) | before check-in, as before | **after check-out**, then the guest pays within the grace days |

- **Customer cannot pay early under post-pay.** Deliberate: no money changes
  hands until the event has actually happened.
- New payment status **`await_event`** ("Payment due after event" / customer
  "Payment pending") — the post-pay holding state between approval and the
  last day. Customer labels for `await_gcash`/`await_cash` become
  "Payment due".
- **One place computes the dates:** `fn_payment_deadline(booking)` (with
  `fn_booking_first_day` / `fn_booking_last_day`). **One entry point for
  approval:** `sp_approve_booking(booking, staff)` picks status + deadline
  for either type and either policy. `sp_expire_due_bookings()` now also
  opens post-pay payment windows and marks overdue. `sp_start_await_pos` is
  policy-aware. `v_booking_summary` exposes `payment_policy`,
  `first_day`, `last_day`.
- The two-axis status carries it: a finished, unpaid post-pay booking reads
  *Completed · Payment due*.
- Not built: notifications when a payment window opens. The customer sees it
  on booking history and the booking page.

## 19. Admin-managed FAQs — 2026-09-18
EVERY question on the customer FAQ page lives in the database, so admins and
staff can add, edit, reorder and hide them without a developer. Two tables:
`faq_sections` (the six section keys the FAQ page uses as ids — booking,
discount, hostel, gcash, cash, after) and `faqs`.

- **Built-in questions are seeded rows** (`is_builtin`), not code. They can
  be edited and hidden but never deleted; the shipped wording is kept in
  `default_question` / `default_answer` so "Restore original" always works.
- **Live values are placeholders** filled at render time — `{discount}`,
  `{grace_days}`, `{rate_communal}`, `{rate_private}`, `{cr_communal}`,
  `{cr_private}` — so the text never drifts from pricing / hostel / refund
  settings.
- **Policy per row** (`any` / `refunds_on` / `refunds_off`): the refund
  switch (#16) decides which rows customers see, because it also changes the
  payment-timing wording (#18). The admin page previews the list under
  either state without changing anything.
- **Answers are plain text with a little markup** (`**bold**`,
  `[text](page.php)`, `- ` lists, blank line = paragraph), escaped first —
  typed HTML is shown, never run. Links may only be relative pages or http(s).
- **One writer:** `admin/faq-save.php` (POST + CSRF, admin or staff). One
  reader include: `includes/faqs.php`. The page is `admin/faq-management.php`.
- **Fail safe:** with the database unreachable the customer page says the FAQ
  is temporarily unavailable; the admin page says so and offers no form.
- **Import note:** the seed contains em dashes and curly quotes — import the
  schema with the client in UTF-8 (the file's `SET NAMES utf8mb4` handles
  a whole-file import; a piecemeal paste from a Latin-1 terminal does not).

## 20. Two-step verification (TOTP) — decided 2026-09-28
- **What:** after the password, a 6-digit code from an authenticator app — Google
  Authenticator (Play Store / App Store) or any TOTP app (RFC 6238: SHA-1, 30 s,
  6 digits). No Google account, API key or network call is involved.
- **Admin: required.** An account without it is sent to set-up on its next sign-in.
  An admin session only counts once it has passed the code (`tfa_passed`, set by the
  sign-in page); one without it is ended on its next request of any kind, pages and
  JSON endpoints alike (`venusep_session_start()`).
- **Staff: not included yet** (decided 2026-09-30). There is no staff side yet; it will
  be a copy of the admin side with some features restricted. Until then staff sign in
  with the password alone, see no 2FA screens or settings, and cannot turn it on.
  Bringing them in later is `tfa_required_for()` in `includes/two-factor.php`.
- **Customers: optional**, turned on and off from their profile. Turning it **on**
  needs the account password (a session alone must never add a credential: with no
  reset path, a stranger's phone on someone's account would lock them out for good);
  turning it off, moving to a new phone or making new recovery codes needs a current
  code or a recovery code.
- **Recovery codes only.** Ten single-use codes, shown once at set-up, regenerable
  while signed in. **No in-app reset — not even by an admin.** Keep at least two
  admin accounts, so one lost phone never locks the admin side.
- **Lockout on the account:** 5 wrong codes = 10s → 30s → 1m → 5m → 15m → 1h → 4h (max),
  in its own `users.totp_*` columns so a sign-in lock never blocks an admin action.
  Recovery-code attempts count too. The first five steps are the #16 ladder; the 1h and
  4h steps were added 2026-09-30: a real person never reaches them (any right code resets
  the ladder), while someone who already has the password drops from ~480 guesses a day
  to ~30. Locks always end on their own, so no reset is ever needed.
- **A code works once** (`users.totp_last_step`); one step of clock drift either way is accepted.
- **Secret stored as plain text** on purpose: encrypting it would put every account one
  lost key away from permanent lockout, with no reset path to recover.
- **Counter identity check unchanged** (password only): the customer is present and
  staff check a physical ID.
- **Demo seed:** `sp_seed_cast()` turns 2FA off for the demo customers on every reset.
  The admin keeps theirs (changed 2026-09-30): it is the presenter's own account, and
  resetting it would sign them out mid-demo.
- **A setup never overwrites another:** confirming a phone only succeeds if the
  account's 2FA is unchanged since that setup began (checked in the UPDATE), so a
  second browser can never replace a phone that was just registered.

**Runbook — someone lost their phone AND every recovery code** (needs database access):

    SET @u = (SELECT id FROM users WHERE email = 'person@example.com');
    CALL sp_reset_2fa(@u);

An admin sets it up again at their next sign-in; for a customer it is simply off.

---

## 22. Email, System Receipts and walk-in contact email — decided 2026-10-01
*(#21 is reserved for the Argon2id password change, planned before this one.)*
- **Account holders get no automatic email.** Their **VENUSeP System Receipt** is on a
  receipt page reached from My Bookings, with **Download PDF** and **Email me this
  receipt** (sent to the account's own address, shown before sending).
- **Walk-ins (no account, venue counter only)** may give an email at the counter. If they
  do, they are emailed a booking confirmation and, once staff confirm the payment, their
  System Receipt with the PDF attached.
- **`customers.contact_email`** holds that address. It is the one deliberate exception to
  #4's "email lives on `users`": a walk-in has no account yet still needs their paperwork.
  It stays NULL for account holders and is not verified — staff read it back to the guest.
- **No email-confirmation step** (considered and dropped): account holders only get email
  when they ask, to an address they see first.
- **Outbox (`email_outbox`):** an email is queued inside the staff action's transaction
  and sent after commit, so a mail server being down never fails or undoes the action;
  the row stays `failed` and staff press Resend. The body is stored when queued, so a
  resend is the identical email. `includes/mailer.php` is the only file that talks to
  the mail library.
- **Sending:** settings in `includes/mail-config.php`, git-ignored (template:
  `mail-config.example.php`). Laptops send into **Mailpit**, a local test inbox; only the
  demo machine holds the Gmail App Password. With no settings file nothing is sent: each
  email is written to a `.eml` file outside the web root.
- **System Receipt** (`system_receipts`): one per confirmed payment, contents frozen in
  `snapshot_json`; the number `VSR-<year>-<id>` is derived, never stored. It is **not** the
  Official Receipt from the University Cashier, which stays out of scope for now.
- **Issued for every confirmed payment**, emailed or not, inside the same transaction as
  the confirmation (`admin/booking-action.php`): if the receipt row cannot be written the
  confirmation rolls back, so a paid booking never lacks its receipt. A mail problem never
  rolls anything back.
- **One picture, three drawings:** the receipt page (`customer/receipt.php`), the emails and
  the PDF (`receipt-pdf.php`, FPDF — no `gd` needed) all draw from `receipt_view()`, so they
  cannot disagree. Phone, ID number and address are never on a receipt.
- **"Email me this receipt"** sends only to the account's own `users.email` (read from the
  database, never the request), after an on-page confirm, at most once per receipt every
  5 minutes. Someone else's receipt is a 404, like `document-view.php`.

---

## Open items (not yet decided)
1. **At-a-glance facts** — hard-coded per room, or editable room columns?
2. **Where each `system_settings` value is surfaced/edited in the UI** (deferred with the broader system_settings talk).

## Not changed by this discussion
The booking lifecycle, payment taxonomy meaning, CEDU/POS hostel flow, refund
document requirements, and per-venue GCash separation are as described in
`PROJECT-HANDOFF.txt`. This file only records the DB-shape decisions made on
2026-07-19.

---

## UPDATE — 2026-09-19: all locked decisions survived implementation

Every decision above was implemented as written. None needed reversing. Notes
where reality added detail:

**#2 Pricing** — the rate is snapshotted onto the booking at approval, not at
submission: the discount is earned by a *verified* USeP ID, so a new booking
stores the full price and `affiliation_verified = 0` until staff decide.

**#5 GCash accounts** — changing one inserts a NEW row and supersedes the old
(`valid_until`), so a receipt paid to last month's number can still be
explained. `uq_gcash_one_active_per_venue` keeps exactly one current.

**#6 Receipt verdicts** — the three verdicts hold. The engine still never
confirms a payment: the best outcome is `under_review` and a human matches the
reference. The verdict is now recomputed server-side rather than trusted from
the browser.

**#9 Amenities are PHP-coded, NOT tables** — honoured, and it needed one
refinement to survive admins adding rooms. The *vocabulary* (47 keys, labels,
icons) stays in `includes/amenities.php`. Only *which* keys a room has is
stored, in a `rooms.amenities` JSON column — **no table**, so the decision holds
literally. Keys, never labels: rewording a label updates every room instead of
orphaning them.

**#11 Maintenance** — one window shape, system-wide, as specified. The seed now
dates every window from `CURDATE()`, because the original hard-coded windows had
silently expired and taken "medium maintenance" and "a planned closure" out of
the demo with nothing to announce it.

**#16 Refund switch** — real throughout. Only a *completed payout* frees a
booking's date; withdrawal and denial leave it whole. Verified.

**#18 Payment timing** — `fn_payment_deadline()` is authoritative. PHP reads the
stored `bookings.current_deadline_at` rather than recomputing, after the two
disagreed on 37 bookings: the SQL has a branch PHP lacked, where a pre-pay
booking whose day-before deadline has passed becomes due the moment the event
starts.

### Added since
- `rooms.amenities` (JSON), `customers.photo_path`, `staff.photo_path`, and
  nullable `staff.venue_id` — four columns, in `venusep_migration_01.sql`.
- `system_settings.demo_mode` — a ROW, not a column, so removing demo mode later
  is one DELETE.
