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
