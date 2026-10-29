<?php

use App\Http\Controllers\Customer;
use Illuminate\Support\Facades\Route;

/* Area: cbook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/customer/venues', Customer\VenueBookingController::class)->name('customer.venues');   /* public: no guard */
Route::get('/customer/room-reservation', Customer\RoomReservationController::class)->middleware('booking.access')->name('customer.room-reservation');
Route::get('/customer/hostel-reservation', Customer\HostelReservationController::class)->middleware('booking.access')->name('customer.hostel-reservation');
/* JSON endpoint: an expired token gets the old 400/401 JSON from bootstrap/app.php */
Route::match(['get', 'post'], '/customer/booking-submit', Customer\BookingSubmitController::class)->name('customer.booking-submit');
Route::get('/customer/calendar', Customer\CalendarController::class)->middleware('customer')->name('customer.calendar');
