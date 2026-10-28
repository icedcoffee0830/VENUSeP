<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: abook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/booking-requests', Admin\BookingRequestsController::class)->middleware('staff')->name('admin.booking-requests');
Route::get('/admin/booking-request', PendingController::class)->name('admin.booking-request');
Route::match(['get', 'post'], '/admin/booking-action', PendingController::class)->name('admin.booking-action');
Route::match(['get', 'post'], '/admin/booking-create', PendingController::class)->name('admin.booking-create');
Route::match(['get', 'post'], '/admin/booking-emails', PendingController::class)->name('admin.booking-emails');
Route::get('/admin/calendar', PendingController::class)->name('admin.calendar');
Route::get('/admin/receipt', PendingController::class)->name('admin.receipt');
Route::get('/admin/transaction-history', PendingController::class)->name('admin.transaction-history');
Route::get('/booking-details', PendingController::class)->name('root.booking-details');
Route::get('/document-view', PendingController::class)->name('root.document-view');
Route::get('/valid-id-view', PendingController::class)->name('root.valid-id-view');
