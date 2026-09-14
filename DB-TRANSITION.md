# VENUSeP — Database Transition Notes

This mockup is UI + `[SIM]` demo data only. There is **no server-side logic and no
persistence** — every "submit" just changes a screen, and every list is hand-written
demo data. That is expected. This file records the things a mockup *cannot* show but
the database build **must** handle, plus the decisions already baked into the demo so
the schema matches it.

Read this before designing tables.

---

## The pattern to copy: one source per entity
Two entities are already modelled the right way — one PHP file that every page reads,
so nothing can drift. Make every other entity work the same way; these become your
seed data:

- `includes/venues.php` → `$venues` (the venue LIST — a venue exists whether or
  not it has rooms; this becomes the `venues` table). The room-form "Location"
  dropdowns and the Venue Management filter all render from it, so adding a venue
  here shows up everywhere automatically. In the DB: `SELECT name FROM venues`.
- `includes/venue-rooms.php` → `$venueRooms` (Bahay Alumni + USeP Venues, r1–r8).
  Each room's `venue` is an FK to a `venues` row.
- `includes/hostel-rooms.php` → `$hostelRooms` (USeP Hostel, h1–h5)
- `includes/payment-settings.php` → `$gcAccounts` (one GCash account per venue)

Booking page, landing page and admin Venue Management all read these. **Do not
re-introduce per-page room lists** — that was the exact bug just removed (four
different room universes).

---

## 1. The customer submit must INSERT the row the admin queue SELECTs  🔴 core
Right now `submitRequest()` (both `customer/room-reservation.php` and
`customer/hostel-reservation.php`) only flips a screen. The admin queue
(`admin/booking-requests.php` → `admin/booking-request.php`) is hand-written demo rows.
**They are not connected.**

The single most important schema decision: a customer booking is a row in ONE
`bookings` table, and the admin queue is a `SELECT` over that same table. Design them
together, not as two independent shapes. A customer submit = `INSERT`; staff actions =
`UPDATE`s to the status columns.

## 2. One booking ID, owned by the DB  🔴
Today there are four ID namespaces that never meet:
`USEP-######` (venue customer) · `USEP-H-######` (hostel customer) ·
`BRQ-####` (admin) · `VB-2026-###` / `TXN-2026-###` (history/transactions).
All are `Math.random()` or hand-written. **The database owns the ID** (auto-increment
or UUID); any customer-facing reference is a display alias derived from it, not a
second identity. Pick one scheme and use it end to end.

## 3. Concurrency — the integrity rules a single-user mockup cannot show  🔴
- **Venue double-booking.** Availability is read from `booked:[{date,start,end}]`. Two
  customers can both see a slot free and both submit. Enforce a **unique constraint or
  a transactional lock** on the (room, date, time-range) at confirmation — the DB, not
  the browser, is the referee.
- **Hostel last-bed race.** `beds_taken = COUNT(occupants)`. Two people grabbing the
  6th bed at once both see 5 taken → the room goes to 7/6. The bed claim must be
  **transactional** (`SELECT … FOR UPDATE`, or a per-(room,night) counter with a
  `CHECK (taken <= beds)` constraint). This is the hostel's central integrity rule and
  it is invisible in the mockup.
- **GCash reference reuse.** The duplicate-receipt check uses `localStorage` — per
  browser. In the DB it must be a **UNIQUE index on the reference number across all
  bookings**, or the same receipt reused from another device passes.

## 4. Re-verify everything server-side  🔴
All validation here is client-side JS (dates, amounts, bed limits, required ID) and the
**GCash receipt verdict is computed in the browser** (`includes/gcash-checker.php`,
Tesseract + `evaluateReceipt()`). For real money that is spoofable. The backend must
**re-run the amount/receiver/reference checks server-side** (or keep the staff
confirmation as the real gate and treat the client verdict as a hint only). Client
checks are UX, not authority.

---

## Availability: two shapes on purpose — keep them separate
- **Venue** = exclusive **time ranges**: `booked: [{date, start, end}]`. An event has hours.
- **Hostel** = per-**night bed counts**: `occupied: {night: [occupants]}`. A bed has nights.

These are genuinely different tables. **Do not unify them.** Nights are *exclusive* of
check-out (`Aug 1 → Aug 4` = 3 nights); venue days are *inclusive* (`Aug 1 → Aug 3` =
3 days). `beds` is never stored — it is `COUNT(occupants)`.

## Maintenance — already one consistent shape
`{from, until, reason, blocks}` on every room, customer + admin identical.
`until = null` = indefinite (nullable column). "Available"/"Occupied" are **not**
stored — a room's state is derived from `booked[]`/`occupied[]` + the window. Keep it
that way. The indefinite-closure "review nag" is derived from age today; a real
`review_on` date column is optional (add when someone complains about being nagged).

---

## Decisions already baked into the demo (match these in the schema)
- **Payment methods = GCash + Cash only.** No Bank Transfer, no Maya. The checker
  verifies GCash receipts; cash is confirmed by staff. `payment_method ENUM('gcash','cash')`.
- **Two-axis status** (already the shape): a `reservation_status` and a
  `payment_status`, not one blended column. Staff see the full taxonomy
  (`locked / await_gcash / await_pos / confirmed / paid_cash / overdue / refund_* …`);
  customers see softened labels (`Pending / Approved / Completed / Cancelled /
  Rejected` + `Paid / Pending / Unpaid / Refunded`). One machine, two vocabularies.
- **Hostel adds `await_pos`** — the one status that waits on another office (CEDU), not
  the customer. And the **OR is a document, not a payment status** — a nullable
  `official_receipt_no` recorded *after* `confirmed`, plus a check-in flag.
- **One GCash account per venue** (`payment-settings.php`). The payment screen and the
  checker both read it, so they can never disagree. Bahay Alumni and USeP Venues are
  **separate** accounts (they used to share one).
- **Session user** drives the customer side (demo: Juan Miguel Dela Cruz). Booking,
  history and profile all read the one logged-in customer.

---

## Designed but NOT built — reserve room for these now
- **Disruption / involuntary refund** (room hard-closed with paid bookings inside →
  replacement room or full, non-deniable refund). Introduces a `disrupted` reservation
  state. **Reserve the enum value** even though the flow isn't built, or adding it later
  is a migration. See memory `room-maintenance-model`.
- **Customer-initiated cancel / refund request.** The admin refund chain exists; there
  is no customer button to start it. booking-history has a "Cancelled" *filter* only.
- **Hostel POS has no deadline.** If CEDU never issues a POS, the booking (and held
  beds) sit forever. Decide whether held beds expire, like the venue pay-by deadline.
- **Email is UI-only.** Every "Transaction receipt emailed" / "OR emailed" is `[SIM]`.
  Needs a real notification/email service; the pages already promise it.
- **Overdue auto-release, 48-h resubmit window, tiered override permissions** exist as
  spec/taxonomy but need a scheduler + a real staff-role model.

## Placeholders to replace before go-live
- **USeP Venues GCash** (`Ramon T Villaflor`) and the **hostel GCash number** — invented
  placeholders in `payment-settings.php`. Get the real accounts.
- **CEDU / University Cashier** names, **check-in/out times**, room **photos** and the
  single shared **360 panorama** (`SAMPLE_PANO`) — all placeholders; real images/assets
  need storage (path/URL columns).

## Refund requests — decided 2026-09-09, built as UI only  🔴

The customer refund flow (`customer/refund-request.php`, reached from
`booking-history.php`) is a **mockup with no POST, no DB write and no stored
upload**. The rules below were settled with the team; the schema does not yet
express them.

**The rules, so nobody has to re-derive them:**
- **Eligibility** = reservation `approved` + payment paid + the event has not yet
  happened. Same one-day-before cutoff as the payment deadline
  (`PROJECT-HANDOFF.txt` 4.7), and `cb_is_refundable()` already expresses it.
  ⚠️ The mockup's single `Paid` collapses **two** real statuses — the condition is
  `payment_status IN ('confirmed','paid_cash')`. Writing `= 'confirmed'` alone
  silently locks every cash payer out of refunds.
- **The booking is NOT cancelled when the request is filed.** It stays the
  customer's for the whole process; the date is released only when the refund is
  **completed**. A denied request leaves the booking untouched.
- **Withdrawal** is allowed at any point before staff decide.
- **Denial is final** — the customer would rebook from scratch. Therefore a
  *paperwork* problem is NOT a denial: staff **return it for correction** with a
  48-hour window (mirrors the existing rejected-receipt resubmit window) and a
  capped number of attempts (mirrors the 5-receipt rule, DB-DECISIONS #6).
- **Documents**: transaction receipt + proof of payment are required to FILE. The
  **Official Receipt is required to be PAID** but may follow later — it needs a
  trip to the Cashier, and gating submission on it would let an honest customer
  miss the filing deadline on paperwork timing alone. Same principle as 4.8: the
  OR is a document, not a status. A missing OR blocks payment, **never** a
  decision — staff can deny on the merits immediately.
- **Amount** is always the full amount paid; the customer never types one.
  Staff set `refunds.amount_approved`.
- **Venue bookings only.** Hostel refunds are a separate decision.

**Schema gaps this creates (none of it exists yet):**
- `payment_statuses` has no **`refund_denied`** code — `admin/booking-request.php`
  already has a working Deny button with nowhere to write. Also needs codes for
  **returned-for-correction** and **awaiting-Official-Receipt**.
- `refunds.refund_status` (`requested/under_review/approved/rejected/completed`)
  has no value for **returned for correction** or **withdrawn**.
- No **attempt counter** for corrections — copy `gcash_receipts.attempt_no`.
- No **unique constraint on `refunds.booking_id`**, so nothing stops a second
  request being filed for the same booking by revisiting the URL.
- The reason **category + free text** the form collects needs columns; the admin
  panel renders them, so they must survive to the DB.

**Security — the current page is UI only and none of this is done yet:**
- 🔴 **Scope the lookup by customer.** `cb_find()` searches only the session
  customer's own array, so the mockup is safe by construction. The moment it
  becomes `SELECT … WHERE id = ?` **without `AND customer_id = ?`** it is an IDOR
  that lets anyone file a refund against someone else's booking. Highest-risk
  line in the feature.
- 🔴 **Re-run every validation server-side.** Reason, minimum length, required
  documents and the confirmation checkbox are all enforced in JavaScript only —
  the same weakness already flagged for the GCash checker (4.6).
- 🔴 **Make it a POST with a CSRF token.** It changes booking state.
- 🔴 **Validate uploads**: MIME type, size cap, randomised filenames, stored
  outside the web root.
- ✅ Already handled: `?booking=` is type-guarded and length-capped, and every
  echo goes through `bh_e()`.

### The refund store is a temporary wire, not a design  ⚠️ DELETE AT DB TIME

`includes/refund-store.php` exists only because there is no database. A refund
the customer files has nowhere to live, so staff would never see it — the two
mockups seed their bookings independently (customer `VB-2026-…`, admin
`BRQ-…`), and nothing connects them. The store carries the request across in
`localStorage`, which every page on this origin shares, and carries the staff
decision back.

- Its four calls (`open` / `withdraw` / `decide` / `markORProvided`) each become
  a row in `refunds` plus a `bookings.payment_status` change. **No decision lives
  in that file** — the rules are all above.
- It is per-browser and per-machine. Clearing site data resets the demo; a second
  computer sees nothing. Never treat it as storage.
- `admin/booking-requests.php` and `admin/booking-request.php` now
  `require_once includes/customer-bookings.php` for the same reason — the admin
  side has no other way to see the customer's bookings. At DB time both sides
  SELECT the same `bookings` rows and that include goes too.
- `admin/booking-request.php` no longer falls through to a default record for an
  unknown `VB-` reference (it showed a different customer's booking). The `BRQ-`
  fallback is untouched and **still has that bug** — worth fixing when the page
  is wired up.

## The USeP discount — built as UI 2026-09-09  🔴

Maps onto fields the schema already has: `bookings.is_usep_affiliated`,
`affiliation_verified`, `room_price`, `discount_percent`, `discount_amount`,
`total_amount`, and `system_settings.discount_percent` (seeded 20).
`includes/pricing.php` is the mockup's stand-in for that setting and **goes away**
when the DB arrives.

**The rule, as agreed:**
- A booking is discounted only when the customer uploads a **USeP ID and staff
  verify it**. The claim alone grants nothing.
- ⚠️ **A `usep.edu.ph` account must NOT grant the discount.** It is shown to staff
  as supporting evidence only. **Registration does not verify the email address** —
  no confirmation link, no token — so anyone could sign up as
  `someone@usep.edu.ph` and take 20% off every booking. On a ₱15,000 hall that is
  ₱3,000 a time, repeatable, and it looks like an ordinary booking.
  *If email verification is added later*, a **verified** USeP address may become a
  fast path that skips the staff step. Not before.
- The rate is **snapshotted at booking time**; changing the live rate must never
  reprice an existing booking (DB-DECISIONS #2).
- `total_amount` is the **discounted** total, and the GCash checker validates that
  stored figure — the checker never applies a discount itself. The mockup mirrors
  this: `totalFee` / `total` are discounted, so `gcExpectedCentavos()` is right
  without knowing discounts exist.
- Applies to **venue and hostel** alike.
- Approval has **three** outcomes, because two questions are being answered — is
  the ID valid, and does it prove affiliation? A valid driver's licence is yes to
  the first and no to the second: *approved, at full price*, not rejected.
- Alumni and organisation accounts are staff's call case by case.

**Still to wire:** the customer's claim → `is_usep_affiliated`, the staff decision
→ `affiliation_verified`, and the locked figures → `room_price` /
`discount_percent` / `discount_amount` / `total_amount` at approval.

**Note:** the session customer's details are now duplicated in four places
(`customer-profile.php`, `includes/customer-bookings.php`, the admin seeds, and
`ACCOUNT` in both booking pages) and have already drifted once. They now feed a
price, so they should become one `customers` row at DB time.

### Discount rate setting — built as UI 2026-09-14  🔴

Admin **Venue Management** now edits the USeP discount rate (DB-DECISIONS #2:
*dynamic, staff-editable*). It sits above the venue cards — where the fees are —
and ABOVE them rather than inside one, so it reads as global. Mockup mechanics: the value is a **cookie** (the only
browser store both the PHP-rendered listing and the JS booking pages can read),
the history is `localStorage`. `includes/pricing.php` validates the cookie hard
(digits, 0–100) and falls back to 20. All of it goes at DB time.

**Keep at DB time:**
- **A change is an event, not an overwrite** — who, when, from, to, why. The UI
  already requires a reason. `system_settings` holds only the current value, so
  add a `system_settings_history` (or equivalent) beside it.
- **A change affects NEW bookings only.** Every booking snapshots its rate
  (`bookings.discount_percent`); nothing here may reprice an existing row. The
  screen says so in bold.
- **Admin only.** Agreed 2026-09-14: staff will **request** a change and an admin
  approves it. That flow waits on a staff UI that does not exist yet. When it
  does, a request is this same event record with a `pending` status — no new
  concept needed. Until then the mockup labels the control "Admin only"; there
  is no role distinction to enforce it with.
- `0` is a valid value and means "discount off".
