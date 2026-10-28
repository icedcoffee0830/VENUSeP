<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/* THE QUEUE (was admin/booking-requests.php), from the same `bookings` rows the
   customer's own history reads. The row builder lives in legacy bookings.php so
   this page and the dashboard's "needs action" preview cannot disagree. */
class BookingRequestsController extends Controller
{
    public function __invoke()
    {
        legacy('bookings');

        return view('admin.booking-requests', ['brRows' => booking_queue_rows()]);
    }
}
