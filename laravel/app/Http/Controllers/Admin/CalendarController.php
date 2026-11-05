<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* CALENDAR (was admin/calendar.php) — the bookings this person handles, from the same
   rows the queue and the transaction ledger read. For staff, the other halls of their
   offices are plain "Busy" days. */
class CalendarController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');
        $calendarEvents = array_merge(calendar_events(), calendar_busy_events());   // no customer id = all customers

        return view('admin.calendar', [
            'calendarEventsJson' => json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?: '[]',
        ]);
    }
}
