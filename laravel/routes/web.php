<?php

use Illuminate\Support\Facades\Route;

/* VENUSeP: one route file per area of the site, in routes/areas/. */

/* The front door and customer/ itself: the landing page, as before. */
Route::redirect('/', '/customer/venues')->name('root.index');
Route::redirect('/customer', '/customer/venues')->name('customer.index');

/* Privacy notice: public, static. LINKED FROM NOWHERE until Aron says so. Then link it from:
   - partials/booking-footer.blade.php: the "Privacy" link (now points to the FAQ);
   - customer/venues.blade.php (landing page): the .lp-footer-bottom row (no Privacy link there yet);
   - auth/customer-register.blade.php: the "I agree to the Terms and Conditions" checkbox;
   - auth/customer-login.blade.php: the Google "Finish creating your account" terms checkbox (gsTerms).
   tests/Feature/PrivacyPageTest.php checks it is not linked yet: update that test then. */
Route::view('/privacy', 'privacy')->name('privacy');

/* Terms and Conditions: public, static. Linked from the customer register checkbox and the Google
   "Finish creating your account" checkbox (gsTerms), in a new tab so a half-filled form is kept.
   Not in any footer, and it does not link the privacy page (Aron 2026-11-05). */
Route::view('/terms', 'terms')->name('terms');

require __DIR__ . '/areas/auth.php';
require __DIR__ . '/areas/customer-booking.php';
require __DIR__ . '/areas/customer-account.php';
require __DIR__ . '/areas/staff-bookings.php';
require __DIR__ . '/areas/admin-venues.php';
require __DIR__ . '/areas/admin-settings.php';
require __DIR__ . '/areas/admin-people.php';
