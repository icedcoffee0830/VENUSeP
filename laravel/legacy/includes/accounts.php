<?php
/* Holds: what every way of signing up or signing in shares (usernames, the signed-in session). */
/* =====================================================================
   ACCOUNTS — the pieces every way of signing up or signing in shares, so
   a password sign-in, a Google sign-in and a remembered device can never
   drift apart.

     venusep_unique_username()  RegisterSubmitController and Google sign-up
     customer_session_open()    the only place a customer session is made
     cl_finish_login()          customer-login.php — reached by a password,
                                a two-step code, or Google; then redirects
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/two-factor.php';   /* tfa_pending_clear() */

/* A username is required to be unique too, so derive one from the email's local
   part and add a suffix if it is taken. Never fail a registration over a
   username the person never chose and will never see. Throws PDOException. */
function venusep_unique_username(string $email): string
{
    $base = preg_replace('/[^a-z0-9._-]+/', '', strtolower(explode('@', $email)[0]));
    if ($base === '') { $base = 'user'; }
    $username = mb_substr($base, 0, 70);
    for ($i = 0; $i < 50; $i++) {
        if (!\App\Queries\AccountQueries::usernameTaken($username)) { break; }
        $username = mb_substr($base, 0, 66) . random_int(100, 9999);
    }
    return $username;
}

/* The only place a customer session is created. Re-reads the account because
   a code step, a trip to Google, or 15 days on a remembered device may sit
   between the proof and this. False (and nothing changed) for an account that
   is gone, suspended, or not a customer. */
function customer_session_open(int $userId): bool
{
    $customer = \App\Queries\AccountQueries::findCustomerForSession($userId);
    if (!$customer || !$customer['is_active']) {
        return false;
    }

    // Start from an empty session so nothing from a previous login
    // (e.g. an admin on the same browser) survives into this one, and
    // prevent session fixation after successful authentication.
    // LARAVEL: flush() + regenerate(true) = the old $_SESSION = [] + session_regenerate_id(true).
    session()->flush();
    session()->regenerate(true);
    session([
        'user_id' => (int)$customer['user_id'],
        'customer_id' => (int)$customer['customer_id'],
        'account_type' => 'customer',
        'customer_name' => $customer['full_name'],
        'customer_email' => $customer['email'],
        /* When this session proved who it is: a password change after this moment
           ends it (customer_session_heal(), #23). */
        'auth_at' => time(),
    ]);

    \App\Queries\AccountQueries::markSignedIn((int) $customer['user_id']);
    return true;
}

/* Signs the customer in and sends them to the booking page; never returns.
   $remember: they ticked "Remember me" — this device stays signed in for
   REMEMBER_DAYS (remember-me.php, #23). Only reached after the full
   proof, two-step code included, so a remembered device can skip the code. */
function cl_finish_login(PDO $pdo, int $userId, bool $remember = false): void
{
    if (!customer_session_open($userId)) {
        tfa_pending_clear();
        tfa_redirect_now(route('customer.customer-login'));
    }
    if ($remember) {
        require_once __DIR__ . '/remember-me.php';
        try {
            remember_issue($pdo, $userId);
        } catch (Throwable $e) {
            sql_failed($e, optional: true);   // signed in anyway, just not remembered
        }
    }
    tfa_redirect_now(route('customer.venues'));
}
