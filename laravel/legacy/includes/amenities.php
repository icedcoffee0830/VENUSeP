<?php
/* =====================================================================
   AMENITIES — the VOCABULARY. PHP-coded, deliberately (DB-DECISIONS #9).

   Two different things wear the word "amenities", and keeping them apart
   is the whole design:

     THE VOCABULARY (this file)   which amenities can exist at all, what
                                  each one is CALLED, and what icon it
                                  gets. Developer territory. An admin can
                                  never invent, rename or re-icon one.

     THE ASSIGNMENT (rooms.amenities)  which of them a given room has,
                                  stored as a list of KEYS. That has to
                                  persist — an admin unticking "Hot
                                  shower" must stay unticked — so it
                                  cannot live in a PHP file. A file the
                                  web server can rewrite is the most
                                  dangerous thing in a PHP app, and every
                                  toggle would become a git conflict.

   KEYS, NEVER LABELS, are what gets stored. Reword a label below and all
   13 rooms follow, because the key never moved. Store the labels instead
   and a reword silently orphans every room that used the old text — the
   exact drift this project keeps finding (photo counts, the README, the
   expired maintenance windows).

   ADDING AN AMENITY = add one line to $AMENITY_LABELS and one key to a
   group in $AMENITY_GROUPS. It then appears in the room form's picker
   automatically. Never delete a key that rooms may still reference:
   retire it by removing it from $AMENITY_GROUPS (gone from the picker,
   still renders on rooms that have it).

   Icons are NOT listed here. amenityIcon() in the booking pages already
   derives one by keyword-matching the label text, with a generic tick as
   the fallback, so a new amenity gets a sensible icon for free.
   ===================================================================== */

/* key => the exact text the customer reads. These 47 are the labels the
   rooms already showed before the catalog moved into the database; they
   are reproduced verbatim so nothing visibly changed on that move. */
$AMENITY_LABELS = [
  /* ---- audio-visual ---- */
  'projector_4k'           => '4K projector & screen',
  'projector_ceiling'      => 'Ceiling projector & screen',
  'projector'              => 'Projector & screen',
  'tv_hdmi'                => 'Wall-mounted TV / HDMI',
  'led_wall'               => 'LED wall backdrop',
  'vc_camera'              => 'Video-conference camera',

  /* ---- sound ---- */
  'sound_pro'              => 'Professional sound system',
  'sound_basic'            => 'Sound system',
  'sound_surround'         => 'Surround sound',
  'pa_full_court'          => 'Full court PA system',
  'mic_handheld'           => 'Handheld microphones',
  'mic_wireless'           => 'Wireless mics',
  'podium_mics'            => 'Podium & microphones',

  /* ---- stage & lighting ---- */
  'stage_raised_podium'    => 'Raised stage & podium',
  'stage_modular'          => 'Modular stage',
  'stage_riser'            => 'Stage riser available',
  'stage_lighting'         => 'Full stage lighting',
  'lighting_string_spot'   => 'String & spot lighting',

  /* ---- seating & furniture ---- */
  'chairs_300_stackable'   => '300 stackable chairs',
  'chairs_tables_200'      => '200 chairs + tables',
  'chairs_tables_60'       => '60 chairs + tables',
  'seats_150'              => '150 seat capacity',
  'seating_bleacher_floor' => 'Bleacher + floor seating',
  'seating_tiered'         => 'Tiered theater seating',
  'layout_ushape_theater'  => 'U-shape / theater layouts',
  'conference_table'       => 'Conference table',
  'study_table'            => 'Study table',
  'whiteboard'             => 'Whiteboard',

  /* ---- comfort & utilities ---- */
  'aircon'                 => 'Air-conditioned',
  'wifi'                   => 'Stable Wi-Fi',
  'power_catering'         => 'Power outlets for catering',
  'coffee_station'         => 'Coffee station',
  'pantry'                 => 'Pantry access',
  'open_air_covered'       => 'Open-air covered setup',

  /* ---- access & logistics ---- */
  'gates_multiple'         => 'Multiple entry gates',
  'load_in'                => 'Load-in access',
  'backstage'              => 'Backstage rooms',
  'step_free_ground'       => 'Step-free, ground floor',
  'courtyard_quiet'        => 'Courtyard-facing (quiet)',

  /* ---- hostel ----
     A bed is not a hall: "catering" and "best for: conferences" are
     meaningless here, so the hostel has its own amenities rather than
     borrowing the event ones. */
  'bunk_beds_6'            => '6 bunk beds',
  'bath_shared_hall'       => 'Shared bathroom (down the hall)',
  'bath_shared_opposite'   => 'Shared bathroom (opposite the door)',
  'bath_private'           => 'Private bathroom in-room',
  'hot_shower'             => 'Hot shower',
  'locker_per_bed'         => 'Personal locker per bed',
  'reading_light_per_bed'  => 'Reading light per bed',
  'lounge_access'          => 'Communal lounge access',
];

/* How the room form groups the picker. 47 chips in one undifferentiated
   pile is unusable; grouped, an admin scans the heading they need. A key
   left out of every group still renders on rooms that have it — that is
   how an amenity is retired without breaking existing rooms.
   'Hostel' is listed last because the event room form hides it. */
$AMENITY_GROUPS = [
  'Audio-visual'         => ['projector_4k', 'projector_ceiling', 'projector', 'tv_hdmi', 'led_wall', 'vc_camera'],
  'Sound'                => ['sound_pro', 'sound_basic', 'sound_surround', 'pa_full_court', 'mic_handheld', 'mic_wireless', 'podium_mics'],
  'Stage & lighting'     => ['stage_raised_podium', 'stage_modular', 'stage_riser', 'stage_lighting', 'lighting_string_spot'],
  'Seating & furniture'  => ['chairs_300_stackable', 'chairs_tables_200', 'chairs_tables_60', 'seats_150', 'seating_bleacher_floor', 'seating_tiered', 'layout_ushape_theater', 'conference_table', 'study_table', 'whiteboard'],
  'Comfort & utilities'  => ['aircon', 'wifi', 'power_catering', 'coffee_station', 'pantry', 'open_air_covered'],
  'Access & logistics'   => ['gates_multiple', 'load_in', 'backstage', 'step_free_ground', 'courtyard_quiet'],
  'Hostel'               => ['bunk_beds_6', 'bath_shared_hall', 'bath_shared_opposite', 'bath_private', 'hot_shower', 'locker_per_bed', 'reading_light_per_bed', 'lounge_access'],
];

/* Which groups each room form shows. The event form has no use for
   "6 bunk beds"; the hostel form has no use for "Load-in access". */
$AMENITY_GROUPS_EVENT  = ['Audio-visual', 'Sound', 'Stage & lighting', 'Seating & furniture', 'Comfort & utilities', 'Access & logistics'];
$AMENITY_GROUPS_HOSTEL = ['Hostel', 'Comfort & utilities', 'Access & logistics', 'Seating & furniture'];

/* Stored keys -> the labels a customer reads, IN THE ORDER THEY ARE
   STORED. Each room's list was hand-ordered to read well — r1 opens on
   "Raised stage & podium", not on whichever amenity happens to sort first
   — and re-sorting it into vocabulary order here would quietly reshuffle
   every room on the day the catalog moved into the database. Order is
   part of what the customer reads, so the stored order is authoritative.

   An UNKNOWN key is skipped silently rather than printed raw: a retired
   or mistyped key should read as "this room doesn't have that", never as
   a code fragment on a public page. */
function amenity_labels($keys) {
  global $AMENITY_LABELS;
  if (is_string($keys)) {
    $keys = json_decode($keys, true);
  }
  if (!is_array($keys)) {
    return [];
  }
  $out = [];
  foreach ($keys as $key) {
    if (is_string($key) && isset($AMENITY_LABELS[$key])) {
      $out[] = $AMENITY_LABELS[$key];
    }
  }
  return $out;
}

/* Keep only keys the vocabulary actually knows — used before writing, so
   a tampered form post cannot put junk in the column. */
function amenity_keys_clean($keys) {
  global $AMENITY_LABELS;
  if (!is_array($keys)) {
    return [];
  }
  $out = [];
  foreach ($keys as $k) {
    if (is_string($k) && isset($AMENITY_LABELS[$k]) && !in_array($k, $out, true)) {
      $out[] = $k;
    }
  }
  return $out;
}

/* =====================================================================
   "AT A GLANCE" FACTS — also PHP-coded (DB-DECISIONS #9), keyed by room.

   bestFor / catering / accessible are one-line prose about a room, not a
   pickable vocabulary, so they are not amenities and do not belong in the
   JSON column. They stay here for the same reason the labels above do.

   ⚠️ KNOWN LIMIT: a room CREATED through the admin form gets no entry
   here, so it shows no at-a-glance line until a developer adds one. That
   is the honest cost of keeping these PHP-coded. Revisit if admins start
   adding rooms often — the fix would be three columns on
   event_room_details, not a table.
   ===================================================================== */
$ROOM_GLANCE = [
  'r1' => ['bestFor' => 'Graduation balls · Conferences · Ceremonies',   'catering' => 'In-house & outside catering', 'accessible' => 'Wheelchair accessible'],
  'r2' => ['bestFor' => 'Seminars · Homecomings · Department events',    'catering' => 'Outside catering allowed',    'accessible' => 'Wheelchair accessible'],
  'r3' => ['bestFor' => 'Meetings · Thesis defenses · Interviews',       'catering' => 'Coffee & light snacks',       'accessible' => 'Wheelchair accessible'],
  'r4' => ['bestFor' => 'Receptions · Evening socials',                  'catering' => 'Outside catering allowed',    'accessible' => 'Step-free, ground level'],
  'r5' => ['bestFor' => 'Assemblies · Intramurals · Job fairs',          'catering' => 'Outside catering allowed',    'accessible' => 'Wheelchair accessible'],
  'r6' => ['bestFor' => 'Colloquia · Defenses · Film screenings',        'catering' => 'Light snacks only',           'accessible' => 'Wheelchair accessible'],
  'r7' => ['bestFor' => 'Council sessions · MOA signings · Meetings',    'catering' => 'In-house catering',           'accessible' => 'Wheelchair accessible'],
  'r8' => ['bestFor' => 'Orientations · Trainings · Org events',         'catering' => 'Outside catering allowed',    'accessible' => 'Wheelchair accessible'],
];

function room_glance($roomCode) {
  global $ROOM_GLANCE;
  return isset($ROOM_GLANCE[$roomCode])
    ? $ROOM_GLANCE[$roomCode]
    : ['bestFor' => '', 'catering' => '', 'accessible' => ''];
}
