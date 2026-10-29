<?php

use App\Http\Controllers;
use App\Http\Controllers\Admin;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: abook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/booking-requests', Admin\BookingRequestsController::class)->middleware('staff')->name('admin.booking-requests');
Route::get('/admin/booking-request', Admin\BookingRequestController::class)->middleware('staff')->name('admin.booking-request');
/* The staff JSON endpoints answer 401 JSON themselves (no redirecting middleware). */
Route::match(['get', 'post'], '/admin/booking-action', Admin\BookingActionController::class)->name('admin.booking-action');
Route::match(['get', 'post'], '/admin/booking-create', PendingController::class)->name('admin.booking-create');
Route::match(['get', 'post'], '/admin/booking-emails', Admin\BookingEmailsController::class)->name('admin.booking-emails');
Route::get('/admin/calendar', Admin\CalendarController::class)->middleware('staff')->name('admin.calendar');
Route::get('/admin/receipt', Admin\ReceiptController::class)->middleware('staff')->name('admin.receipt');
Route::get('/admin/transaction-history', Admin\TransactionHistoryController::class)->middleware('staff')->name('admin.transaction-history');
/* Used by customers AND staff: each answers a 404 (never a redirect, never 403) to anyone else. */
Route::get('/booking-details', Controllers\BookingDetailsController::class)->name('root.booking-details');
Route::get('/document-view', Controllers\DocumentViewController::class)->name('root.document-view');
Route::get('/valid-id-view', Controllers\ValidIdViewController::class)->name('root.valid-id-view');
