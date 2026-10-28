<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/* booking_page_require_access(): the booking pages are for a customer booking
   for themselves, or staff at the counter (?counter=1 with a STAFF session). */
class BookingAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        legacy('auth');
        booking_page_require_access();

        return NoStore::apply($next($request));
    }
}
