<?php
/* =====================================================================
   VENUES — the list of venues, read from the `venues` table.

   A venue is its OWN thing, independent of rooms: a venue exists whether
   or not it has any rooms yet. That is why this list is a table of its
   own and not derived from the room data — a brand-new venue with zero
   rooms must still appear in the "Location" picker when you add or edit
   a room.

   Included by:
     admin/room-form.php          (Location dropdown — event rooms)
     admin/hostel-room-form.php   (Location dropdown — hostel rooms)
     admin/venue-management.php   (the venue filter)

   ADD A VENUE = add a row (admin Venue Management -> Add Venue), and it
   appears in every Location dropdown and filter automatically.

   Only ACTIVE venues are listed. Deactivating a venue hides it from the
   pickers without touching the rooms or bookings that reference it —
   the foreign keys are ON DELETE RESTRICT, so a venue with rooms cannot
   be deleted anyway (by design: retire it, never erase its history).

   FAILS LOUD. This used to be a hard-coded array, so an unreachable
   database cost nothing; now it IS the venue list, and a page that
   renders an empty or invented "Location" dropdown is worse than a page
   that admits it cannot load. See venusep_db_or_fail() in db.php.
   ===================================================================== */

require_once __DIR__ . '/db.php';

/* [id => name] for the pages that need the id (venue forms, photo uploads),
   and $venues as the plain name list every dropdown has always used. */
$venuesById = [];
$venues = [];

$stmt = venusep_db_or_fail()->query(
  'SELECT id, name FROM venues WHERE is_active = 1 ORDER BY id'
);
foreach ($stmt as $row) {
  $venuesById[(int) $row['id']] = $row['name'];
  $venues[] = $row['name'];
}

/* Render a custom-dropdown option list from $venues, marking $current as the
   summary. Shared by both room forms so they can never list different venues.
   Escapes every value. */
function venueOptions($venues) {
  $out = '';
  foreach ($venues as $v) {
    $out .= '<li class="vm-dd-option">' . htmlspecialchars($v) . '</li>' . "\n                ";
  }
  return rtrim($out);
}

/* The venue id for a name — the reverse of $venuesById. Returns null for an
   unknown name rather than guessing, so a caller can refuse rather than
   silently file a room under the wrong venue. */
function venueIdForName($name) {
  global $venuesById;
  $id = array_search($name, $venuesById, true);
  return $id === false ? null : (int) $id;
}
