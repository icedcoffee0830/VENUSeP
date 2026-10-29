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

    /* The old "logged-in admin/staff session" check of the JSON endpoints. */
    protected function requireStaffJson(): void
    {
        if (!session()->has('user_id') || !in_array(session('account_type'), ['admin', 'staff'], true)) {
            $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }
        /* These routes have no page guard (staff_session_heal()), so the account is
           re-read here: a suspended one is refused, a demoted admin acts as staff. */
        legacy('db');
        $pdo = venusep_db();
        if ($pdo !== null) {
            $st = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :id');
            $st->execute([':id' => (int) session('user_id')]);
            $row = $st->fetch();
            if (!$row || !(bool) $row['is_active'] || !in_array($row['account_type'], ['admin', 'staff'], true)) {
                $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
            }
            session(['account_type' => $row['account_type']]);
        }
    }

    /* STAFF HANDLE ONLY THEIR OWN VENUE (DB-DECISIONS #24): the catalog writes
       (rooms, venues, their photos) are refused for another venue's things, the
       same way booking-action refuses another venue's bookings. Admins pass. */
    protected function requireVenueScope($venueId, string $what = 'room'): void
    {
        legacy('auth');
        if (!staff_may_handle_venue($venueId)) {
            $this->reply(403, ['ok' => false, 'error' => 'forbidden',
                'message' => 'This ' . $what . ' belongs to another venue. Only that venue\'s staff or an administrator can change it.']);
        }
    }

    protected function requirePost(Request $request): void
    {
        if (!$request->isMethod('post')) {
            $this->reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
    }
}
