<?php
/* =====================================================================
   VENUE ROOMS — the event-venue rooms (Bahay Alumni + USeP Venues),
   read from the database. THE ONE SOURCE, mirroring hostel-rooms.php.

   Included by:
     customer/room-reservation.php      (json_encode'd into the booking page)
     customer/venusep_venue_booking.php (the landing list)
     admin/venue-management.php         (the room cards)

   WHY ONE SOURCE: these rooms used to be hand-written SEPARATELY on every
   page and had already drifted into four different room universes — the
   booking page had eight rooms, admin Venue Management managed three
   DIFFERENT ones, and the history pages listed a third set nobody could
   book. One source ended that; the database now ends it permanently.

   WHAT COMES FROM WHERE
     rooms + venues            name, venue, description, active
     event_room_details        capacity, fee per day
     rooms.amenities           amenity KEYS -> labels via amenities.php
     includes/amenities.php    the amenity VOCABULARY + the at-a-glance
                               facts, both PHP-coded on purpose (#9)
     maintenance_windows       the closure, if any
     venue_booking_slots       what is already taken

   AVAILABILITY MODEL — a venue room is booked as an exclusive TIME RANGE:
       booked: [ {date, start, end} ]      -> the slot is taken or it is not
   (The hostel's model is different on purpose — per-night BED counts. Do
   not unify the two; an event has hours, a bed has nights.)

   MAINTENANCE — {from, until, reason, blocks}. `until:null` = indefinite;
   `blocks:true` = hard (cannot book), false = medium (bookable, the
   customer is just told). There is NO `status` field: "Available" /
   "Occupied" were only ever `booked[]` wearing a word.

   FAILS LOUD. This file used to fall back to hard-coded rooms when the
   database was unreachable, which was right when the database supplied
   only the name. It now supplies the rooms, the prices and what is taken
   — and a booking page drawn from invented availability would sell a room
   that is not free. Better to refuse to draw it.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/amenities.php';

$vrPdo = venusep_db_or_fail();

/* ---- 1. the rooms themselves ---- */
$vrRoomRows = $vrPdo->query(
  "SELECT r.id, r.room_code, r.name, r.description, r.amenities,
          v.name AS venue_name,
          d.attendee_capacity, d.fee_per_day
     FROM rooms r
     JOIN venues v                  ON v.id = r.venue_id
     LEFT JOIN event_room_details d ON d.room_id = r.id
    WHERE r.room_type = 'event' AND r.is_active = 1
    ORDER BY r.id"
)->fetchAll();

/* ---- 2. closures, every room in ONE query (never N+1 inside the loop) ----
   The room array carries ONE maintenance window because the UI shows one
   line. A room may legitimately have several rows, so pick the one that
   matters now: a window covering today, otherwise the next one due to
   start. Anything already finished is ignored — a closure that has ended
   is not news, and this is exactly how a fixed window self-heals. */
$vrMaint = [];
$vrMaintRows = $vrPdo->query(
  "SELECT m.room_id, m.from_date, m.until_date, m.reason, m.blocks_booking
     FROM maintenance_windows m
     JOIN rooms r ON r.id = m.room_id
    WHERE r.room_type = 'event'
      AND (m.until_date IS NULL OR m.until_date >= CURDATE())
    ORDER BY m.room_id,
             (m.from_date <= CURDATE()) DESC,   -- a live window beats a planned one
             m.from_date ASC"
)->fetchAll();
foreach ($vrMaintRows as $m) {
  if (isset($vrMaint[$m['room_id']])) continue;          // first per room wins
  $vrMaint[$m['room_id']] = [
    'from'   => $m['from_date'],
    'until'  => $m['until_date'],                        // null = indefinite
    'reason' => $m['reason'],
    'blocks' => (bool) $m['blocks_booking'],
  ];
}

/* ---- 3. what is already taken, every room in ONE query ----
   These conditions MIRROR sp_add_venue_slot()'s conflict check, so what a
   customer is shown as taken is exactly what the database would refuse. If
   the two ever drifted, a customer could pick a slot this page called free
   and have the booking rejected at the last step. */
$vrBooked = [];
$vrSlotRows = $vrPdo->query(
  "SELECT s.room_id, s.slot_date, s.start_time, s.end_time
     FROM venue_booking_slots s
     JOIN bookings b ON b.id = s.booking_id
    WHERE s.released_at IS NULL
      AND b.reservation_status IN ('pending','approved')
      AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW())
    ORDER BY s.slot_date, s.start_time"
)->fetchAll();
foreach ($vrSlotRows as $s) {
  $vrBooked[$s['room_id']][] = [
    'date'  => $s['slot_date'],
    'start' => substr($s['start_time'], 0, 5),           // '08:00:00' -> '08:00'
    'end'   => substr($s['end_time'], 0, 5),
  ];
}

/* ---- 4. assemble the exact shape every page has always consumed ---- */
$venueRooms = [];
foreach ($vrRoomRows as $r) {
  $glance = room_glance($r['room_code']);
  $venueRooms[] = [
    'id'          => $r['room_code'],
    'name'        => $r['name'],
    'venue'       => $r['venue_name'],
    'capacity'    => (int) $r['attendee_capacity'],
    'maintenance' => isset($vrMaint[$r['id']]) ? $vrMaint[$r['id']] : null,
    'fee'         => (int) $r['fee_per_day'],
    'description' => (string) $r['description'],
    'amenities'   => amenity_labels($r['amenities']),
    'bestFor'     => $glance['bestFor'],
    'catering'    => $glance['catering'],
    'accessible'  => $glance['accessible'],
    'booked'      => isset($vrBooked[$r['id']]) ? $vrBooked[$r['id']] : [],
  ];
}
unset($vrRoomRows, $vrMaintRows, $vrSlotRows, $vrMaint, $vrBooked, $vrPdo);

/* Rooms under a given venue — the landing page groups by venue. */
function venueRoomsFor(array $rooms, $venue) {
  return array_values(array_filter($rooms, function ($r) use ($venue) { return $r['venue'] === $venue; }));
}

/* Does a maintenance window cover this date? Same rule as the hostel + the
   customer JS — one shared shape, never a second maintenance concept. */
function venueMaintCovers($m, $date) {
  return $m && $date >= $m['from'] && ($m['until'] === null || $date <= $m['until']);
}
