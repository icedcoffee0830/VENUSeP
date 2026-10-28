<?php
/* =====================================================================
   REGISTER SUBMIT — the ONLY way an account is created.

   POST  csrf, kind=customer|staff|admin, full_name, email, contact_number,
         password, confirm_password, terms
         staff also: venue_id (required), and the CALLER MUST ALREADY BE AN ADMIN
         admin also: creator_password — the CALLER's own password, re-checked
   Replies with JSON.

   One endpoint for both because the checks are the same and the only
   real difference is which profile table the row lands in: a customer
   gets a `customers` row, a staff member a `staff` row. Two endpoints
   would be two places to get password handling wrong.

   WHO MAY CREATE WHAT
     customer  anyone — this is public sign-up
     staff     ADMINS ONLY, re-checked here. admin-register.php already
               guards the page with admin_require_login(['admin']), but a
               page guard is not a security boundary: the endpoint is what
               someone would POST to directly.
     admin     ADMINS ONLY, and the admin re-enters THEIR OWN password
               (2026-10-27). An admin can do everything, so a borrowed admin
               session must not be enough to mint another one. The check uses
               the same users.reauth_* lockout as the refund switch and the
               GCash accounts: one shared budget. The new admin has no venue
               (admins handle all of them) and sets up two-step verification
               on their first sign-in (admin-login.php enrols them).

   PASSWORDS are hashed with Argon2id (venusep_password_hash(),
   includes/passwords.php, DB-DECISIONS #21) and never logged, echoed or
   returned. The plaintext leaves scope the moment the hash is made.

   EMAIL UNIQUENESS is enforced by uq_users_email, not by a SELECT before
   the INSERT: two people registering the same address in the same
   instant would both pass a pre-check and one would still fail. The
   constraint is the real answer, so the duplicate is caught by catching
   it. The reply is deliberately the same friendly sentence either way.

   DEMO MODE NOTE (not built yet — see the demo-mode design): registration
   is the ONE flow that writes for real even while demo mode is on,
   because an account that vanishes is not an account. Nothing here needs
   to change when that lands.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/passwords.php';   /* venusep_password_hash() — Argon2id (#21) */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rg_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rg_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    rg_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$kindRaw = (string) ($_POST['kind'] ?? '');
$kind = in_array($kindRaw, ['staff', 'admin'], true) ? $kindRaw : 'customer';

/* Creating a staff or admin account is an ADMIN action, re-checked server-side. */
if ($kind !== 'customer' && !admin_is_admin()) {
    rg_reply(403, ['ok' => false, 'message' => 'Only an administrator can create a staff or admin account.']);
}

$name    = trim((string) ($_POST['full_name'] ?? ''));
$email   = strtolower(trim((string) ($_POST['email'] ?? '')));
$phone   = trim((string) ($_POST['contact_number'] ?? ''));
$pass    = (string) ($_POST['password'] ?? '');
$confirm = (string) ($_POST['confirm_password'] ?? '');
$venueRaw = $_POST['venue_id'] ?? '';

if ($name === '' || mb_strlen($name) > 190) {
    rg_reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter your full name.']);
}
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
    rg_reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
}
if ($pass === '' || strlen($pass) < 8) {
    rg_reply(400, ['ok' => false, 'field' => 'password', 'message' => 'Use a password of at least 8 characters.']);
}
if ($pass !== $confirm) {
    rg_reply(400, ['ok' => false, 'field' => 'confirm_password', 'message' => 'The two passwords do not match.']);
}
if (empty($_POST['terms'])) {
    rg_reply(400, ['ok' => false, 'field' => 'terms', 'message' => 'Please accept the terms to continue.']);
}
/* A phone is optional, but if one is given it has to be a PH mobile — this is
   the number a GCash refund would be sent to, so a typo here costs money
   later. cb_normalise_mobile() is the same rule both portals compare with. */
require_once __DIR__ . '/includes/bookings.php';
$phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
if ($phone !== '' && $phoneNorm === null) {
    rg_reply(400, ['ok' => false, 'field' => 'contact_number',
        'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    rg_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so the account was not created.']);
}

$venueId = null;
/* Every staff account belongs to ONE venue (decided 2026-10-27): there are no
   unassigned staff, because a staff member handles only their own venue's
   bookings. trg_staff_venue_required enforces the same rule in the database. */
if ($kind === 'staff' && $venueRaw === '') {
    rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Choose the venue this staff member will handle.']);
}
if ($kind === 'staff') {
    if (!is_scalar($venueRaw) || !ctype_digit((string) $venueRaw) || (int) $venueRaw < 1) {
        rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Select a valid venue.']);
    }
    $venueCheck = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
    $venueCheck->execute([':id' => (int) $venueRaw]);
    $venueId = $venueCheck->fetchColumn();
    if ($venueId === false) {
        rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue does not exist.']);
    }
    $venueId = (int) $venueId;
}

/* A NEW ADMIN needs the creating admin's own password (see the header). Checked
   before anything is written, with the shared re-entry lockout: 5 wrong = a
   lock, each longer than the last (admin/gcash-account-save.php, same ladder). */
if ($kind === 'admin') {
    $creatorPw = (string) ($_POST['creator_password'] ?? '');
    if ($creatorPw === '') {
        rg_reply(400, ['ok' => false, 'field' => 'creator_password', 'error' => 'no_password',
            'message' => 'Enter your own admin password to create another administrator.']);
    }
    try {
        $pdo->beginTransaction();
        $u = $pdo->prepare(
            'SELECT id, password_hash, account_type, is_active, reauth_failed_attempts, reauth_lock_level,
                    CASE WHEN reauth_locked_until > NOW()
                         THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
               FROM users WHERE id = :id FOR UPDATE'
        );
        $u->execute([':id' => (int) $_SESSION['user_id']]);
        $creator = $u->fetch();
        if (!$creator || !(bool) $creator['is_active'] || $creator['account_type'] !== 'admin') {
            $pdo->rollBack();
            rg_reply(403, ['ok' => false, 'message' => 'Only an administrator can create an admin account.']);
        }
        if ((int) $creator['lock_seconds'] > 0) {
            $pdo->rollBack();
            rg_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => (int) $creator['lock_seconds'],
                'message' => 'Too many wrong passwords. Try again in ' . (int) $creator['lock_seconds'] . ' seconds.']);
        }
        if (!password_verify($creatorPw, (string) $creator['password_hash'])) {
            $attempts = (int) $creator['reauth_failed_attempts'] + 1;
            $ladder = [10, 30, 60, 300, 900];
            if ($attempts >= 5) {
                $level = min((int) $creator['reauth_lock_level'] + 1, 255);
                $seconds = $ladder[min($level, count($ladder)) - 1];
                $pdo->prepare(
                    'UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = :l,
                            reauth_locked_until = DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE id = :id'
                )->execute([':l' => $level, ':s' => $seconds, ':id' => $creator['id']]);
                $pdo->commit();
                rg_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $seconds,
                    'message' => 'Too many wrong passwords. Try again in ' . $seconds . ' seconds.']);
            }
            $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')
                ->execute([':a' => $attempts, ':id' => $creator['id']]);
            $pdo->commit();
            rg_reply(401, ['ok' => false, 'field' => 'creator_password', 'error' => 'wrong_password',
                'attemptsLeft' => 5 - $attempts,
                'message' => 'That is not your password. ' . (5 - $attempts) . ' attempt(s) left before a temporary lock.']);
        }
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')
            ->execute([':id' => $creator['id']]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('VENUSeP register-submit (admin re-auth): ' . $e->getMessage());
        rg_reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
    }
}

/* A unique username derived from the email — the same rule Google sign-up uses. */
require_once __DIR__ . '/includes/accounts.php';
try {
    $username = venusep_unique_username($pdo, $email);
} catch (PDOException $e) {
    rg_reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
}

/* Argon2id is required (DB-DECISIONS #21): refuse before touching the database. */
if (!venusep_password_ready()) {
    rg_reply(500, ['ok' => false, 'message' => VENUSEP_PASSWORD_SETUP_ERROR]);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO users (email, username, password_hash, account_type)
         VALUES (:e, :u, :p, :t)'
    )->execute([
        ':e' => $email,
        ':u' => $username,
        ':p' => venusep_password_hash($pass),
        ':t' => $kind,
    ]);
    $userId = (int) $pdo->lastInsertId();

    if ($kind !== 'customer') {
        /* staff: their one venue; admin: none — they handle every venue */
        $pdo->prepare(
            'INSERT INTO staff (user_id, venue_id, full_name, phone) VALUES (:u, :v, :n, :p)'
        )->execute([':u' => $userId, ':v' => $kind === 'staff' ? $venueId : null, ':n' => $name, ':p' => $phoneNorm]);
    } else {
        $pdo->prepare(
            'INSERT INTO customers (user_id, full_name, phone) VALUES (:u, :n, :p)'
        )->execute([':u' => $userId, ':n' => $name, ':p' => $phoneNorm]);
    }

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    /* 23000 here is normally uq_users_email (or, vanishingly, the username
       race the loop above did not win). A venue could also be deleted between
       validation and insert, so that FK is reported against the venue field.
       Email conflicts are reported as a plain "already
       registered" — and deliberately NOT as "that password was wrong" or
       anything that would let someone probe which addresses exist beyond what
       a sign-up form inevitably reveals. */
    if ($e->getCode() === '23000') {
        if ($kind === 'staff' && strpos($e->getMessage(), 'fk_staff_venue') !== false) {
            rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue no longer exists.']);
        }
        rg_reply(409, ['ok' => false, 'field' => 'email',
            'message' => 'That email address is already registered. Try logging in instead.']);
    }
    error_log('VENUSeP register-submit: ' . $e->getMessage());
    rg_reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
}

/* A new staff or admin account is announced to the other admins (2026-10-28).
   A failure costs a notification, never the account. */
if ($kind !== 'customer') {
    require_once __DIR__ . '/includes/notifications.php';
    try {
        $venueName = '';
        if ($venueId !== null) {
            $vn = $pdo->prepare('SELECT name FROM venues WHERE id = :v');
            $vn->execute([':v' => $venueId]);
            $venueName = (string) $vn->fetchColumn();
        }
        notify_admins($pdo, 'account_created', (int) $_SESSION['user_id'],
            ['email' => $email, 'role' => $kind, 'venue' => $venueName]);
    } catch (PDOException $e) {
        error_log('VENUSeP register-submit (notify): ' . $e->getMessage());
    }
}

/* A new CUSTOMER is not logged in automatically: they go to the login page and
   sign in, which proves the password they just chose actually works. A staff
   account is created BY an admin, so logging in as them would be wrong —
   the admin stays signed in as themselves. */
rg_reply(200, [
    'ok'      => true,
    'kind'    => $kind,
    'message' => $kind === 'admin'
        ? 'Administrator account created. They set up two-step verification the first time they sign in.'
        : ($kind === 'staff' ? 'Staff account created.' : 'Account created. You can sign in now.'),
]);
