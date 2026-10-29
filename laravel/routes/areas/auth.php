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
Route::get('/customer/customer-register', Auth\CustomerRegisterController::class)->name('customer.customer-register');
Route::match(['get', 'post'], '/register-submit', Auth\RegisterSubmitController::class)->name('root.register-submit');
Route::match(['get', 'post'], '/customer/forgot-password', [Auth\PasswordResetController::class, 'forgot'])->name('customer.forgot-password');
Route::match(['get', 'post'], '/customer/reset-password', [Auth\PasswordResetController::class, 'reset'])->name('customer.reset-password');
Route::match(['get', 'post'], '/customer/google-start', [Auth\GoogleController::class, 'start'])->name('customer.google-start');
Route::match(['get', 'post'], '/customer/google-callback', [Auth\GoogleController::class, 'callback'])->name('customer.google-callback');
Route::match(['get', 'post'], '/customer/logout', [Auth\LogoutController::class, 'customer'])->name('customer.logout');
Route::get('/admin/profile', PendingController::class)->name('admin.profile');
Route::get('/customer/customer-profile', Auth\CustomerProfileController::class)->name('customer.customer-profile')->middleware('customer');
Route::match(['get', 'post'], '/profile-save', Auth\ProfileSaveController::class)->name('root.profile-save');
