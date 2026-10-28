<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/* The venues area's JSON endpoints (room-save, venue-save, the photo APIs,
   room-toggle-active, catalog-delete). The old scripts answered with
   `xxx_reply($status, $data)` = http_response_code + echo json + exit, from
   anywhere — including from inside helper functions and try blocks. reply()
   keeps that shape by throwing the response; a try block that catches
   Throwable must rethrow HttpResponseException first.

   CSRF: these routes skip Laravel's token middleware and check here, in the
   old order (401 "session ended" first, then the old 400 "page has expired"
   JSON the pages' scripts show). The token is Laravel's own (`_token`). */
trait RepliesJson
{
    protected function reply(int $status, array $data): never
    {
        throw new HttpResponseException(
            response()->json($data, $status)->header('Cache-Control', 'no-store')
        );
    }

    /* The old "logged-in admin/staff session" check of the JSON endpoints. */
    protected function requireStaffJson(): void
    {
        if (!session()->has('user_id') || !in_array(session('account_type'), ['admin', 'staff'], true)) {
            $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }
    }

    protected function requireCsrfJson(Request $request): void
    {
        legacy('auth');   /* csrf_valid() */
        $token = $request->input('_token') ?: $request->header('X-CSRF-TOKEN');
        if (!csrf_valid($token)) {
            $this->reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        }
    }

    protected function requirePost(Request $request): void
    {
        if (!$request->isMethod('post')) {
            throw new HttpResponseException(
                response()->json(['ok' => false, 'error' => 'method', 'message' => 'Use POST.'], 405)
                    ->header('Cache-Control', 'no-store')->header('Allow', 'POST')
            );
        }
    }
}
