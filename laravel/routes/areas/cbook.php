<?php

use App\Http\Controllers\Customer;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: cbook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/customer/venues', Customer\VenueBookingController::class)->name('customer.venues');   /* public: no guard */
Route::get('/customer/room-reservation', Customer\RoomReservationController::class)->middleware('booking.access')->name('customer.room-reservation');
Route::get('/customer/hostel-reservation', Customer\HostelReservationController::class)->middleware('booking.access')->name('customer.hostel-reservation');
/* JSON endpoint: answers 405 / 401 / the old "page has expired" 400 itself, so it
   runs without Laravel's CSRF middleware and checks the token in the controller. */
Route::match(['get', 'post'], '/customer/booking-submit', Customer\BookingSubmitController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)->name('customer.booking-submit');
Route::get('/customer/calendar', PendingController::class)->name('customer.calendar');
