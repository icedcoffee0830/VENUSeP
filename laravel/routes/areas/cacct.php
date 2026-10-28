<?php

use App\Http\Controllers\Customer;
use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: cacct — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/customer/booking-history', Customer\BookingHistoryController::class)->middleware('customer')->name('customer.booking-history');
Route::get('/customer/pay', PendingController::class)->name('customer.pay');
Route::match(['get', 'post'], '/customer/payment-submit', PendingController::class)->name('customer.payment-submit');
Route::get('/customer/receipt', PendingController::class)->name('customer.receipt');
Route::get('/receipt-pdf', PendingController::class)->name('root.receipt-pdf');
Route::get('/customer/refund-request', PendingController::class)->name('customer.refund-request');
Route::match(['get', 'post'], '/customer/refund-submit', PendingController::class)->name('customer.refund-submit');
Route::get('/customer/transaction-history', PendingController::class)->name('customer.transaction-history');
Route::get('/customer/faq', PendingController::class)->name('customer.faq');
