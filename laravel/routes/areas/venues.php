<?php

use App\Http\Controllers\Admin\CatalogDeleteController;
use App\Http\Controllers\Admin\RoomPhotosApiController;
use App\Http\Controllers\Admin\RoomSaveController;
use App\Http\Controllers\Admin\RoomToggleActiveController;
use App\Http\Controllers\Admin\VenueFormController;
use App\Http\Controllers\Admin\VenueManagementController;
use App\Http\Controllers\Admin\VenuePhotoApiController;
use App\Http\Controllers\Admin\VenueSaveController;
use App\Http\Controllers\PendingController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

/* Area: venues — see the ownership table in LARAVEL-PORT.md.
   Every route below starts as a PendingController placeholder (501 "not ported yet").
   Port a page -> replace its line here. KEEP the name and the URL: other areas link to it. */

Route::get('/admin/venue-management', VenueManagementController::class)->middleware('staff')->name('admin.venue-management');
Route::get('/admin/venue-form', VenueFormController::class)->middleware('staff')->name('admin.venue-form');
Route::get('/admin/room-form', PendingController::class)->name('admin.room-form');
Route::get('/admin/hostel-room-form', PendingController::class)->name('admin.hostel-room-form');

/* The JSON endpoints answer exactly as the old scripts did: their own 401
   "session ended" JSON (no redirecting guard), then the old 400 "page has
   expired" JSON for a bad token — so they check the CSRF token themselves
   (Concerns\RepliesJson) instead of Laravel's 419 page. */
Route::withoutMiddleware(ValidateCsrfToken::class)->group(function () {
    Route::match(['get', 'post'], '/admin/venue-save', VenueSaveController::class)->name('admin.venue-save');
    Route::match(['get', 'post'], '/admin/venue-photo-api', VenuePhotoApiController::class)->name('admin.venue-photo-api');
    Route::match(['get', 'post'], '/admin/room-save', RoomSaveController::class)->name('admin.room-save');
    Route::match(['get', 'post'], '/admin/room-photos-api', RoomPhotosApiController::class)->name('admin.room-photos-api');
    Route::match(['get', 'post'], '/admin/room-toggle-active', RoomToggleActiveController::class)->name('admin.room-toggle-active');
    Route::match(['get', 'post'], '/admin/catalog-delete', CatalogDeleteController::class)->name('admin.catalog-delete');
});
