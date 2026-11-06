<?php

use App\Http\Controllers\Admin\CounterCustomerController;
use App\Http\Controllers\Admin\CustomerManagementController;
use App\Http\Controllers\Admin\FaqManagementController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\Admin\StaffCoverController;
use Illuminate\Support\Facades\Route;

/* Staff, customers, the counter's customer search and the FAQ editor. Keep every URL and route name:
   links, emails and bookmarks use them. */

Route::middleware('staff:admin')->get('/admin/staff', [StaffController::class, 'index'])->name('admin.staff');
/* JSON for the page's JS: own admin-only 403/400 JSON (no redirecting middleware, own CSRF check). */
Route::match(['get', 'post'], '/admin/staff-save', [StaffController::class, 'save'])->name('admin.staff-save');
/* covers and moves between groups: JSON, admin-only, answers for itself */
Route::post('/admin/staff-cover-save', StaffCoverController::class)->name('admin.staff-cover-save');
Route::middleware('staff:admin')->get('/admin/admin-register', [StaffController::class, 'register'])->name('admin.admin-register');
Route::middleware('staff:admin')->get('/admin/customer-management', [CustomerManagementController::class, 'index'])->name('admin.customer-management');
/* JSON for the page's JS: own 403/400 JSON (no redirecting middleware, own CSRF check). */
Route::match(['get', 'post'], '/admin/customer-save', [CustomerManagementController::class, 'save'])->name('admin.customer-save');
/* JSON endpoints for the counter booker's JS: they answer "Staff only." as JSON themselves (no
   redirecting middleware); customer-verify checks its own CSRF token so an expired page still
   gets the old 400 JSON instead of Laravel's 419 page. */
Route::match(['get', 'post'], '/admin/customer-search', [CounterCustomerController::class, 'search'])->name('admin.customer-search');
Route::match(['get', 'post'], '/admin/customer-verify', [CounterCustomerController::class, 'verify'])->name('admin.customer-verify');
Route::match(['get', 'post'], '/admin/customer-create', [CounterCustomerController::class, 'create'])->name('admin.customer-create');
Route::middleware('staff:admin')->get('/admin/faq-management', [FaqManagementController::class, 'index'])->name('admin.faq-management');
Route::middleware('staff:admin')->match(['get', 'post'], '/admin/faq-save', [FaqManagementController::class, 'save'])->name('admin.faq-save');
