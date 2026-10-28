<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;

/* CUSTOMER CALENDAR (was customer/calendar.php) — same UI as the admin calendar,
   showing THIS customer's own bookings. Read-only. Route middleware: customer.

   The rows come from legacy bookings.php — the same ones this customer's booking
   history and transaction ledger read. */
class CalendarController extends Controller
{
    public function __invoke()
    {
        extract(legacy('customer-bookings'));

        $calendarEvents = calendar_events($customerContact['id']);

        return view('customer.calendar', [
            'customerContact'    => $customerContact,
            'calendarEventsJson' => json_encode($calendarEvents, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]',
        ]);
    }
}
