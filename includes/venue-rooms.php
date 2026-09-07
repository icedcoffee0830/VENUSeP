<?php
/* =====================================================================
   VENUE ROOMS — [SIM] the event-venue rooms (Bahay Alumni + USeP Venues).
   THE ONE SOURCE, mirroring includes/hostel-rooms.php.

   Included by:
     customer/room-reservation.php      (json_encode'd into the booking page)
     customer/venusep_venue_booking.php (the landing list)
     admin/venue-management.php         (the room cards)

   WHY AN INCLUDE: these rooms used to be hand-written SEPARATELY on every
   page and had already drifted into four different room universes — the
   booking page had eight rooms, admin Venue Management managed three
   DIFFERENT ones, and the history pages listed a third set that nobody
   could book. One source ends that. When the database arrives, this array
   becomes the `rooms` seed for Bahay Alumni + USeP Venues.

   AVAILABILITY MODEL — a venue room is booked as an exclusive TIME RANGE:
       booked: [ {date, start, end} ]      -> the slot is taken or it is not
   (The hostel's model is different on purpose — per-night BED counts. Do
   not unify the two; an event has hours, a bed has nights.)

   MAINTENANCE — the same window shape used system-wide (see
   [[room-maintenance-model]]): {from, until, reason, blocks}. `until:null`
   = indefinite; `blocks:true` = hard (cannot book), false = medium
   (bookable, the customer is just told). There is NO `status` field:
   "Available"/"Occupied" were only ever `booked[]` wearing a word.
   ===================================================================== */

$venueRooms = [
  ['id' => 'r1', 'name' => 'Alumni Grand Ballroom', 'venue' => 'Bahay Alumni', 'capacity' => 300, 'maintenance' => null, 'fee' => 5000, 'photos' => 5,
    'description' => 'The flagship function hall of Bahay Alumni, ideal for graduation balls, conferences, and large university ceremonies. Column-free floor with a raised stage and full lighting rig.',
    'amenities' => ['Raised stage & podium', 'Full stage lighting', 'Professional sound system', 'Air-conditioned', '300 stackable chairs', 'LED wall backdrop'],
    'bestFor' => 'Graduation balls · Conferences · Ceremonies', 'catering' => 'In-house & outside catering', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-15', 'start' => '08:00', 'end' => '12:00'], ['date' => '2026-07-18', 'start' => '13:00', 'end' => '18:00']]],

  ['id' => 'r2', 'name' => 'Heritage Function Room', 'venue' => 'Bahay Alumni', 'capacity' => 80, 'maintenance' => null, 'fee' => 2500, 'photos' => 4,
    'description' => 'A warm, mid-sized room for seminars, alumni homecomings, and department gatherings. Flexible seating layout with a built-in projector.',
    'amenities' => ['Ceiling projector & screen', 'Air-conditioned', 'Handheld microphones', '60 chairs + tables', 'Pantry access'],
    'bestFor' => 'Seminars · Homecomings · Department events', 'catering' => 'Outside catering allowed', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-12', 'start' => '09:00', 'end' => '17:00'], ['date' => '2026-07-14', 'start' => '08:00', 'end' => '12:00']]],

  ['id' => 'r3', 'name' => 'Alumni Boardroom', 'venue' => 'Bahay Alumni', 'capacity' => 20, 'maintenance' => null, 'fee' => 1500, 'photos' => 3,
    'description' => 'An executive boardroom for small committee meetings, thesis defenses, and interviews. Conference table seating for up to 20.',
    'amenities' => ['Conference table', 'Wall-mounted TV / HDMI', 'Air-conditioned', 'Whiteboard', 'Coffee station'],
    'bestFor' => 'Meetings · Thesis defenses · Interviews', 'catering' => 'Coffee & light snacks', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-16', 'start' => '10:00', 'end' => '12:00']]],

  /* [SIM] HARD + INDEFINITE. Used to read status:'Maintenance' with an empty
     booked[] — the pill said closed and the form took the reservation anyway.
     The window is what actually closes it now. */
  ['id' => 'r4', 'name' => 'Garden Pavilion', 'venue' => 'Bahay Alumni', 'capacity' => 150, 'fee' => 3500, 'photos' => 4,
    'maintenance' => ['from' => '2026-07-01', 'until' => null, 'reason' => 'Roof repair', 'blocks' => true],
    'description' => 'A semi-outdoor pavilion overlooking the alumni garden, popular for receptions and evening socials. Currently closed for roofing maintenance.',
    'amenities' => ['Open-air covered setup', 'String & spot lighting', 'Power outlets for catering', '150 seat capacity'],
    'bestFor' => 'Receptions · Evening socials', 'catering' => 'Outside catering allowed', 'accessible' => 'Step-free, ground level',
    'booked' => []],

  ['id' => 'r5', 'name' => 'USeP Gymnasium', 'venue' => 'USeP Venues', 'capacity' => 1000, 'maintenance' => null, 'fee' => 8000, 'photos' => 5,
    'description' => 'The main university gymnasium for large assemblies, intramurals, job fairs, and commencement exercises. Bleacher and floor seating combined.',
    'amenities' => ['Bleacher + floor seating', 'Full court PA system', 'Stage riser available', 'Multiple entry gates', 'Backstage rooms'],
    'bestFor' => 'Assemblies · Intramurals · Job fairs', 'catering' => 'Outside catering allowed', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-20', 'start' => '07:00', 'end' => '19:00']]],

  /* [SIM] MEDIUM — blocks:false. Still fully bookable; the customer is just told. */
  ['id' => 'r6', 'name' => 'CIC Audio-Visual Room', 'venue' => 'USeP Venues', 'capacity' => 120, 'fee' => 2000, 'photos' => 4,
    'maintenance' => ['from' => '2026-07-10', 'until' => '2026-07-31', 'reason' => 'One of two aircon units is being replaced', 'blocks' => false],
    'description' => 'A tiered audio-visual room in the College of Information & Computing, suited to colloquia, defenses, and film screenings.',
    'amenities' => ['Tiered theater seating', '4K projector & screen', 'Surround sound', 'Air-conditioned', 'Wireless mics', 'Stable Wi-Fi'],
    'bestFor' => 'Colloquia · Defenses · Film screenings', 'catering' => 'Light snacks only', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-13', 'start' => '13:00', 'end' => '16:00']]],

  ['id' => 'r7', 'name' => 'Admin Conference Hall', 'venue' => 'USeP Venues', 'capacity' => 60, 'maintenance' => null, 'fee' => 1800, 'photos' => 3,
    'description' => 'A formal conference hall at the Administration building for council sessions, MOA signings, and official university meetings.',
    'amenities' => ['U-shape / theater layouts', 'Projector & screen', 'Air-conditioned', 'Podium & microphones', 'Video-conference camera'],
    'bestFor' => 'Council sessions · MOA signings · Meetings', 'catering' => 'In-house catering', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-12', 'start' => '08:00', 'end' => '17:00']]],

  /* [SIM] HARD + PLANNED (from is in the future) — same shape, no extra machinery. */
  ['id' => 'r8', 'name' => 'Obrero Function Hall', 'venue' => 'USeP Venues', 'capacity' => 200, 'fee' => 3000, 'photos' => 5,
    'maintenance' => ['from' => '2026-08-03', 'until' => '2026-08-07', 'reason' => 'Floor refinishing', 'blocks' => true],
    'description' => 'A versatile function hall on the Obrero campus for orientations, trainings, and student org events. Open floor with modular staging.',
    'amenities' => ['Modular stage', 'Projector & screen', 'Air-conditioned', 'Sound system', '200 chairs + tables', 'Load-in access'],
    'bestFor' => 'Orientations · Trainings · Org events', 'catering' => 'Outside catering allowed', 'accessible' => 'Wheelchair accessible',
    'booked' => [['date' => '2026-07-17', 'start' => '09:00', 'end' => '12:00']]],
];

/* Rooms under a given venue — the landing page groups by venue. */
function venueRoomsFor(array $rooms, $venue) {
  return array_values(array_filter($rooms, function ($r) use ($venue) { return $r['venue'] === $venue; }));
}

/* Does a maintenance window cover this date? Same rule as the hostel + the
   customer JS — one shared shape, never a second maintenance concept. */
function venueMaintCovers($m, $date) {
  return $m && $date >= $m['from'] && ($m['until'] === null || $date <= $m['until']);
}
