<?php

use App\Http\Controllers\Customer;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: cacct — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/customer/booking-history', Customer\BookingHistoryController::class)->middleware('customer')->name('customer.booking-history');
Route::get('/customer/pay', Customer\PayController::class)->middleware('customer')->name('customer.pay');
/* JSON endpoint: own 401 + "page has expired" JSON, as before (see the controller) */
Route::match(['get', 'post'], '/customer/payment-submit', Customer\PaymentSubmitController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('customer.payment-submit');
Route::match(['get', 'post'], '/customer/receipt', Customer\ReceiptController::class)->middleware('customer')->withoutMiddleware(ValidateCsrfToken::class)->name('customer.receipt');
Route::get('/receipt-pdf', Customer\ReceiptPdfController::class)->name('root.receipt-pdf');
Route::get('/customer/refund-request', Customer\RefundRequestController::class)->middleware('customer')->name('customer.refund-request');
Route::match(['get', 'post'], '/customer/refund-submit', Customer\RefundSubmitController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('customer.refund-submit');
Route::get('/customer/transaction-history', Customer\TransactionHistoryController::class)->middleware('customer')->name('customer.transaction-history');
Route::get('/customer/faq', Customer\FaqController::class)->name('customer.faq');
