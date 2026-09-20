<?php
/* =====================================================================
   REGISTER SUBMIT — the ONLY way an account is created.

   POST  csrf, kind=customer|staff, full_name, email, contact_number,
         password, confirm_password, terms
         staff also: venue_id (optional), and the CALLER MUST ALREADY BE AN ADMIN
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

   PASSWORDS are hashed with password_hash() and never logged, echoed or
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

$kind = ($_POST['kind'] ?? '') === 'staff' ? 'staff' : 'customer';

/* Creating a staff account is an ADMIN action, re-checked server-side. */
if ($kind === 'staff' && !admin_is_admin()) {
    rg_reply(403, ['ok' => false, 'message' => 'Only an administrator can create a staff account.']);
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
if ($kind === 'staff' && $venueRaw !== '') {
    if (!is_scalar($venueRaw) || !ctype_digit((string) $venueRaw) || (int) $venueRaw < 1) {
        rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Select a valid venue or Unassigned.']);
    }
    $venueCheck = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
    $venueCheck->execute([':id' => (int) $venueRaw]);
    $venueId = $venueCheck->fetchColumn();
    if ($venueId === false) {
        rg_reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue does not exist.']);
    }
    $venueId = (int) $venueId;
}

/* A username is required to be unique too, so derive one from the email's local
   part and add a suffix if it is taken. Never fail a registration over a
   username the person never chose and will never see. */
$base = preg_replace('/[^a-z0-9._-]+/', '', explode('@', $email)[0]);
if ($base === '') { $base = 'user'; }
$username = mb_substr($base, 0, 70);
try {
    $check = $pdo->prepare('SELECT 1 FROM users WHERE username = :u LIMIT 1');
    for ($i = 0; $i < 50; $i++) {
        $check->execute([':u' => $username]);
        if ($check->fetchColumn() === false) { break; }
        $username = mb_substr($base, 0, 66) . random_int(100, 9999);
    }
} catch (PDOException $e) {
    rg_reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO users (email, username, password_hash, account_type)
         VALUES (:e, :u, :p, :t)'
    )->execute([
        ':e' => $email,
        ':u' => $username,
        ':p' => password_hash($pass, PASSWORD_DEFAULT),
        ':t' => $kind === 'staff' ? 'staff' : 'customer',
    ]);
    $userId = (int) $pdo->lastInsertId();

    if ($kind === 'staff') {
        $pdo->prepare(
            'INSERT INTO staff (user_id, venue_id, full_name, phone) VALUES (:u, :v, :n, :p)'
        )->execute([':u' => $userId, ':v' => $venueId, ':n' => $name, ':p' => $phoneNorm]);
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

/* A new CUSTOMER is not logged in automatically: they go to the login page and
   sign in, which proves the password they just chose actually works. A staff
   account is created BY an admin, so logging in as them would be wrong —
   the admin stays signed in as themselves. */
rg_reply(200, [
    'ok'      => true,
    'kind'    => $kind,
    'message' => $kind === 'staff'
        ? 'Staff account created.'
        : 'Account created. You can sign in now.',
]);
