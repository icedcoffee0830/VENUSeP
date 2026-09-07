<?php
/* =====================================================================
   VENUES — [SIM] the list of venues, the ONE source.

   A venue is its OWN thing, independent of rooms: a venue exists whether
   or not it has any rooms yet. That is why this list is here and not
   derived from the room data — a brand-new venue with zero rooms must
   still appear in the "Location" picker when you add or edit a room.

   Included by:
     admin/room-form.php          (Location dropdown — event rooms)
     admin/hostel-room-form.php   (Location dropdown — hostel rooms)
     admin/venue-management.php   (the venue filter)

   ADD A VENUE = ADD ONE LINE HERE, and it appears in every Location
   dropdown and filter automatically. Nothing else to touch. When the
   database arrives this becomes the `venues` table, and these dropdowns
   become `SELECT name FROM venues` — the exact same behaviour.

   Keep these names byte-identical to the `venue` field on the rooms in
   includes/venue-rooms.php + $HOSTEL_VENUE in includes/hostel-rooms.php
   (in the DB that link is a foreign key).
   ===================================================================== */

$venues = [
  'Bahay Alumni',
  'USeP Venues',
  'USeP Hostel',
];

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
