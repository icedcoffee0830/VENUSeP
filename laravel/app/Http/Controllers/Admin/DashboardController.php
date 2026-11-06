<?php

namespace App\Http\Controllers\Admin;

use App\Queries\ReportQueries;
use App\Queries\VenueQueries;
use PDOException;

/* Admin Dashboard (was admin/Admin_Dashboard.php). Route middleware: staff. */
class DashboardController
{
    public function __invoke()
    {
        /* The needs-action counts and the preview list come from the SAME builder the
           Booking Requests queue uses (legacy bookings.php). */
        legacy('bookings');                        // + auth: staff_venue_scope(), staff_venue_sql()
        $adRows = booking_queue_rows();

        /* Today's bookings, money collected this quarter, the venue list —
           tiles fall back to zero rather than to a lie. "In use today" = today
           is one of the booking's days: a venue's days are inclusive, a hostel
           stay's nights end the morning of check-out — not only the first day. */
        $adToday = 0; $adRevenue = 0.0; $adVenues = [];
        $adWho = session('account_type') === 'admin' ? 'Administrator' : 'Staff';
        $adManaging = '';
        try {
            venusep_db_or_fail();
            $adToday = VenueQueries::countBookingsToday();
            /* Money COLLECTED this quarter in this person's offices (staff: their halls): the
               Quarterly Reports' own figure (ReportQueries::quarters()), so an admin's tile and
               report agree. */
            $adRevenue = (float) ReportQueries::quarters(array_column(ReportQueries::offices(staff_venue_scope()), 'id'), 1, null, staff_hall_ids())[0]['revenue'];
            $adVenues = VenueQueries::officesWithRooms();

            /* "Welcome, <who> | Managing: <what>" — the header's name rule (staff
               full name, else username), and for staff only their own venue. */
            $adWho = (string) (VenueQueries::displayName((int) session('user_id')) ?: $adWho);
            /* $adVenues is already narrowed to the offices this person handles */
            $names = array_column($adVenues, 'name');
            $last = array_pop($names);
            $adManaging = $names ? implode(', ', $names) . ' and ' . $last : (string) $last;
        } catch (PDOException $e) { sql_failed($e); /* tiles fall back to zero */ }
        if ($adManaging === '') {
            $adManaging = 'No venue assigned';
        }

        return live_view('admin.dashboard', compact('adRows', 'adToday', 'adRevenue', 'adVenues', 'adWho', 'adManaging'));
    }
}
