<?php

use App\Http\Controllers\Admin\FaqController;
use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: adminb — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/staff', PendingController::class)->name('admin.staff');
Route::match(['get', 'post'], '/admin/staff-save', PendingController::class)->name('admin.staff-save');
Route::get('/admin/admin-register', PendingController::class)->name('admin.admin-register');
Route::get('/admin/customer-management', PendingController::class)->name('admin.customer-management');
Route::match(['get', 'post'], '/admin/customer-save', PendingController::class)->name('admin.customer-save');
Route::match(['get', 'post'], '/admin/customer-search', PendingController::class)->name('admin.customer-search');
Route::match(['get', 'post'], '/admin/customer-verify', PendingController::class)->name('admin.customer-verify');
Route::middleware('staff')->get('/admin/faq-management', [FaqController::class, 'index'])->name('admin.faq-management');
Route::middleware('staff')->match(['get', 'post'], '/admin/faq-save', [FaqController::class, 'save'])->name('admin.faq-save');
