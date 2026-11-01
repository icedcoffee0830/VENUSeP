<?php

use Illuminate\Support\Facades\Route;

/* VENUSeP — one route file per area (see LARAVEL-PORT.md). Each porter edits
   ONLY routes/areas/<their area>.php. */

/* The front door and customer/ itself: the landing page, as before. */
Route::redirect('/', '/customer/venues')->name('root.index');
Route::redirect('/customer', '/customer/venues')->name('customer.index');

require __DIR__ . '/areas/auth.php';
require __DIR__ . '/areas/cbook.php';
require __DIR__ . '/areas/cacct.php';
require __DIR__ . '/areas/abook.php';
require __DIR__ . '/areas/venues.php';
require __DIR__ . '/areas/admina.php';
require __DIR__ . '/areas/adminb.php';
