<?php

use App\Http\Controllers\Customer;
use Illuminate\Support\Facades\Route;

/* The landing page, the two booking pages and the customer's calendar. Keep every URL and route name:
   links, emails and bookmarks use them. */

Route::get('/customer/venues', Customer\VenuesController::class)->name('customer.venues');   /* public: no guard */
Route::get('/customer/room-reservation', Customer\RoomReservationController::class)->middleware('booking.access')->name('customer.room-reservation');
Route::get('/customer/hostel-reservation', Customer\HostelReservationController::class)->middleware('booking.access')->name('customer.hostel-reservation');
/* JSON endpoint: an expired token gets the old 400/401 JSON from bootstrap/app.php */
Route::match(['get', 'post'], '/customer/booking-submit', Customer\BookingSubmitController::class)->name('customer.booking-submit');
Route::get('/customer/calendar', Customer\CalendarController::class)->middleware('customer')->name('customer.calendar');
