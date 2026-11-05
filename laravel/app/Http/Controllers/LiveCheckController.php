<?php

namespace App\Http\Controllers;

use App\Queries\LiveQueries;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/* THE 30-SECOND CHECK — public/assets/js/live.js calls this from every signed-in page.
   GET ?page=queue  the staff booking lists (Booking Management, Dashboard)
       ?page=mine   the customer's own bookings (Booking History, the pay page)
       (no page)    the bell alone
   Answers { ok, bell, page }: version strings the script compares with the last ones it saw.
   One request per tick whatever the page, so the built-in PHP server is not kept busy.
   Guards itself (JSON 401), like the bell's endpoint; the scope comes from the session. */
class LiveCheckController
{
    public function __invoke(Request $request): JsonResponse
    {
        legacy('auth');

        $staff = in_array(session('account_type'), ['admin', 'staff'], true);
        $userId = (int) session('user_id', 0);
        if ($userId <= 0 || (!$staff && !customer_logged_in())) {
            return response()->json(['ok' => false, 'message' => 'Not signed in.'], 401);
        }

        $page = null;
        if ($staff && $request->query('page') === 'queue') {
            $page = LiveQueries::staffBookings();
        } elseif (!$staff && $request->query('page') === 'mine') {
            $page = LiveQueries::customerBookings((int) session('customer_id'));
        }

        return response()->json(['ok' => true, 'bell' => LiveQueries::bell($userId), 'page' => $page], 200, ['Cache-Control' => 'no-store']);
    }
}
