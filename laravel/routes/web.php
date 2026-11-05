<?php

use Illuminate\Support\Facades\Route;

/* VENUSeP — one route file per area (see LARAVEL-PORT.md). Each porter edits
   ONLY routes/areas/<their area>.php. */

/* The front door and customer/ itself: the landing page, as before. */
Route::redirect('/', '/customer/venues')->name('root.index');
Route::redirect('/customer', '/customer/venues')->name('customer.index');

/* Privacy notice: public, static. LINKED FROM NOWHERE until Aron says so. Then link it from:
   - partials/booking-footer.blade.php: the "Privacy" link (now points to the FAQ);
   - customer/venue-booking.blade.php (landing page): the .lp-footer-bottom row (no Privacy link there yet);
   - auth/customer-register.blade.php: the "I agree to the Terms and Conditions" checkbox;
   - auth/customer-login.blade.php: the Google "Finish creating your account" terms checkbox (gsTerms).
   tests/Feature/PrivacyPageTest.php checks it is not linked yet: update that test then. */
Route::view('/privacy', 'privacy')->name('privacy');

/* Terms and Conditions: public, static. LINKED FROM NOWHERE until Aron says so. Then link it from:
   - auth/customer-register.blade.php: the checkbox becomes "I agree to the Terms and Conditions and the Privacy Notice", both linked;
   - auth/customer-login.blade.php: the same on the Google "Finish creating your account" checkbox (gsTerms);
   - partials/booking-footer.blade.php and customer/venue-booking.blade.php (.lp-footer-bottom): beside the Privacy link.
   Agreed then too: admin-register.blade.php loses its terms checkbox, its JS check and any server check.
   tests/Feature/TermsPageTest.php checks it is not linked yet: update that test then. */
Route::view('/terms', 'terms')->name('terms');

require __DIR__ . '/areas/auth.php';
require __DIR__ . '/areas/cbook.php';
require __DIR__ . '/areas/cacct.php';
require __DIR__ . '/areas/abook.php';
require __DIR__ . '/areas/venues.php';
require __DIR__ . '/areas/admina.php';
require __DIR__ . '/areas/adminb.php';
