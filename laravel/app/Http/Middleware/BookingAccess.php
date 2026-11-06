<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* booking_page_require_access(): the booking pages are for a customer booking
   for themselves, or staff at the counter (?counter=1 with a STAFF session).
   At the counter, a room this person does not handle is refused before the page
   is drawn (DB-DECISIONS 28); booking-create refuses it again at save. */
class BookingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        legacy('auth', 'offices');
        booking_page_require_access();

        $office = counter_mode() ? office_of_room($request->query('room')) : null;
        if ($office && !staff_may_handle_room_code($request->query('room'))) {
            return redirect(route('customer.venues') . '?counter=1')
                ->with('counter_refused', 'That room is not one you handle. At the counter you can book your own rooms only.');
        }

        return NoStore::apply($next($request));
    }
}
