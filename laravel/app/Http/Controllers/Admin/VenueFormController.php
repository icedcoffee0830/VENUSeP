<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use PDOException;

/* VENUE FORM (was admin/venue-form.php) — Add / Edit a venue (location).
   Venue photos are DB-backed (venues.cover_photo) — see venue-photos.php
   for why this is a single column, not a room_media-style gallery. */
class VenueFormController extends Controller
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'venue-photos');   /* admin_is_admin() */

        $id = (string) ($request->query('id') ?? '');
        $venueId = $id !== '' && ctype_digit($id) ? (int) $id : 0;
        $venueFormRow = $venueId ? vp_venue_row($venueId) : null;
        if ($venueFormRow === null) $venueId = 0;   // unknown id — behaves like Add

        /* The offices are fixed (DB-DECISIONS 28; venue-save refuses an add too): no id, or an
           unknown one, goes back to the list instead of a form nobody can save. */
        if ($venueId === 0) {
            return redirect()->route('admin.venue-management');
        }
        if (!staff_may_handle_whole_venue($venueId)) {   // another department's venue, or staff with only some of its halls
            return redirect()->route('admin.venue-management');
        }

        /* ASSIGNED STAFF (2026-10-27): the real staff.venue_id rows. Every staff member
           handles exactly one venue, so the picker MOVES people here from the venue
           they handle now. Nobody can be removed into "no venue". Only an admin may
           change it (venue-save re-checks); staff see the list read-only. */
        $vfStaff = [];
        $vfPdo = venusep_db();
        if ($vfPdo !== null) {
            try {
                $vfStaff = $vfPdo->query(
                    "SELECT u.id, u.is_active, s.full_name, s.position_role, s.venue_id, v.name AS venue_name
                       FROM users u JOIN staff s ON s.user_id = u.id LEFT JOIN venues v ON v.id = s.venue_id
                      WHERE u.account_type = 'staff' ORDER BY s.full_name"
                )->fetchAll();
            } catch (PDOException $e) {
                sql_failed($e);
                $vfStaff = [];
            }
        }
        $vfStaff = array_values(array_filter($vfStaff, function ($s) { return staff_sees_venue($s['venue_id']); }));
        $vfHere   = array_values(array_filter($vfStaff, function ($s) use ($venueId) { return $venueId && (int) $s['venue_id'] === $venueId; }));
        $vfOthers = array_values(array_filter($vfStaff, function ($s) use ($venueId) { return !$venueId || (int) $s['venue_id'] !== $venueId; }));

        return live_view('admin.venue-form', [
            'venueId' => $venueId,
            'venueFormRow' => $venueFormRow,
            'venueFormCsrf' => csrf_token(),
            'vfHere' => $vfHere,
            'vfOthers' => $vfOthers,
            'vfCanAssign' => admin_is_admin(),
        ]);
    }
}
