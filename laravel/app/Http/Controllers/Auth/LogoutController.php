<?php

namespace App\Http\Controllers\Auth;

use App\Http\Middleware\NoStore;
use App\Queries\ActivityQueries;

/* admin/logout.php and customer/logout.php — really end the session, then show
   the sign-in page. Plain GET links (sidebar, profile), as before. */
class LogoutController
{
    public function admin()
    {
        legacy('auth');
        self::logSignOut();
        venusep_logout();

        return NoStore::apply(redirect()->route('admin.admin-login'));
    }

    /* Also forgets this device if "Remember me" was ticked (#23), or the cookie
       would sign them straight back in. */
    public function customer()
    {
        legacy('auth', 'db', 'remember-me');
        $pdo = venusep_db();
        if ($pdo !== null) {
            remember_forget_current($pdo);
        } else {
            remember_cookie_clear();   // the row expires on its own; this device is forgotten either way
        }
        self::logSignOut();
        venusep_logout();

        return NoStore::apply(redirect()->route('customer.customer-login'));
    }

    /* Before the session ends: afterwards nobody is signed in to name. */
    private static function logSignOut(): void
    {
        if (session('user_id')) {
            ActivityQueries::add('sign_out', 'Sign-in: ' . (session('customer_email') ?? session('user_email') ?? 'user #' . session('user_id')), 'Signed out');
        }
    }
}
