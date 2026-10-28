<?php

use App\Http\Controllers\PendingController;
use Illuminate\Support\Facades\Route;

/* Area: venues — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/venue-management', PendingController::class)->name('admin.venue-management');
Route::get('/admin/venue-form', PendingController::class)->name('admin.venue-form');
Route::match(['get', 'post'], '/admin/venue-save', PendingController::class)->name('admin.venue-save');
Route::match(['get', 'post'], '/admin/venue-photo-api', PendingController::class)->name('admin.venue-photo-api');
Route::get('/admin/room-form', PendingController::class)->name('admin.room-form');
Route::get('/admin/hostel-room-form', PendingController::class)->name('admin.hostel-room-form');
Route::match(['get', 'post'], '/admin/room-save', PendingController::class)->name('admin.room-save');
Route::match(['get', 'post'], '/admin/room-photos-api', PendingController::class)->name('admin.room-photos-api');
Route::match(['get', 'post'], '/admin/room-toggle-active', PendingController::class)->name('admin.room-toggle-active');
Route::match(['get', 'post'], '/admin/catalog-delete', PendingController::class)->name('admin.catalog-delete');
