<?php
/* =====================================================================
   ACCOUNTS — the pieces every way of signing up or signing in shares, so
   a password sign-in, a Google sign-in and a remembered device can never
   drift apart.

     venusep_unique_username()  register-submit.php and Google sign-up
     customer_session_open()    the only place a customer session is made
     cl_finish_login()          customer-login.php — reached by a password,
                                a two-step code, or Google; then redirects
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/two-factor.php';   /* tfa_pending_clear() */

/* A username is required to be unique too, so derive one from the email's local
   part and add a suffix if it is taken. Never fail a registration over a
   username the person never chose and will never see. Throws PDOException. */
function venusep_unique_username(PDO $pdo, string $email): string
{
    $base = preg_replace('/[^a-z0-9._-]+/', '', strtolower(explode('@', $email)[0]));
    if ($base === '') { $base = 'user'; }
    $username = mb_substr($base, 0, 70);
    $check = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
    for ($i = 0; $i < 50; $i++) {
        $check->execute([':u' => $username]);
        if ($check->fetchColumn() === false) { break; }
        $username = mb_substr($base, 0, 66) . random_int(100, 9999);
    }
    return $username;
}

/* The only place a customer session is created. Re-reads the account because
   a code step, a trip to Google, or 15 days on a remembered device may sit
   between the proof and this. False (and nothing changed) for an account that
   is gone, suspended, or not a customer. */
function customer_session_open(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare(
        'SELECT u.id AS user_id, u.email, u.is_active, c.id AS customer_id, c.full_name
           FROM users u
           INNER JOIN customers c ON c.user_id = u.id
          WHERE u.id = :id AND u.account_type = :account_type
          LIMIT 1'
    );
    $stmt->execute([':id' => $userId, ':account_type' => 'customer']);
    $customer = $stmt->fetch();
    if (!$customer || !$customer['is_active']) {
        return false;
    }

    // Prevent session fixation after successful authentication.
    session_regenerate_id(true);

    // Start from an empty session so nothing from a previous login
    // (e.g. an admin on the same browser) survives into this one.
    $_SESSION = [];
    $_SESSION['user_id'] = (int)$customer['user_id'];
    $_SESSION['customer_id'] = (int)$customer['customer_id'];
    $_SESSION['account_type'] = 'customer';
    $_SESSION['customer_name'] = $customer['full_name'];
    $_SESSION['customer_email'] = $customer['email'];
    /* When this session proved who it is: a password change after this moment
       ends it (customer_session_heal(), #23). */
    $_SESSION['auth_at'] = time();

    $update = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
    $update->execute([':id' => $customer['user_id']]);
    return true;
}

/* Signs the customer in and sends them to the booking page; never returns.
   $remember: they ticked "Remember me" — this device stays signed in for
   REMEMBER_DAYS (includes/remember-me.php, #23). Only reached after the full
   proof, two-step code included, so a remembered device can skip the code. */
function cl_finish_login(PDO $pdo, int $userId, bool $remember = false): void
{
    if (!customer_session_open($pdo, $userId)) {
        tfa_pending_clear();
        header('Location: customer-login.php');
        exit;
    }
    if ($remember) {
        require_once __DIR__ . '/remember-me.php';
        try {
            remember_issue($pdo, $userId);
        } catch (Throwable $e) {
            error_log('VENUSeP remember me: ' . $e->getMessage());   // signed in anyway, just not remembered
        }
    }
    header('Location: venusep_venue_booking.php');
    exit;
}
