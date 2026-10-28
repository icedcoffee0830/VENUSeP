<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* admin_require_login(): `staff` = admin or staff, `staff:admin` = admins only.
   Not signed in on the staff side -> sign-in page; staff on an admin-only page
   -> dashboard; suspended / demoted / 2FA-reset accounts are handled by
   staff_session_heal(). */
class EnsureStaff
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        legacy('auth');
        admin_require_login($roles ?: ['admin', 'staff']);

        return NoStore::apply($next($request));
    }
}
