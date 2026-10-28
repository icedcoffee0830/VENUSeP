<?php

use App\Http\Controllers\Admin\CounterCustomerController;
use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: adminb — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/staff', PendingController::class)->name('admin.staff');
Route::match(['get', 'post'], '/admin/staff-save', PendingController::class)->name('admin.staff-save');
Route::get('/admin/admin-register', PendingController::class)->name('admin.admin-register');
Route::middleware('staff')->get('/admin/customer-management', [CustomerController::class, 'index'])->name('admin.customer-management');
/* JSON for the page's JS: own 403/400 JSON (no redirecting middleware, own CSRF check). */
Route::match(['get', 'post'], '/admin/customer-save', [CustomerController::class, 'save'])
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.customer-save');
/* JSON endpoints for the counter booker's JS: they answer "Staff only." as JSON themselves (no
   redirecting middleware); customer-verify checks its own CSRF token so an expired page still
   gets the old 400 JSON instead of Laravel's 419 page. */
Route::match(['get', 'post'], '/admin/customer-search', [CounterCustomerController::class, 'search'])->name('admin.customer-search');
Route::match(['get', 'post'], '/admin/customer-verify', [CounterCustomerController::class, 'verify'])
    ->withoutMiddleware(ValidateCsrfToken::class)->name('admin.customer-verify');
Route::middleware('staff')->get('/admin/faq-management', [FaqController::class, 'index'])->name('admin.faq-management');
Route::middleware('staff')->match(['get', 'post'], '/admin/faq-save', [FaqController::class, 'save'])->name('admin.faq-save');
