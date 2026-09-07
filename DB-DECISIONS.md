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

---

## Open items (not yet decided)
1. **At-a-glance facts** — hard-coded per room, or editable room columns?
2. **Where each `system_settings` value is surfaced/edited in the UI** (deferred with the broader system_settings talk).

## Not changed by this discussion
The booking lifecycle, payment taxonomy meaning, CEDU/POS hostel flow, refund
document requirements, and per-venue GCash separation are as described in
`PROJECT-HANDOFF.txt`. This file only records the DB-shape decisions made on
2026-07-19.
