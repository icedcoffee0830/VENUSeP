<?php

use App\Http\Controllers\Admin;
use Illuminate\Support\Facades\Route;

/* Dashboard, reports, notifications, backup, payment and office settings, activity. Keep every URL
   and route name: links, emails and bookmarks use them.
   The JSON endpoints guard themselves (their own 401/403/400 JSON) and check the CSRF token
   themselves (Admin\Concerns\JsonEndpoint), so they skip the redirecting middleware and Laravel's 419. */

Route::get('/admin/dashboard', Admin\DashboardController::class)->middleware('staff')->name('admin.dashboard');
Route::get('/admin/reports', Admin\ReportsController::class)->middleware('staff')->name('admin.reports');
Route::match(['get', 'post'], '/notifications', Admin\NotificationsController::class)->name('root.notifications');   // guards itself (JSON 401)
Route::get('/admin/backup-restore', Admin\BackupRestoreController::class)->middleware('staff:super')->name('admin.backup-restore');
Route::match(['get', 'post'], '/admin/backup-action', Admin\BackupActionController::class)->name('admin.backup-action');
Route::match(['get', 'post'], '/admin/refund-switch', Admin\RefundSwitchController::class)->name('admin.refund-switch');
Route::get('/admin/payment-settings', Admin\PaymentSettingsController::class)->middleware('staff')->name('admin.payment-settings');
Route::middleware('staff:admin')->get('/admin/office-settings', [Admin\OfficeSettingsController::class, 'index'])->name('admin.office-settings');
Route::middleware('staff:super')->get('/admin/activity', Admin\ActivityController::class)->name('admin.activity');
Route::post('/admin/office-save', [Admin\OfficeSettingsController::class, 'save'])->name('admin.office-save');
Route::match(['get', 'post'], '/admin/gcash-account-save', Admin\GcashAccountSaveController::class)->name('admin.gcash-account-save');
