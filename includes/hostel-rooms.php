<?php
/* =====================================================================
   USeP HOSTEL — [SIM] rooms + the availability model. THE ONE SOURCE.

   Included by:
     customer/hostel-reservation.php    (json_encode'd into the booking page)
     customer/venusep_venue_booking.php (the landing list)
     admin/venue-management.php         (the room cards)

   WHY AN INCLUDE: the venue's demo rooms are hand-written separately in
   three files and have already drifted into different room names on each.
   The hostel starts with one source so it cannot happen here.

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
   contradict each other and nothing says which is right).

   A NIGHT is keyed by its start date: the night of Aug 1 is Aug 1 -> Aug 2.
   A stay of Aug 1 -> Aug 4 therefore occupies nights Aug 1, 2, 3 = 3 nights.
   nights = checkOut - checkIn  (EXCLUSIVE). Venues count days INCLUSIVE.
   Same two dates, different arithmetic — do not reuse the venue's rangeDates().

   Gender is DISPLAY ONLY: shown as a count so a guest knows the room's mix.
   Nothing is enforced or gender-locked; the counter stays a plain number.
   ===================================================================== */

$HOSTEL_VENUE = 'USeP Hostel';

/* Rates are per head, PER NIGHT (confirmed): total = beds x rate x nights. */
$HOSTEL_RATES = [
  'communal' => 350,   // shared bathroom down the hall
  'private'  => 400,   // bathroom inside the room
];

$HOSTEL_CR_LABEL = [
  'communal' => 'Communal CR',
  'private'  => 'Private CR',
];

/* Hostel amenities are their own catalog — "catering" and "best for:
   conferences" are meaningless for a bed. Layout differs by CR type. */
$hostelRooms = [
  [
    'id' => 'h1', 'name' => 'Hostel Room 1', 'cr_type' => 'communal', 'beds' => 6, 'photos' => 4,
    'description' => 'A six-bed room with bunk beds along both walls and a shared bathroom just down the hall. Each bed has its own locker and reading light.',
    'amenities' => ['6 bunk beds', 'Shared bathroom (down the hall)', 'Air-conditioned', 'Personal locker per bed', 'Reading light per bed', 'Communal lounge access'],
    'maintenance' => null,
    'occupied' => [
      // night => the people sleeping in this room that night.
      // Jul 15-17 is a PARTLY-full room: the case that only exists because
      // booking is per bed. A venue room is taken or free; this one is 2 of 6.
      '2026-07-15' => [['name' => 'Ana Reyes', 'gender' => 'F'], ['name' => 'Bea Cruz', 'gender' => 'F']],
      '2026-07-16' => [['name' => 'Ana Reyes', 'gender' => 'F'], ['name' => 'Bea Cruz', 'gender' => 'F']],
      '2026-07-17' => [['name' => 'Ana Reyes', 'gender' => 'F']],
      '2026-07-20' => [['name' => 'Ana Reyes', 'gender' => 'F'], ['name' => 'Bea Cruz', 'gender' => 'F']],
      '2026-07-21' => [['name' => 'Ana Reyes', 'gender' => 'F'], ['name' => 'Bea Cruz', 'gender' => 'F']],
      '2026-07-22' => [['name' => 'Ana Reyes', 'gender' => 'F']],
    ],
  ],
  [
    'id' => 'h2', 'name' => 'Hostel Room 2', 'cr_type' => 'communal', 'beds' => 6, 'photos' => 4,
    'description' => 'Six bunk beds with the shared bathroom directly opposite the door. The quietest of the communal rooms — it faces the inner courtyard.',
    'amenities' => ['6 bunk beds', 'Shared bathroom (opposite the door)', 'Air-conditioned', 'Personal locker per bed', 'Reading light per bed', 'Courtyard-facing (quiet)'],
    'maintenance' => null,
    'occupied' => [
      // FULL nights. This room demonstrates that exclusivity is EMERGENT: a
      // group booked all six beds, so the room is effectively theirs — but
      // nothing stored says "exclusive". The count simply reached `beds`.
      '2026-07-15' => [
        ['name' => 'Carlo Diaz', 'gender' => 'M'], ['name' => 'Dan Lim', 'gender' => 'M'],
        ['name' => 'Elmo Tan', 'gender' => 'M'], ['name' => 'Fritz Uy', 'gender' => 'M'],
        ['name' => 'Gab Ong', 'gender' => 'M'], ['name' => 'Hero Sy', 'gender' => 'M'],
      ],
      '2026-07-18' => [
        ['name' => 'Carlo Diaz', 'gender' => 'M'], ['name' => 'Dan Lim', 'gender' => 'M'],
        ['name' => 'Elmo Tan', 'gender' => 'M'], ['name' => 'Fritz Uy', 'gender' => 'M'],
        ['name' => 'Gab Ong', 'gender' => 'M'], ['name' => 'Hero Sy', 'gender' => 'M'],
      ],
      '2026-07-25' => [['name' => 'Ivy Mendoza', 'gender' => 'F'], ['name' => 'Jom Rivera', 'gender' => 'M']],
    ],
  ],
  [
    'id' => 'h3', 'name' => 'Hostel Room 3', 'cr_type' => 'communal', 'beds' => 6, 'photos' => 3,
    'description' => 'Six bunk beds with the shared bathroom down the hall. Ground floor, step-free access from the hostel entrance.',
    'amenities' => ['6 bunk beds', 'Shared bathroom (down the hall)', 'Air-conditioned', 'Personal locker per bed', 'Reading light per bed', 'Step-free, ground floor'],
    /* [SIM] MEDIUM maintenance — the same window shape every venue room uses.
       blocks:false => still fully bookable, the guest is just told. */
    'maintenance' => ['from' => '2026-07-14', 'until' => '2026-07-28', 'reason' => 'One of the two ceiling fans is being replaced', 'blocks' => false],
    'occupied' => [
      '2026-07-19' => [['name' => 'Kim Bautista', 'gender' => 'F']],
    ],
  ],
  [
    'id' => 'h4', 'name' => 'Hostel Room 4', 'cr_type' => 'private', 'beds' => 6, 'photos' => 5,
    'description' => 'Six bunk beds with a private bathroom inside the room — no queueing down the hall. Hot shower and a wider walkway between bunks.',
    'amenities' => ['6 bunk beds', 'Private bathroom in-room', 'Hot shower', 'Air-conditioned', 'Personal locker per bed', 'Reading light per bed'],
    'maintenance' => null,
    'occupied' => [
      // a MIXED room — the gender counts are shown so a guest knows the mix,
      // but nothing here reserves a bed by gender.
      '2026-07-15' => [['name' => 'Luis Ramos', 'gender' => 'M'], ['name' => 'Mara Ilagan', 'gender' => 'F'], ['name' => 'Nico Perez', 'gender' => 'M']],
      '2026-07-16' => [['name' => 'Luis Ramos', 'gender' => 'M'], ['name' => 'Mara Ilagan', 'gender' => 'F'], ['name' => 'Nico Perez', 'gender' => 'M']],
      '2026-07-20' => [['name' => 'Luis Ramos', 'gender' => 'M'], ['name' => 'Mara Ilagan', 'gender' => 'F'], ['name' => 'Nico Perez', 'gender' => 'M']],
      '2026-07-21' => [['name' => 'Luis Ramos', 'gender' => 'M'], ['name' => 'Mara Ilagan', 'gender' => 'F'], ['name' => 'Nico Perez', 'gender' => 'M']],
    ],
  ],
  [
    'id' => 'h5', 'name' => 'Hostel Room 5', 'cr_type' => 'private', 'beds' => 6, 'photos' => 4,
    'description' => 'Six bunk beds with a private bathroom and a small study table by the window. Second floor, overlooking the field.',
    'amenities' => ['6 bunk beds', 'Private bathroom in-room', 'Hot shower', 'Air-conditioned', 'Study table', 'Personal locker per bed'],
    /* [SIM] HARD maintenance — cannot be booked on these nights at all. */
    'maintenance' => ['from' => '2026-08-10', 'until' => '2026-08-16', 'reason' => 'Bathroom re-tiling', 'blocks' => true],
    'occupied' => [],
  ],
];

/* ---------------------------------------------------------------------
   Derived helpers — every one of these COMPUTES; none of them read a
   stored count, a stored status, or a stored bed total.
   --------------------------------------------------------------------- */

/** Does a maintenance window cover this night? Same window shape and same rule
    as every venue room ([[room-maintenance-model]]) — the hostel did not invent
    a second maintenance concept, it reuses the one that already exists.
    (venue-management.php has its own one-line copy as vmCovers(). Left alone
    deliberately: this is a date comparison, not the kind of logic that gets
    tuned later, so a shared include would cost more than it saves. The GCash
    CHECKER is the opposite case — 350 lines of matchers — which is why THAT
    one is an include.) */
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

/** Gender mix for DISPLAY only — e.g. "2 female, 1 male". Never enforced. */
function hostelGenderMix(array $room, $night) {
  $f = 0; $m = 0;
  foreach (hostelOccupants($room, $night) as $o) {
    if (($o['gender'] ?? '') === 'F') $f++; else $m++;
  }
  return ['F' => $f, 'M' => $m];
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
