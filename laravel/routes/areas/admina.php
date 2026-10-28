<?php

use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: admina — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/dashboard', PendingController::class)->name('admin.dashboard');
Route::get('/admin/reports', PendingController::class)->name('admin.reports');
Route::match(['get', 'post'], '/notifications', PendingController::class)->name('root.notifications');
Route::get('/admin/backup-restore', PendingController::class)->name('admin.backup-restore');
Route::match(['get', 'post'], '/admin/backup-action', PendingController::class)->name('admin.backup-action');
Route::match(['get', 'post'], '/admin/demo-mode-switch', PendingController::class)->name('admin.demo-mode-switch');
Route::match(['get', 'post'], '/admin/refund-switch', PendingController::class)->name('admin.refund-switch');
Route::get('/admin/payment-settings', PendingController::class)->name('admin.payment-settings');
Route::match(['get', 'post'], '/admin/discount-save', PendingController::class)->name('admin.discount-save');
Route::match(['get', 'post'], '/admin/gcash-account-save', PendingController::class)->name('admin.gcash-account-save');
