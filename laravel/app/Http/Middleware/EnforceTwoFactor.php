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

        return $next($request);
    }
}
