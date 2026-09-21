# VENUSeP — ERD Mapping Guide

A drawing guide for redrawing `venusep_erd.png` / `venusep_erd.svg` after the
2026-09-16 schema update. Every connection below was read from the live
database's foreign keys, so the drawing will match `venusep_schema.sql`.

The text version (`venusep_erd.md` / `venusep_erd.mmd`) is already updated.
Paste the `.mmd` into <https://mermaid.live> for a reference image to copy the
layout from.

---

## 1. What changed (update these on the drawing)

| Change | What to draw |
|---|---|
| **REMOVE** `AMENITIES`, `ROOM_AMENITIES` | Delete both boxes and their lines (amenities are hard-coded in PHP) |
| **REMOVE** `BOOKING_OCCUPANTS` | It is `HOSTEL_OCCUPANTS` now (same lines, new name) |
| **ADD** `SYSTEM_SETTINGS_HISTORY` | New box, 2 lines (see section 3, group G) |
| `SYSTEM_SETTINGS` | Add `updated_by_user_id (FK)` + a line to `USERS` |
| `USERS` | Add `reauth_failed_attempts`, `reauth_lock_level`, `reauth_locked_until` |
| `BOOKINGS` | Add `refunds_allowed` |
| `REFUNDS` | Add `reason_category`, `refund_to_number`, `official_receipt_pending`, `correction_attempts`, `open_booking_id (UK)` |

If your old image already shows `RESERVATION_STATUSES` / `PAYMENT_STATUSES` as
lookup tables and `CUSTOMERS` with its own `id`, those parts are fine as they are.

---

## 2. How to read the cardinality

| Symbol | Read as | Example |
|---|---|---|
| `||--o{` | one → zero or many | one venue has many rooms |
| `||--o|` | one → zero or one | one room has at most one event-details row |

**Rule of thumb:** the table that **holds the FK column** is the "many" (or
"zero-or-one") end. The table the FK **points to** is the "one" end.

---

## 3. Every connection, grouped (draw group by group)

Format: **child.fk_column → parent.column** · cardinality · label

### A. Accounts
| Connection | Card. | Label |
|---|---|---|
| `customers.user_id → users.id` | users 1 — 0..1 customers | may log in as (NULL = walk-in) |
| `staff.user_id → users.id` | users 1 — 0..1 staff | may log in as |
| `staff.venue_id → venues.id` | venues 1 — many staff | assigned to (NULL = unassigned/admin-wide) |

### B. Venues and rooms
| Connection | Card. | Label |
|---|---|---|
| `rooms.venue_id → venues.id` | 1 — many | contains |
| `gcash_accounts.venue_id → venues.id` | 1 — many | paid via |
| `event_room_details.room_id → rooms.id` | 1 — 0..1 | event specs |
| `hostel_room_details.room_id → rooms.id` | 1 — 0..1 | hostel specs |
| `hostel_beds.room_id → rooms.id` | 1 — many | has |
| `room_media.room_id → rooms.id` | 1 — many | media |
| `maintenance_windows.room_id → rooms.id` | 1 — many | closures |

### C. Bookings (the centre of the diagram)
| Connection | Card. | Label |
|---|---|---|
| `bookings.customer_id → customers.id` | 1 — many | makes |
| `bookings.room_id → rooms.id` | 1 — many | booked as |
| `bookings.reservation_status → reservation_statuses.code` | 1 — many | reservation state |
| `bookings.payment_status → payment_statuses.code` | 1 — many | payment state |

### D. Venue booking branch
| Connection | Card. | Label |
|---|---|---|
| `venue_booking_details.booking_id → bookings.id` | 1 — 0..1 | venue header |
| `venue_booking_slots.booking_id → bookings.id` | 1 — many | per-day slots |
| `venue_booking_slots.room_id → rooms.id` | 1 — many | held on |

### E. Hostel booking branch
| Connection | Card. | Label |
|---|---|---|
| `hostel_booking_details.booking_id → bookings.id` | 1 — 0..1 | hostel header |
| `hostel_occupants.booking_id → bookings.id` | 1 — many | named guests |
| `bed_reservations.occupant_id → hostel_occupants.id` | 1 — 0..1 | assigned to |
| `bed_reservations.bed_id → hostel_beds.id` | 1 — many | reserved |
| `bed_reservations.booking_id → bookings.id` | 1 — many | holds |
| `bed_reservation_nights.bed_reservation_id → bed_reservations.id` | 1 — many | one per night |
| `bed_reservation_nights.bed_id → hostel_beds.id` | 1 — many | occupied on |

### F. Money and documents
| Connection | Card. | Label |
|---|---|---|
| `payments.booking_id → bookings.id` | 1 — many | paid via |
| `gcash_receipts.payment_id → payments.id` | 1 — many | receipt(s) |
| `booking_documents.booking_id → bookings.id` | 1 — many | ID / POS / OR |
| `refunds.booking_id → bookings.id` | 1 — many | refunded by (only ONE open at a time) |
| `refunds.payment_id → payments.id` | 1 — 0..1 | of |
| `booking_timeline.booking_id → bookings.id` | 1 — many | audit trail |

### G. Settings and the refund switch  ← NEW
| Connection | Card. | Label |
|---|---|---|
| `system_settings_history.setting_key → system_settings.setting_key` | 1 — many | change log |
| `system_settings_history.changed_by_user_id → users.id` | 1 — many | changed by |
| `system_settings.updated_by_user_id → users.id` | 1 — many | last changed by |

---

## 4. Lines you may leave OFF the drawing (the current ERD omits them)

These are real FKs, but drawing them all makes `USERS` a spiderweb. They are
"who did it" audit columns, or shortcuts to a table already reachable through a
parent. Mention them in a footnote instead.

- To `users.id`: `bookings.updated_by_user_id`, `booking_documents.uploaded_by_user_id`,
  `booking_documents.verified_by_user_id`, `booking_timeline.performed_by_user_id`,
  `gcash_accounts.created_by_user_id`, `gcash_receipts.reviewed_by_user_id`,
  `maintenance_windows.created_by_user_id`, `payments.confirmed_by_user_id`,
  `refunds.requested_by_user_id`, `refunds.reviewed_by_user_id`,
  `room_media.uploaded_by_user_id`
- Shortcuts: `gcash_receipts.booking_id → bookings`, `bed_reservation_nights.booking_id → bookings`
- `bed_reservations.booking_id → hostel_occupants.booking_id` (the second half of a
  composite key that guarantees the guest belongs to the same booking)

`system_settings.updated_by_user_id` is technically an audit column too. Draw it
anyway if you want the refund switch's "last changed by" to show; otherwise
footnote it with the others.

---

## 5. Suggested layout

```
            USERS ─────────────── SYSTEM_SETTINGS ── SYSTEM_SETTINGS_HISTORY
           /     \
     CUSTOMERS   STAFF

  VENUES ── ROOMS ──┬── EVENT_ROOM_DETAILS          RESERVATION_STATUSES
     │              ├── HOSTEL_ROOM_DETAILS         PAYMENT_STATUSES
  GCASH_ACCOUNTS    ├── HOSTEL_BEDS                        │
                    ├── ROOM_MEDIA                         │
                    └── MAINTENANCE_WINDOWS                │
                            │                              │
     CUSTOMERS ─────────► BOOKINGS ◄───────────────────────┘
                     ┌──────┼───────────────┬──────────────┐
            venue branch  hostel branch   money/docs     audit
          VENUE_BOOKING_  HOSTEL_BOOKING_  PAYMENTS ─ GCASH_RECEIPTS   BOOKING_TIMELINE
          DETAILS         DETAILS          BOOKING_DOCUMENTS
          VENUE_BOOKING_  HOSTEL_OCCUPANTS REFUNDS
          SLOTS           BED_RESERVATIONS
                          BED_RESERVATION_NIGHTS
```

Put `BOOKINGS` in the middle. Inventory (venues/rooms/beds) goes above it, and
the two booking branches plus money go below. Keep the settings group in a
corner: it doesn't connect to bookings by a line. The link is the
`refunds_allowed` copy, made when a booking is created, so add a footnote for it.

**Count check:** 27 tables (+ 5 views, which are not drawn on an ERD).

---

## UPDATE — 2026-09-19: the diagram is accurate, with three new columns

The ERD above still matches the schema. Every relationship it draws is real and
now carries live data. Four columns were added during the database phase
(`venusep_migration_01.sql`). The first three do not change relationships;
`staff.venue_id` adds the nullable staff-to-venue line shown above:

| Table | Column | Why |
|---|---|---|
| `rooms` | `amenities` JSON | Which amenity KEYS a room has. The vocabulary stays PHP-coded (DB-DECISIONS #9), so this adds **no table** and no new line on the diagram. |
| `customers` | `photo_path` | Profile picture only. |
| `staff` | `photo_path` | Profile picture only. |
| `staff` | `venue_id` | Nullable current venue assignment; deleting a venue sets it to NULL. |

Plus one settings ROW, not a column: `system_settings.demo_mode`.

### Worth knowing when reading the diagram

**`booking_documents` and `gcash_receipts` hold PATHS, not files.** The bytes
live **outside the web root** (`includes/documents.php`), reachable only through
`document-view.php`, which checks the session first. Profile pictures are the
opposite case and sit under `assets/` like any other image. The diagram cannot
show that difference, and it is the most important thing about those two tables.

**`customers.user_id` is nullable and that is load-bearing.** A NULL is a
walk-in — a real customer with no login, booked at the counter by staff. The
seed includes one.

**Statuses are lookup TABLES, not enums** (`reservation_statuses`,
`payment_statuses`), and each row carries BOTH a `staff_label` and a
`customer_label`. That is why staff see "Awaiting POS - CEDU" where the customer
sees "Awaiting POS" — neither side writes its own wording.

**The two availability branches are genuinely different shapes**, as §D and §E
describe: `venue_booking_slots` holds exclusive time ranges, while
`bed_reservation_nights` holds one row per occupied night per bed. Do not
redraw them as one.
