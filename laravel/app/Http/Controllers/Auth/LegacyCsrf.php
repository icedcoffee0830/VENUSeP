<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;

/* The JSON endpoints register-submit and profile-save are excluded from
   Laravel's CSRF middleware (routes/areas/auth.php) because their pages' JS
   reads a JSON reply — Laravel's 419 page would show as "The server sent an
   unreadable reply". They check the same session token here instead, and
   reply exactly as the old csrf_valid() branch did. The token may come as the
   old `csrf` field (what the pages send), `_token`, or the X-CSRF-TOKEN header. */
trait LegacyCsrf
{
    protected function legacyCsrfValid(Request $request): bool
    {
        legacy('auth');
        $token = $request->request->get('csrf') ?? $request->request->get('_token') ?? $request->header('X-CSRF-TOKEN');

        return csrf_valid($token);
    }
}
