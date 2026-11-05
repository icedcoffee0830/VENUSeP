<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/* The staff JSON endpoints (booking-action, booking-create, booking-emails, and
   the venues area: room-save, venue-save, the photo APIs, room-toggle-active,
   catalog-delete). The old scripts answered with `xxx_reply($status, $data)` =
   http_response_code + echo json + exit, from anywhere — including from inside
   helper functions and try blocks. reply() keeps that shape by throwing the
   response; a try block that catches Throwable must rethrow
   HttpResponseException first.

   CSRF: Laravel's token check runs on every POST (the pages' scripts send
   `_token`); bootstrap/app.php turns its 419 into the old 400 "page has
   expired" / 401 "session ended" JSON. */
trait RepliesJson
{
    protected function reply(int $status, array $data, array $headers = []): never
    {
        throw new HttpResponseException(
            response()->json($data, $status, ['Cache-Control' => 'no-store'] + $headers)
        );
    }

    /* The old "logged-in admin/staff session" check of the JSON endpoints. The
       session is already fresh: staff_session_refresh() (EnforceTwoFactor, every
       request) re-read the account before this ran — a suspended one has no
       session left (401 here), a demoted admin is already 'staff'. */
    protected function requireStaffJson(): void
    {
        if (!session()->has('user_id') || !in_array(session('account_type'), ['admin', 'staff'], true)) {
            $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }
    }

    /* STAFF HANDLE ONLY THEIR OWN HALLS (DB-DECISIONS 28): the catalog writes (rooms,
       venues, their photos) are refused for anything else, the same way booking-action
       refuses another hall's bookings. Admins: their offices.

       The office a room is filed under. */
    protected function requireVenueScope($venueId): void
    {
        legacy('auth');
        if (!staff_may_handle_venue($venueId)) {
            $this->refuseScope('This room belongs to another venue. Only that venue\'s staff or an administrator can change it.');
        }
    }

    /* A room itself. */
    protected function requireRoomScope($roomId, $venueId): void
    {
        legacy('auth');
        if (!staff_may_handle_room($roomId, $venueId)) {
            $this->refuseScope('This room is not one you handle. Only its staff or an administrator can change it.');
        }
    }

    /* A venue's own details and photo (staff_may_handle_whole_venue). */
    protected function requireWholeVenueScope($venueId): void
    {
        legacy('auth');
        if (!staff_may_handle_whole_venue($venueId)) {
            $this->refuseScope('This venue is not one you handle as a whole. Only its staff or an administrator can change it.');
        }
    }

    private function refuseScope(string $message): never
    {
        $this->reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => $message]);
    }

    protected function requirePost(Request $request): void
    {
        if (!$request->isMethod('post')) {
            $this->reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
    }
}
