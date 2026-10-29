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

        /* Venue cards must start from venues, not rooms: a newly created venue has no
           rooms yet and still belongs on this page. LEFT JOINs keep that venue in the
           result while deriving its active room, hostel-bed and assigned-staff counts. */
        $vmVenueRows = venusep_db_or_fail()->query(
            "SELECT v.id, v.name, v.venue_type, v.description, v.cover_photo,
                    COUNT(DISTINCT r.id) AS room_count,
                    COUNT(DISTINCT hb.id) AS bed_count,
                    COUNT(DISTINCT s.user_id) AS staff_count
               FROM venues v
               LEFT JOIN rooms r ON r.venue_id = v.id AND r.is_active = 1
               LEFT JOIN hostel_beds hb ON hb.room_id = r.id AND hb.is_active = 1
               LEFT JOIN staff s ON s.venue_id = v.id
              WHERE v.is_active = 1
              GROUP BY v.id, v.name, v.venue_type, v.description, v.cover_photo
              ORDER BY v.id"
        )->fetchAll();

        $vmStaffByVenue = [];
        $vmStaffRows = venusep_db_or_fail()->query(
            'SELECT venue_id, full_name FROM staff WHERE venue_id IS NOT NULL ORDER BY full_name'
        )->fetchAll();
        foreach ($vmStaffRows as $vmStaff) {
            $vmStaffByVenue[(int) $vmStaff['venue_id']][] = (string) $vmStaff['full_name'];
        }

        /* The rate's change history — system_settings_history, written by
           discount-save. A settings change is an EVENT, not an overwrite. */
        $dcHistory = [];
        try {
            $dcHistory = venusep_db_or_fail()->query(
                "SELECT h.old_value, h.new_value, h.change_note, h.changed_at,
                        COALESCE(u.username, u.email, 'Administrator') AS changed_by
                   FROM system_settings_history h
                   LEFT JOIN users u ON u.id = h.changed_by_user_id
                  WHERE h.setting_key = 'discount_percent'
                  ORDER BY h.changed_at DESC, h.id DESC
                  LIMIT 6"
            )->fetchAll();
        } catch (PDOException $e) {
            $dcHistory = [];   // the rate still shows; only its history is missing
        }

        return view('admin.venue-management', [
            'TODAY' => date('Y-m-d'),
            'venues' => $venues,
            'rooms' => $venueRooms,   // the event rooms, same data the booking page shows
            'hostelRooms' => $hostelRooms,
            'HOSTEL_VENUE' => $HOSTEL_VENUE,
            'HOSTEL_CR_LABEL' => $HOSTEL_CR_LABEL,
            'DISCOUNT_PERCENT' => $DISCOUNT_PERCENT,
            'vmVenueRows' => $vmVenueRows,
            'vmStaffByVenue' => $vmStaffByVenue,
            'dcHistory' => $dcHistory,
            'dcCsrf' => csrf_token(),
            'pricingScript' => $pricingScript,
        ]);
    }
}
