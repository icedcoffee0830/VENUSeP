<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use PDOException;

/* ==================================================================
   VENUE MANAGEMENT (was admin/venue-management.php).
   ROOMS come from venue-rooms.php + hostel-rooms.php (the database).

   Rooms have NO `status` field. Only a closure is a real decision, and a
   closure has DATES, so that is all that is stored:

       maintenance => ['from'=>ISO, 'until'=>ISO|null, 'reason'=>…, 'blocks'=>bool]
       until = null  -> INDEFINITE (no END date)
       blocks = true -> HARD, cannot be booked | false -> MEDIUM, still bookable

   Everything the card shows about maintenance is DERIVED from that window by
   vmMaint() (in the view), so the card can never disagree with the booking
   engine.
   ================================================================== */
class VenueManagementController extends Controller
{
    public function __invoke()
    {
        /* Staff have to be able to see a disabled room here to turn it back on, so
           this page (and only this page) asks the shared includes for every room,
           not just the ones customers can currently see.
           pricing.php echoes its <script> (DISCOUNT_PERCENT, priceWithDiscount())
           when loaded; the old page printed it before <!DOCTYPE>. Captured here and
           printed in the page head instead of ahead of the response headers. */
        ob_start();
        extract(legacy(
            ['vrIncludeInactive' => true, 'hrIncludeInactive' => true],
            'venues', 'venue-rooms', 'hostel-rooms', 'room-photos', 'venue-photos', 'pricing'
        ));
        $pricingScript = ob_get_clean();

        /* The sections start from venues, not rooms: a new venue has no rooms yet and still belongs here. */
        $vmVenueRows = venusep_db_or_fail()->query(
            "SELECT v.id, v.name, v.venue_type, v.description, v.cover_photo
               FROM venues v
              WHERE v.is_active = 1" . staff_venue_sql('v.id IN (%s)', 'AND') . staff_hall_office_sql('v.id') . "
              ORDER BY v.id"
        )->fetchAll();

        /* The lists are already narrowed to the rooms this person handles (staff: their
           halls; the JSON endpoints refuse the rest). null = the super admin (every room);
           otherwise the names of the offices shown. Edit Details needs the whole office. */
        legacy('auth');
        $vmMyVenue = staff_venue_scope() === null ? null : array_map('strval', array_column($vmVenueRows, 'name'));
        $vmVenueRows = array_map(fn ($v) => $v + ['may_edit' => staff_may_handle_whole_venue($v['id'])], $vmVenueRows);

        $vmStaffByVenue = [];
        $vmStaffRows = venusep_db_or_fail()->query(
            'SELECT venue_id, full_name FROM staff WHERE venue_id IS NOT NULL ORDER BY full_name'
        )->fetchAll();
        foreach ($vmStaffRows as $vmStaff) {
            $vmStaffByVenue[(int) $vmStaff['venue_id']][] = (string) $vmStaff['full_name'];
        }

        return live_view('admin.venue-management', [
            'TODAY' => date('Y-m-d'),
            'rooms' => $venueRooms,   // the event rooms, same data the booking page shows
            'hostelRooms' => $hostelRooms,
            'HOSTEL_CR_LABEL' => $HOSTEL_CR_LABEL,
            'DISCOUNT_PERCENT' => $DISCOUNT_PERCENT,
            'vmVenueRows' => $vmVenueRows,
            'vmStaffByVenue' => $vmStaffByVenue,
            'vmMyVenue' => $vmMyVenue,
            /* who sees the Add buttons: admins, for the offices they handle (room-save checks again) */
            'vmAddHall' => admin_is_admin() && $venuesByType['event'],
            'vmAddHostel' => admin_is_admin() && $venuesByType['hostel'],
            'pricingScript' => $pricingScript,
        ]);
    }
}
