<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* DB-DECISIONS #20: an account that must use two-step verification only counts
   as signed in if the session passed the code. Checked on EVERY request (pages,
   JSON endpoints, counter pages) — the old venusep_session_start() did this. */
class EnforceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        if ($session->has('user_id') && $session->has('account_type') && !$session->get('tfa_passed')) {
            legacy('two-factor');
            if (tfa_required_for((string) $session->get('account_type'))) {
                $session->invalidate();
                $session->put('tfa_required_notice', true);
            }
        }
        /* Same question, other cause: a staff account suspended or demoted since
           sign-in no longer counts either — on JSON endpoints too (staff_session_refresh()). */
        if ($session->has('user_id')) {
            legacy('auth');
            staff_session_refresh();
        }
        /* A staff account the admin created sets its own password before anything
           else (Aron 2026-10-31): until then only the profile's password form,
           saving it, and signing out. */
        if ($session->get('must_change_password') && !$request->routeIs('admin.profile', 'root.profile-save', 'admin.logout')) {
            return $request->isMethod('get') && !$request->expectsJson()
                ? redirect()->route('admin.profile', ['password' => 'required'])
                : response()->json(['ok' => false, 'message' => 'Set your own password first, in your profile.'], 403);
        }

        return $next($request);
    }
}
