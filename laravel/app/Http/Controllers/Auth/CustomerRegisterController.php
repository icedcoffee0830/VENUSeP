<?php

namespace App\Http\Controllers\Auth;

/* customer/customer-register.php — public sign-up; the form posts (JS) to
   register-submit with the session's CSRF token. */
class CustomerRegisterController
{
    public function __invoke()
    {
        legacy('auth', 'google-auth');   /* google_enabled() — sign-up with Google (#23) */

        return view('auth.customer-register', [
            'crCsrf' => csrf_token(),
            'crGoogleOn' => google_enabled(),
        ]);
    }
}
