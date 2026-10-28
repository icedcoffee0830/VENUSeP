<?php

use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: cbook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/customer/venues', PendingController::class)->name('customer.venues');
Route::get('/customer/room-reservation', PendingController::class)->name('customer.room-reservation');
Route::get('/customer/hostel-reservation', PendingController::class)->name('customer.hostel-reservation');
Route::match(['get', 'post'], '/customer/booking-submit', PendingController::class)->name('customer.booking-submit');
Route::get('/customer/calendar', PendingController::class)->name('customer.calendar');
