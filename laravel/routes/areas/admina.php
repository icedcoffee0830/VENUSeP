<?php

use App\Http\Controllers\Admin;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: admina — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it.

   The JSON endpoints guard themselves (their own 401/403/400 JSON, in the old
   order) and check the CSRF token themselves (Admin\Concerns\JsonEndpoint), so
   they skip the redirecting middleware and Laravel's 419. */

Route::get('/admin/dashboard', Admin\DashboardController::class)->middleware('staff')->name('admin.dashboard');
Route::get('/admin/reports', Admin\ReportsController::class)->middleware('staff')->name('admin.reports');
Route::match(['get', 'post'], '/notifications', Admin\NotificationsController::class)->name('root.notifications');   // guards itself (JSON 401)
Route::get('/admin/backup-restore', Admin\BackupRestoreController::class)->middleware('staff')->name('admin.backup-restore');
Route::match(['get', 'post'], '/admin/backup-action', Admin\BackupActionController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('admin.backup-action');
Route::match(['get', 'post'], '/admin/demo-mode-switch', Admin\DemoModeSwitchController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('admin.demo-mode-switch');
Route::match(['get', 'post'], '/admin/refund-switch', Admin\RefundSwitchController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('admin.refund-switch');
Route::get('/admin/payment-settings', Admin\PaymentSettingsController::class)->middleware('staff')->name('admin.payment-settings');
Route::match(['get', 'post'], '/admin/discount-save', Admin\DiscountSaveController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('admin.discount-save');
Route::match(['get', 'post'], '/admin/gcash-account-save', Admin\GcashAccountSaveController::class)->withoutMiddleware(ValidateCsrfToken::class)->name('admin.gcash-account-save');
