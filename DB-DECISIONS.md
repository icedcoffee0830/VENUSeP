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
- **Hostel stays at the counter (decided and built 2026-10-27).** These work like venue
  counter bookings (`admin/booking-create.php`, `type=hostel`):
  - account holders confirm their identity with their password; walk-ins with no
    account pay at the counter;
  - staff confirm they inspected the ID, and the stay is approved on the spot;
  - each guest is named and gets an exact bed for every night;
  - there's no lead time, so check-in can be today.
  - ~~The POS step is kept: with refunds on, a stay waits in `await_pos`, and a
    same-day stay goes to cash at the desk (#18). With refunds off, it's paid after the
    stay.~~ **Replaced the same day by pay to use (#26):** the stay is only recorded
    once the cash is taken ("Cash received"), and it is written approved and paid.
  - The "who is this for" step lives in `includes/counter-booker.php`, shared by both
    booking pages.
  - In counter mode, every link keeps `counter=1`, and customer-only links (My bookings,
    Transaction history, refunds) are hidden. The calendar link goes to the staff
    calendar.

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
- **Built 2026-10-26** (migration 05, `customer/payment-submit.php`):
  - **Every upload is recorded**, rejected ones included, with its image and attempt number.
    Staff see the whole upload history on the booking. A rejected upload has no payment row.
  - **Duplicates** are judged against receipts that were **not** rejected (generated
    `active_reference` / `active_sha256` columns, each UNIQUE). A rejected upload stays on
    record but blocks nothing, so one misread never burns a genuine receipt's reference.
  - **5 attempts per booking** (`bookings.receipt_attempts_allowed`); every upload counts.
    An upload whose reference number cannot be read at all is **not** counted.
  - The **5th** goes to staff for manual review **whatever its verdict** (flag
    `attempt_limit_reached`, noted in the timeline). A **6th** is refused and not recorded.
  - The count **never resets** on its own. Staff may **"Allow 5 more attempts"**, only once
    all are used and payment is open again, with a **required note** written to the timeline.
  - **Staff rejection keeps the receipt blocked** (decided 2026-10-26). "Reject payment" leaves
    the checker's verdict as it was, so the reference and image stay taken everywhere. It
    records who decided, when and why on the receipt (`reviewed_by_user_id`, `reviewed_at`,
    `override_note`, flag `rejected_by_staff`).
  - **Masked receivers (2026-10-27).** Some GCash receipts hide the number
    (`+63 9•••••2358`) and the name (`FR••••S RO•••E H.`). OCR reads the dots
    as letters, e.g. `+63 Deceee2358` and `FReeeeS ROeeekE H.`, so a genuine
    receipt was flagged "receiver mismatch".
    - **Number** (browser and `ps_number_matches()` on the server): any
      non-digit counts as a hidden digit, and only the visible digits are
      compared. When OCR merged or split the dots, only the visible ends are
      compared. At least 4 visible digits are needed to say "match".
    - **Name** (browser only): the capital-letter runs are the evidence, and
      stray lowercase letters count as dots.
    - Staff still confirm every payment.
  - **Re-sending this booking's own earlier receipt** (same image, or same reference number)
    is refused **without counting an attempt** and without being recorded. The customer is
    told which attempt it was and what became of it. `customer/pay.php` warns before sending.
    A receipt from **another** booking still counts as a rejected duplicate.
- **"Pay now"** (`customer/pay.php`, built 2026-10-26): Booking History links every booking
  awaiting payment to its own payment page. Before it, the booking pages reached payment only
  through demo mode's simulate buttons, so a real customer could never upload a receipt.
  While demo mode is on, payments on **real** bookings are refused (nothing is written).

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
- **Same day, different time (decided and built 2026-10-27, migration 09).** A venue room
  can take several bookings on one date. Each keeps a **cleanup gap** before and after it:
  `system_settings.venue_cleanup_gap_minutes`, which is 60.
  - `sp_add_venue_slot` enforces the gap. The booking page reads the same setting: a
    booked date stays pickable and is marked "partly booked", hours inside the gap are
    greyed out, and a clash names the free windows.
  - Pricing stays **per day**. All venue rooms are covered; hostel beds are unaffected.
  - Both calendars show each venue booking's hours.
  - **Fixed at the same time:** a booking counted as "held" only while its payment
    deadline was in the future. That let a **paid** pre-pay booking be booked over on its
    event day. Lapsed holds are released by expiry instead (#18).

## 9. Amenities
- Amenities **and** attributes (the tap-able icon chips) are **hard-coded in PHP** → **remove the `amenities` and `room_amenities` tables.**
- "At a glance" facts (rate, bookable hours, best-for, catering, accessibility) are **not** amenities — a separate thing.
- **Decided and built 2026-10-27 (migration 10): every room detail is editable.**
  - Best-for, catering and accessibility are now room columns (`rooms.best_for` /
    `catering` / `accessibility`). NULL falls back to the old `$ROOM_GLANCE` text, and
    bookable hours stay the booking page's own (7 AM – 10 PM).
  - The room forms (`admin/room-form.php`, `admin/hostel-room-form.php`) load and save
    everything they show, through `admin/room-save.php`: capacity, rate, description,
    the maintenance window, and for hostel rooms the beds, comfort-room type and
    check-in/out times.
  - Rules that protect existing bookings:
    - a new rate reaches **new** bookings only;
    - lowering capacity names the upcoming bookings now over it, and keeps them;
    - beds with upcoming guests can't be removed (beds are retired, never deleted);
    - a room moves venue only with no upcoming live bookings, and only to the same
      venue type;
    - a closed window names the bookings inside it and cancels nothing.
  - Hostel prices are shown from each room's **own** rate. Before, a per-comfort-room
    table could show a different price than the server charged.

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
- **The hostel no longer follows this table (2026-10-27, #26).** Every hostel stay
  booked since migration 11 is *pay to use*, whatever the switch says. The table
  and the same-day rule below now apply to venues, and to hostel stays booked
  before migration 11.
- **Same-day hostel check-in (decided and built 2026-10-27, migration 06).**
  The hostel no longer has the 12-hour advance rule. A guest checks in and
  uses the room at once, so only a past check-in date is refused. Venues are
  unchanged: USeP Venues has no lead time, and Bahay Alumni keeps 12 hours.
  - While refunds are **on**, a same-day stay has no time for the POS from
    CEDU. It is booked as **cash**, `sp_approve_booking` approves it straight
    to `await_cash` (skipping `await_pos`), and it is paid at the **front desk
    on arrival**.
  - The deadline (`fn_payment_deadline`) is the **end of the check-in day**.
    It used to be 2 PM, which expired a booking made after 2 PM the moment it
    was made.
  - "Same-day" is decided again at approval, so a stay that reaches its
    check-in day while still pending takes the same route.
  - **Payment at the desk is cash only**, as at the venue cashier. A guest who
    wants GCash pays online in advance from Pay now, and staff confirm the
    upload as usual. Staff never record a GCash payment by hand.
- **Unapproved requests expire on their date (decided and built 2026-10-27,
  migration 07).** A request nobody approved becomes released / expired when
  its booking begins (`fn_request_expiry`). The same rule applies to both
  refund policies:
  - a venue expires at its first slot's start time;
  - a hostel stay expires at the end of its check-in day.

  Before this, a post-pay request never expired, and a pre-pay one expired a
  day early at a payment deadline it could not meet. Customers see
  **"Expired"** with the reason ("not approved before your event date, nothing
  was charged"). Staff see "Request expired (never approved)", and approving
  one is refused.
  - **Fixed at the same time:** the "unpaid pre-pay hold" rule used to release
    **paid** and under-review bookings on their event day, because their
    day-before deadline had passed. It now releases only bookings still
    waiting for payment.
  - **The rules now actually run.** The app calls `sp_expire_due_bookings()`
    before every booking or availability read
    (`venusep_expire_due_bookings()`, once per request, skipped in demo mode).
    Before, it ran only when someone made a new booking.

  - While refunds are **off**, nothing changes: the stay is paid after
    check-out, as before.

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

## 21. Password hashing: Argon2id — decided 2026-10-01, built 2026-10-04
- Every password hash is **Argon2id**, made only by `venusep_password_hash()`
  (`includes/passwords.php`), with OWASP's recommended minimum settings:
  **19 MiB memory, 2 passes, 1 thread**.
- Not heavier on purpose while the login pages have no password-attempt limit: every
  attempt costs this much server time and memory. Raise `VENUSEP_PASSWORD_OPTIONS` later;
  stored hashes upgrade on their owner's next login.
- **Old bcrypt hashes keep working** and are re-hashed to Argon2id at the next successful
  login (both login pages). No reset, no bulk job. The upgrade only replaces the exact hash
  that was checked, so a password changed meanwhile in another window is never overwritten.
- **Argon2id is required:** on a PHP build without it, registering and changing a password
  show a setup error and save nothing. Logging in still works.
- The demo seed keeps bcrypt hashes (plain SQL); they upgrade at first login.
- Teammates can check their PHP: open http://localhost/dashboard/phpinfo.php and find
  "Password hashing algorithms" — it must list `argon2id`.

---

## 22. Email, System Receipts and walk-in contact email — decided 2026-10-01
- **Account holders get no automatic email.** Their **VENUSeP System Receipt** is on a
  receipt page reached from My Bookings, with **Download PDF** and **Email me this
  receipt** (sent to the account's own address, shown before sending).
- **Walk-ins (no account, venue counter only)** must give an email at the counter (made
  required 2026-10-04; it was optional at first). They
  are emailed a booking confirmation and, once staff confirm the payment, their
  System Receipt with the PDF attached.
- **Exception: "Guest has no email"** (counter checkbox, 2026-10-04). Staff tick it for a guest
  without an address: nothing is emailed, the receipt is printed from **Download PDF**, and the
  booking history records "No email, receipt printed" (`counter_no_email`), so a missing email
  reads as a decision, not an omission.
- **`customers.contact_email`** holds that address. It is the one deliberate exception to
  #4's "email lives on `users`": a walk-in has no account yet still needs their paperwork.
  It stays NULL for account holders (and for walk-ins booked before it was required) and is
  not verified — staff read it back to the guest.
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

## 23. Customer sign-in with Google — decided 2026-10-05
- **Customers only.** "Continue with Google" on the login page, "Sign up with Google" on the
  register page. A staff or admin account is never signed in through Google, matched by
  Google id or by email.
- **Identity is the Google id (`users.google_sub`), never the email.** An email can be
  changed in the profile; the Google id cannot.
- **A new Google identity gets an account automatically**, after a one-time screen on the
  login page: name (pre-filled from Google), an optional mobile number (for GCash
  refunds), and the terms. The email comes from Google and is marked verified.
- **Google never gives us the person's password**, so such an account has
  **`users.password_hash` NULL** (migration 04). The login form tells them "This account
  uses Google sign-in". Changing a password, turning on two-step verification, and the
  counter identity check refuse plainly instead of failing on the missing hash.
- **An email already registered is never linked automatically.** VENUSeP does not verify
  emails at sign-up, so anyone could have registered someone else's address first;
  linking would let both people into one account. The customer is told to log in with
  their email and password.
- **Two-step verification still applies:** a customer who turned it on is asked for the
  code after Google, exactly as after a password.
- **The exchange:** one-time `state` (login CSRF), PKCE S256, and a `nonce` checked in the
  ID token; the token is fetched straight from Google's token endpoint over verified TLS,
  and its issuer, audience, expiry and `email_verified` are checked.
- **Settings:** `includes/google-config.php`, git-ignored (template:
  `google-config.example.php`). Without it the Google buttons are hidden.
- **Profile → Account Security** shows both ways of signing in. Adding or removing one
  always needs a fresh proof, never the session alone (built 2026-10-05):
  - **Set a password** (account made through Google): confirm with the linked Google
    account first; the form then works for 10 minutes, once.
  - **Connect Google** (password account): re-type the password, then go to Google. A
    Google account already linked to another VENUSeP account is refused; any Google
    account the customer owns may be linked, whatever its email.
  - **Disconnect Google**: re-type the password. Refused while Google is the only way in.
  - Two-step verification can only be turned on once the account has a password.
- **Counter:** a Google-only account has no password to type, so staff are told to have
  the customer set one, or book them as a walk-in.
- **Forgot password** (customers only, built 2026-10-05; `includes/password-reset.php`):
  - The form gives the **same reply** whether or not the email has an account.
  - The link's token is 32 random bytes; **only its SHA-256 is stored**
    (`password_resets`). It works **once, for 30 minutes**; a newer request cancels older
    links; at most **one email per account every 5 minutes**.
  - It is sent through the outbox (`kind = 'password_reset'`), then the link is
    **scrubbed from the stored email**. These emails are never resent; the customer asks
    again. Staff and admin accounts are never sent one.
  - The reset page moves the token into the session on the first visit and drops it from
    the URL, and sends no referrer.
  - It does **not** sign anyone in: the customer logs in afterwards (two-step code still
    asked). A Google-only account can get its first password this way too.
- **A password change ends the account's other sessions.** `users.password_changed_at`
  is set by a reset, a profile change, or a first password; a customer session that
  signed in before it is ended on its next request. The session that changed it in the
  profile stays signed in.
- **Remember me** (customers only, built 2026-10-05; `includes/remember-me.php`):
  - Ticking it (password or Google) keeps that device signed in for **15 days from
    sign-in**; using the site does not extend it. A remembered device **skips the
    two-step code**, because the token is only issued after the code was passed.
  - Cookie `selector:validator`, `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS; only the
    validator's SHA-256 is stored (`remember_tokens`). Every use replaces the validator;
    an old one coming back after a one-minute grace (for two tabs loading at once) means
    the cookie was copied, and **every remembered device of that account is forgotten**.
  - Forgotten: this device on **Log Out**; every device when the password is changed,
    reset or first set, or two-step verification is turned on or off. Refused (and
    deleted) for a suspended account or one whose password changed after it was made.
  - A remembered customer who opens the login page goes straight to the booking page.
- **Built in four rounds, all done 2026-10-05:** (1) sign-in and sign-up; (2) profile
  "Set a password", "Connect / Disconnect Google", and the counter message for Google-only
  accounts; (3) forgot password; (4) remember me (15 days).

## 24. Staff handle only their own venue — decided and built 2026-10-27 (migration 08)
- **Every staff account belongs to exactly one venue** (`staff.venue_id`). There are no
  unassigned staff:
  - creating a staff account requires a venue;
  - Staff Management offers no "Unassigned";
  - `trg_staff_venue_required_ins` / `_upd` refuse a staff row without a venue.
  - Migration 08 needs no preparation. Before adding the triggers, it places any staff
    account with no venue (or no staff profile row) in the first active venue, and
    prints who it placed so an admin can move them in Staff Management.
  - **Migration 13 (2026-10-28) is data, not structure.** It adds the team's new staff
    and admin accounts (jakemerson, macocase, francishubero) to every copy of the system:
    - the same passwords (the hashes are copied);
    - **no** two-step verification secret, so an admin sets up their own at first
      sign-in;
    - matched by email and venue code; an existing account is left alone.

    It was generated from Francis's database. Accounts added later need a new migration.
  Admins have no venue and handle every venue.
- **Enforced on the server now** (`staff_venue_scope()` / `staff_may_handle_venue()` in
  `includes/auth.php`). A staff member is refused (403) when they act on another venue's
  booking: approve, reject, payments, POS/OR, refunds, check-in (`booking-action.php`),
  counter bookings (`booking-create.php`), and email resends (`booking-emails.php`).
  The scope is re-read from the database on every request, so a move takes effect on the
  next click.
- **Not yet filtered:** lists and read-only views (queues, booking detail, documents) still
  show every venue. The staff UI will filter them with the same function.
- **Admins can create admins (2026-10-27).** The register page (`admin/admin-register.php`)
  offers Staff or Administrator.
  - An administrator gets no venue.
  - Creating one needs the **creator's own password** (`register-submit.php`
    `kind=admin`). It uses the same `users.reauth_*` lockout as the refund switch and
    the GCash accounts, so it is one shared budget.
  - The new admin sets up two-step verification at their first sign-in, through
    `admin-login.php`'s existing enrolment step.
  - Staff cannot create either kind of account.
- **Assigned Staff on the venue form is real.** "Add Staff" *moves* someone from their
  current venue, after a confirmation, and saves together with the venue. Only an admin
  may move staff. Nobody can be removed into "no venue". A venue may have no staff, in
  which case only admins handle its bookings.

## 25. Backup & restore — decided and built 2026-10-27 (no migration)
- **One file per backup:** `venusep-backup-YYYYMMDD-HHMMSS-<kind>.tar.gz`, kind `manual`,
  `pre-restore` or `uploaded`. It holds:
  - `database.sql`: mysqldump with routines, triggers and events, `--single-transaction`,
    so bookings keep working while it runs;
  - `private/`: IDs, receipts and the mail log (everything in the private folder except
    the backups themselves);
  - `photos/rooms`, `photos/covers`, `photos/avatars`;
  - `manifest.json`: app, kind, who made it, when, and the **layout version** (the
    highest `venusep_migration_NN.sql`).

  Not in it: the code (it is in git) and the Gmail / Google secrets.
- **Kept outside the web root** in `<private folder>/backups`. The newest 10 are kept;
  older ones are pruned. A `.json` beside each archive lets the list load without opening
  it.
- **Who may do what** (`admin/backup-action.php`, the only door to `includes/backup.php`):
  - staff and admins: **Back up now**;
  - admins only: Download, Delete and Restore, including restoring an uploaded file.

  Reason: a backup carries every ID and password hash, and a restore rewinds every account.
- **A restore:**
  - asks for the admin's own password, under the shared `users.reauth_*` lockout;
  - refuses a backup whose layout version differs from the system's;
  - refuses an archive with any file outside `database.sql`, `manifest.json`, `private/`
    and the three photo folders;
  - makes a **safety copy** (`pre-restore`) first, and does not restore without one;
  - imports the database, then replaces the file folders;
  - **signs everyone out**: it empties `remember_tokens` and deletes the PHP session files.
- **Automatic daily backups: on hold.** `backup_create()` is ready for a scheduled task
  when it is wanted.

## 26. The hostel is pay to use — decided and built 2026-10-27 (migration 11)
A guest pays **before** using the bed. Unlike a venue, the payment doesn't wait. This
applies whatever the refund switch says; venues still follow #18.
- **`bookings.pay_to_use`** is a snapshot like `refunds_allowed`. `trg_bookings_type_ins`
  sets it on every new hostel booking. The hostel stays booked before migration 11
  keep their old route (2 upcoming post-pay stays on 2026-10-27).
- **Online:**
  - the guest sends a request, and staff check the ID and discount as before;
  - no payment method is chosen at booking (`payment_method` is NULL);
  - `sp_approve_booking` opens payment at once (`await_gcash`, or `await_cash` if cash
    was chosen), with no POS wait;
  - the guest pays by GCash from Pay now, or in cash at the front desk on arrival;
  - payment is due by the **end of the check-in day** (`fn_payment_deadline`);
  - `sp_expire_due_bookings` releases a stay still unpaid then. A paid stay, or one
    with a receipt under review, is never touched, and none is ever marked "overdue".
- **Counter** (`admin/booking-create.php`): the stay is **not recorded unless paid**.
  - The last step is "Cash received — book it", behind a confirmation. It creates the
    stay already approved and `paid_cash`, with its payment row and System Receipt, in
    one transaction, even for a future date.
  - If anything fails, the stay is removed and nothing is charged.
  - It takes cash only; a guest who wants GCash books online.
  - The amount isn't typed; it is the total shown.
  - A walk-in with an email gets the System Receipt email only.
- **Front desk, for an online stay:** "Cash received" (`booking-action.php`
  `cash_received`) makes the stay a cash booking and marks it paid. It is refused while
  a GCash receipt is under review: staff confirm or reject that first.
- **The guest chooses cash, and can take it back (2026-10-28).**
  - "Cash received" is shown, and allowed by the server, only once the guest has
    chosen "Cash — hostel front desk" in Pay now (`await_cash`).
  - While that choice stands, GCash is closed for the guest. The option is
    disabled, and `payment-submit.php` refuses an upload.
  - The guest can press "Cancel cash payment" (`method=cancel_cash`). The stay goes
    back to `await_gcash`, with no method chosen, GCash opens again, and "Cash
    received" disappears.
  - A staff page opened before the cancel is refused and redraws without the button.
  - A cancel is refused once staff have recorded the cash.
  - Both choices are written to the booking's timeline.
  - **Venues work the same way (2026-10-28).**
    - A customer who chose "Cash — at the office" can cancel it. That includes cash
      picked when the booking was made.
    - GCash stays closed until they cancel.
    - A cancelled venue booking goes back to GCash; a hostel stay goes back to no method
      chosen.
    - Staff can no longer record a payment while a booking is waiting on GCash with no
      receipt (`confirm_payment` from `await_gcash` is refused). So a "Record cash
      payment" click on a page loaded before the cancel is refused, and the page redraws
      without the button. Staff never record a GCash payment by hand (#18).
  - `payment_confirm()` in `includes/system-receipt.php` is now the one way a payment
    is confirmed, shared by both paths.
- **Check-in is refused until the stay is paid** (`confirmed` / `paid_cash`). A receipt
  still under review is not paid.
- **The whole stay is paid at once.**
- **The POS and the OR are paperwork:** staff record them whenever CEDU and the Cashier
  issue them, and neither holds up payment or check-in. The staff page has a "Record
  POS" box for this.
- **Wording:** the hostel page, Pay now, the staff page and the queue now say "pay
  before you check in". The two built-in "How do I pay for a hostel bed?" FAQs were
  rewritten; an answer an admin had already edited is left alone.
- Tests: `tests/hostel_pay_to_use_db_test.php`.

## 27. In-app notifications — decided and built 2026-10-28 (migration 12)
A bell with an unread count sits in both shared top bars, so it appears on every signed-in
page:
- `includes/header.php`: admin and staff pages, and the customer account pages;
- `includes/customer-nav.php`: landing, venue list, booking pages and FAQ, plus staff in
  counter mode.

Notifications are in-app only; nothing is emailed.
- **The `notifications` table is everyone's inbox:** one row per person per event. The
  wording isn't stored; `includes/notifications.php` writes it when the bell is shown.
- **Who writes them:**
  - booking status changes: `trg_booking_timeline_notify` on the timeline row the
    status-change trigger already writes, so every page is covered;
  - a new online request: `customer/booking-submit.php`;
  - staff/admin accounts: `register-submit.php`, `admin/staff-save.php` and
    `admin/venue-save.php` (staff moved).

  All go through `sp_notify_customer` / `sp_notify_venue_staff` / `sp_notify_admins`.
  **Nobody is notified of their own action.** Walk-ins (no login), counter bookings and
  demo bookings produce none.
- **Who sees what:**
  - customers: their own bookings (approved, rejected, payment open, receipt rejected
    with the reason, payment confirmed, expired or released, refund decided);
  - staff of the booking's venue, and every admin: work to do (new request, receipt
    uploaded, guest chose or cancelled cash, refund requested);
  - admins: staff/admin accounts created, suspended, reactivated or moved. There is no
    "deleted": the system never deletes accounts.
- **"About to expire" reminders** (an unapproved request within 24 hours of its date) are
  worked out live, not stored. They show at the top for the venue's staff and admins,
  and disappear once the request is handled.
- **Unread until clicked.** A click marks the notification read and opens it:
  - Pay now while payment is open;
  - otherwise Booking History with that booking's details window (`?view=`);
  - for staff, the booking's page;
  - for account events, Staff Management.

  "Mark all as read" clears the lot. The bell checks `notifications.php` about once a
  minute, paused while the tab is in the background.
- **Read notifications older than 90 days are deleted;** unread ones are kept.
- **Not done:** notifications for venue and room detail changes (dropped: it would need
  its own log table).
- Tests: `tests/notifications_db_test.php`.

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
