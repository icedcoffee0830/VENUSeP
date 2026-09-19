<?php
/* =====================================================================
   USeP HOSTEL — rooms + the availability model, read from the database.
   THE ONE SOURCE, mirroring includes/venue-rooms.php.

   Included by:
     customer/hostel-reservation.php    (json_encode'd into the booking page)
     customer/venusep_venue_booking.php (the landing list)
     admin/venue-management.php         (the room cards)

   ---------------------------------------------------------------------
   THE AVAILABILITY MODEL — the one structural difference from venues
   ---------------------------------------------------------------------
   A venue room is booked as an exclusive TIME RANGE:
       booked: [ {date, start, end} ]        -> the room is taken or it is not

   A hostel room is booked PER BED, so availability is a COUNT per night:
       occupied: { 'YYYY-MM-DD': [occupant, occupant, ...] }
       bed available on a night  <=>  count(occupied[night]) < beds

   `beds_taken` is NEVER stored — it is count(occupants). Storing both lets
   them disagree (beds=3 but 2 people named => the roster and the counter
   contradict each other and nothing says which is right). The database
   keeps the same promise: bed_reservation_nights holds one row per
   occupied night, and the count IS the occupancy.

   A NIGHT is keyed by its start date: the night of Aug 1 is Aug 1 -> Aug 2.
   A stay of Aug 1 -> Aug 4 therefore occupies nights Aug 1, 2, 3 = 3 nights.
   nights = checkOut - checkIn  (EXCLUSIVE). Venues count days INCLUSIVE.
   Same two dates, different arithmetic — do not reuse the venue's rangeDates().

   Gender is DISPLAY ONLY: shown as a count so a guest knows the room's mix.
   Nothing is enforced or gender-locked; the counter stays a plain number.

   FAILS LOUD, for the same reason as venue-rooms.php: this now supplies
   the beds, the rates and who is already sleeping where, and a booking
   page drawn from invented occupancy would sell a bed that is taken.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/amenities.php';

$hrPdo = venusep_db_or_fail();

/* The hostel venue's name, from the venue that actually holds the hostel
   rooms rather than a constant that could drift away from it. */
$HOSTEL_VENUE = (string) $hrPdo->query(
  "SELECT name FROM venues WHERE venue_type = 'hostel' ORDER BY id LIMIT 1"
)->fetchColumn();

/* ---- 1. the rooms ---- */
$hrRoomRows = $hrPdo->query(
  "SELECT r.id, r.room_code, r.name, r.description, r.amenities,
          v.name AS venue_name,
          d.cr_type, d.rate_per_head_per_night,
          (SELECT COUNT(*) FROM hostel_beds b WHERE b.room_id = r.id AND b.is_active = 1) AS bed_count
     FROM rooms r
     JOIN venues v                   ON v.id = r.venue_id
     LEFT JOIN hostel_room_details d  ON d.room_id = r.id
    WHERE r.room_type = 'hostel' AND r.is_active = 1
    ORDER BY r.id"
)->fetchAll();

/* Rates are per head, PER NIGHT: total = beds x rate x nights.
   Derived from the rooms rather than hard-coded, so changing a rate in the
   database moves the booking page with it. Keyed by CR type because that is
   how the booking page asks for it; each room also carries its own `rate`,
   which is the authoritative value if the two types ever stop being uniform. */
$HOSTEL_RATES = [];
foreach ($hrRoomRows as $r) {
  if ($r['cr_type'] !== null && !isset($HOSTEL_RATES[$r['cr_type']])) {
    $HOSTEL_RATES[$r['cr_type']] = (int) $r['rate_per_head_per_night'];
  }
}

$HOSTEL_CR_LABEL = [
  'communal' => 'Communal CR',
  'private'  => 'Private CR',
];

/* ---- 2. closures — same shape and same rule as every venue room ---- */
$hrMaint = [];
$hrMaintRows = $hrPdo->query(
  "SELECT m.room_id, m.from_date, m.until_date, m.reason, m.blocks_booking
     FROM maintenance_windows m
     JOIN rooms r ON r.id = m.room_id
    WHERE r.room_type = 'hostel'
      AND (m.until_date IS NULL OR m.until_date >= CURDATE())
    ORDER BY m.room_id, (m.from_date <= CURDATE()) DESC, m.from_date ASC"
)->fetchAll();
foreach ($hrMaintRows as $m) {
  if (isset($hrMaint[$m['room_id']])) continue;
  $hrMaint[$m['room_id']] = [
    'from'   => $m['from_date'],
    'until'  => $m['until_date'],
    'reason' => $m['reason'],
    'blocks' => (bool) $m['blocks_booking'],
  ];
}

/* ---- 3. who is sleeping where, every room + night in ONE query ----
   The roster comes from the BEDS, not from the booking: a stay is one row
   per occupant per night in bed_reservation_nights, and the occupancy of a
   room on a night is simply how many of those rows point at its beds.
   Released nights (released_at set) are excluded — that is what frees a bed.

   Gender: the database stores male/female/other; the pages have always used
   the short 'M'/'F'. 'other' maps to 'O' rather than being folded into one
   of the two, so a guest is never silently counted as something they are
   not. It is display-only either way — nothing here reserves a bed by gender. */
$hrOccupied = [];
$hrNightRows = $hrPdo->query(
  "SELECT hb.room_id, n.night_date, o.full_name, o.gender
     FROM bed_reservation_nights n
     JOIN hostel_beds hb       ON hb.id = n.bed_id
     JOIN bed_reservations br  ON br.id = n.bed_reservation_id
     JOIN hostel_occupants o   ON o.id  = br.occupant_id
     JOIN bookings bk          ON bk.id = n.booking_id
    WHERE n.released_at IS NULL
      AND bk.reservation_status IN ('pending', 'approved', 'completed')
    ORDER BY hb.room_id, n.night_date, o.id"
)->fetchAll();
foreach ($hrNightRows as $n) {
  $g = $n['gender'] === 'female' ? 'F' : ($n['gender'] === 'male' ? 'M' : 'O');
  $hrOccupied[$n['room_id']][$n['night_date']][] = [
    'name'   => $n['full_name'],
    'gender' => $g,
  ];
}

/* ---- 4. assemble the shape every page has always consumed ---- */
$hostelRooms = [];
foreach ($hrRoomRows as $r) {
  $hostelRooms[] = [
    'id'          => $r['room_code'],
    'name'        => $r['name'],
    'venue'       => $r['venue_name'],
    'cr_type'     => $r['cr_type'],
    'rate'        => (int) $r['rate_per_head_per_night'],
    'beds'        => (int) $r['bed_count'],
    'description' => (string) $r['description'],
    'amenities'   => amenity_labels($r['amenities']),
    'maintenance' => isset($hrMaint[$r['id']]) ? $hrMaint[$r['id']] : null,
    'occupied'    => isset($hrOccupied[$r['id']]) ? $hrOccupied[$r['id']] : [],
  ];
}
unset($hrRoomRows, $hrMaintRows, $hrNightRows, $hrMaint, $hrOccupied, $hrPdo);

/* ---------------------------------------------------------------------
   Derived helpers — every one of these COMPUTES; none of them reads a
   stored count, a stored status, or a stored bed total.
   --------------------------------------------------------------------- */

/** Does a maintenance window cover this night? Same window shape and same rule
    as every venue room — the hostel did not invent a second maintenance
    concept, it reuses the one that already exists. */
function hostelMaintCovers($m, $night) {
  return $m && $night >= $m['from'] && ($m['until'] === null || $night <= $m['until']);
}

/** The people in a room on a given night (never a stored number). */
function hostelOccupants(array $room, $night) {
  return isset($room['occupied'][$night]) ? $room['occupied'][$night] : [];
}

/** beds_taken = count(occupants). This is the load-bearing rule. */
function hostelBedsTaken(array $room, $night) {
  return count(hostelOccupants($room, $night));
}

/** Free beds on a night. Exclusivity is emergent: 0 free just means full. */
function hostelBedsFree(array $room, $night) {
  return max(0, $room['beds'] - hostelBedsTaken($room, $night));
}

/** Gender mix for DISPLAY only — e.g. "2 female, 1 male". Never enforced.
    'O' is counted in its own bucket rather than folded into male, so nobody
    is miscounted; callers that only read F and M simply ignore it. */
function hostelGenderMix(array $room, $night) {
  $mix = ['F' => 0, 'M' => 0, 'O' => 0];
  foreach (hostelOccupants($room, $night) as $o) {
    $g = isset($o['gender']) ? $o['gender'] : 'O';
    if (!isset($mix[$g])) $g = 'O';
    $mix[$g]++;
  }
  return $mix;
}

/** Nights are EXCLUSIVE of the check-out date: Aug 1 -> Aug 4 = Aug 1,2,3. */
function hostelNights($checkIn, $checkOut) {
  $out = [];
  if (!$checkIn || !$checkOut || $checkOut <= $checkIn) return $out;
  $cur = strtotime($checkIn); $end = strtotime($checkOut);
  while ($cur < $end && count($out) < 366) { $out[] = date('Y-m-d', $cur); $cur = strtotime('+1 day', $cur); }
  return $out;
}

/** The peak occupancy across a stay — what actually limits how many beds
    you can reserve for the WHOLE stay (a bed must be free every night). */
function hostelMaxBedsFree(array $room, array $nights) {
  if (!$nights) return $room['beds'];
  $min = $room['beds'];
  foreach ($nights as $n) $min = min($min, hostelBedsFree($room, $n));
  return $min;
}

/* Rooms grouped by CR type — the landing page shows the two types distinctly. */
function hostelRoomsByType(array $rooms, $crType) {
  return array_values(array_filter($rooms, function ($r) use ($crType) { return $r['cr_type'] === $crType; }));
}
