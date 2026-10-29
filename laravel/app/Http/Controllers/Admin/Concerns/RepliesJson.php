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
    }

    protected function requirePost(Request $request): void
    {
        if (!$request->isMethod('post')) {
            $this->reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
    }
}
