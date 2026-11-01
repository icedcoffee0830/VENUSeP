<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* The old admin JSON endpoints (refund-switch.php, discount-save.php, ...)
   answered every failure with their own JSON, in a fixed order: method, session,
   THEN the CSRF token ("This page has expired..."). Their routes skip Laravel's
   CSRF middleware (which would answer 419 HTML first) and check the token here,
   so the pages' scripts keep getting the replies they were written for. */
trait JsonEndpoint
{
    /* Same body as the old json_encode($data) (no flags), plus Cache-Control: no-store. */
    protected function reply(int $status, array $data, array $headers = []): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'] + $headers);
    }

    /* Laravel's token, sent as the `_token` field or the X-CSRF-TOKEN header
       (`csrf` = the old field name, still accepted from not-yet-updated callers). */
    protected function csrfOk(Request $request): bool
    {
        legacy('auth');
        $token = $request->input('_token', $request->input('csrf', $request->header('X-CSRF-TOKEN')));

        return csrf_valid(is_string($token) ? $token : null);
    }
}
