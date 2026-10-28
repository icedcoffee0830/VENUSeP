<?php

use App\Http\Controllers\Auth;
use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: auth — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::match(['get', 'post'], '/admin/admin-login', Auth\AdminLoginController::class)->name('admin.admin-login');
Route::match(['get', 'post'], '/admin/logout', [Auth\LogoutController::class, 'admin'])->name('admin.logout');
Route::match(['get', 'post'], '/customer/customer-login', Auth\CustomerLoginController::class)->name('customer.customer-login');
Route::get('/customer/customer-register', PendingController::class)->name('customer.customer-register');
Route::match(['get', 'post'], '/register-submit', PendingController::class)->name('root.register-submit');
Route::match(['get', 'post'], '/customer/forgot-password', PendingController::class)->name('customer.forgot-password');
Route::match(['get', 'post'], '/customer/reset-password', PendingController::class)->name('customer.reset-password');
Route::match(['get', 'post'], '/customer/google-start', PendingController::class)->name('customer.google-start');
Route::match(['get', 'post'], '/customer/google-callback', PendingController::class)->name('customer.google-callback');
Route::match(['get', 'post'], '/customer/logout', [Auth\LogoutController::class, 'customer'])->name('customer.logout');
Route::get('/admin/profile', PendingController::class)->name('admin.profile');
Route::get('/customer/customer-profile', PendingController::class)->name('customer.customer-profile');
Route::match(['get', 'post'], '/profile-save', PendingController::class)->name('root.profile-save');
