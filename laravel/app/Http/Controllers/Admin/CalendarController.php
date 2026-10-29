<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* CALENDAR (was admin/calendar.php) — EVERY booking, for the staff-wide
   calendar, from the same rows the queue and the transaction ledger read. */
class CalendarController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');
        $calendarEvents = calendar_events();          // no customer id = all customers

        return view('admin.calendar', [
            'calendarEventsJson' => json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }
}
