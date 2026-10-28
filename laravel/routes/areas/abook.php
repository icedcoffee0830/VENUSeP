<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: abook — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/booking-requests', Admin\BookingRequestsController::class)->middleware('staff')->name('admin.booking-requests');
Route::get('/admin/booking-request', Admin\BookingRequestController::class)->middleware('staff')->name('admin.booking-request');
/* JSON endpoint: answers 401 / 400 JSON itself (the old order), so it checks the CSRF token itself too. */
Route::match(['get', 'post'], '/admin/booking-action', Admin\BookingActionController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.booking-action');
Route::match(['get', 'post'], '/admin/booking-create', PendingController::class)->name('admin.booking-create');
Route::match(['get', 'post'], '/admin/booking-emails', Admin\BookingEmailsController::class)
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.booking-emails');   /* JSON; checks its own token, like booking-action */
Route::get('/admin/calendar', PendingController::class)->name('admin.calendar');
Route::get('/admin/receipt', Admin\ReceiptController::class)->middleware('staff')->name('admin.receipt');
Route::get('/admin/transaction-history', Admin\TransactionHistoryController::class)->middleware('staff')->name('admin.transaction-history');
Route::get('/booking-details', PendingController::class)->name('root.booking-details');
Route::get('/document-view', PendingController::class)->name('root.document-view');
Route::get('/valid-id-view', PendingController::class)->name('root.valid-id-view');
