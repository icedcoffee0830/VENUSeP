<?php

namespace App\Http\Controllers\Admin;

use PDOException;

/* Admin Dashboard (was admin/Admin_Dashboard.php). Route middleware: staff. */
class DashboardController
{
    public function __invoke()
    {
        /* The needs-action counts and the preview list come from the SAME builder the
           Booking Requests queue uses (legacy bookings.php). */
        legacy('bookings');
        $adRows = booking_queue_rows();

        /* Today's bookings, money collected this quarter, the venue list —
           tiles fall back to zero rather than to a lie. "In use today" = today
           is one of the booking's days: a venue's days are inclusive, a hostel
           stay's nights end the morning of check-out — not only the first day. */
        $adToday = 0; $adRevenue = 0.0; $adVenues = [];
        try {
            $adPdo = venusep_db_or_fail();
            $adToday = (int) $adPdo->query(
                "SELECT COUNT(*) FROM bookings b
                   LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
                   LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
                  WHERE (CURDATE() BETWEEN vd.start_date AND vd.end_date
                         OR (hd.check_in_date <= CURDATE() AND hd.check_out_date > CURDATE()))
                    AND b.reservation_status IN ('approved', 'completed')"
            )->fetchColumn();
            /* Money COLLECTED this quarter — the Quarterly Reports' own figure
               (report_quarters()), so the tile and the report cannot disagree. */
            $adRevenue = (float) report_quarters(1)[0]['revenue'];
            $adVenues = $adPdo->query(
                "SELECT v.name, v.description, COUNT(r.id) AS rooms
                   FROM venues v LEFT JOIN rooms r ON r.venue_id = v.id AND r.is_active = 1
                  WHERE v.is_active = 1 GROUP BY v.id ORDER BY v.id"
            )->fetchAll();
        } catch (PDOException $e) { /* tiles fall back to zero */ }

        return view('admin.dashboard', compact('adRows', 'adToday', 'adRevenue', 'adVenues'));
    }
}
