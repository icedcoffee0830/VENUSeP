<?php
/* =====================================================================
   GCASH ACCOUNT SAVE — the ONLY way a venue's payout account changes.

   POST  csrf, venue, account_name, mobile_number, password, note
   Replies with JSON.

   WHY THIS ONE ASKS FOR THE PASSWORD
   ----------------------------------
   This number is where customers' money goes. It is shown on the payment
   screen as "send it here" AND checked by the receipt verifier as "it
   should have landed here" — both from this row, which is what keeps them
   agreeing. Change it and every future payment for that venue follows.

   That makes it the most attractive thing in the system to tamper with:
   anyone who got hold of an admin session could point a venue's payments
   at their own number and simply collect. So it re-asks for the password,
   using the same users.reauth_* lockout the refund switch uses — the
   schema calls those columns "password RE-ENTRY lockout for sensitive
   admin actions", and redirecting money qualifies more than most.

   ADMIN ONLY, re-checked here. Staff run bookings; they do not decide
   where the money lands.

   A CHANGE IS A NEW ROW, NOT AN EDIT. The old account is superseded
   (valid_until set) and a new active one inserted. A receipt paid to
   last month's number can then still be explained, instead of looking
   like it was sent somewhere the system never used. uq_gcash_one_active
   _per_venue keeps exactly one current account per venue.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function gs_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    gs_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
if (!isset($_SESSION['user_id']) || !admin_is_admin()) {
    gs_reply(403, ['ok' => false, 'error' => 'not_admin',
        'message' => 'Only an administrator can change where a venue is paid.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    gs_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$venueName = trim((string) ($_POST['venue'] ?? ''));
$acctName  = trim((string) ($_POST['account_name'] ?? ''));
$digits    = preg_replace('/\D+/', '', (string) ($_POST['mobile_number'] ?? ''));
$note      = trim((string) ($_POST['note'] ?? ''));
$password  = (string) ($_POST['password'] ?? '');

if ($venueName === '') {
    gs_reply(400, ['ok' => false, 'message' => 'Which venue?']);
}
/* The account NAME matters as much as the number: GCash masks the number on a
   receipt, and the checker falls back to matching visible letter-runs of the
   name. An empty name silently weakens every verification. */
if ($acctName === '' || mb_strlen($acctName) > 150) {
    gs_reply(400, ['ok' => false, 'field' => 'account_name',
        'message' => 'Enter the account name — the checker matches it when the number is masked on a receipt.']);
}
/* 11 digits starting 09. A malformed number would not error anywhere: it would
   simply reject every receipt for this venue, quietly, and look like the
   scanner had broken. */
if (strlen($digits) !== 11 || substr($digits, 0, 2) !== '09') {
    gs_reply(400, ['ok' => false, 'field' => 'mobile_number',
        'message' => 'Needs 11 digits starting with 09. The checker compares digits, so any other shape rejects every receipt.']);
}
if ($password === '') {
    gs_reply(400, ['ok' => false, 'error' => 'no_password',
        'message' => 'Enter your admin password to confirm this change.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    gs_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
}

try {
    $pdo->beginTransaction();

    /* Re-read the account from the database and re-check the password. The
       session says who logged in; it does not authorise this. FOR UPDATE so two
       attempts at once both count against the lockout. */
    $u = $pdo->prepare(
        'SELECT id, password_hash, account_type, is_active,
                reauth_failed_attempts, reauth_lock_level,
                CASE WHEN reauth_locked_until > NOW()
                     THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
           FROM users WHERE id = :id FOR UPDATE'
    );
    $u->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $u->fetch();
    if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin') {
        $pdo->rollBack();
        gs_reply(403, ['ok' => false, 'message' => 'Only an administrator can change where a venue is paid.']);
    }
    if ((int) $user['lock_seconds'] > 0) {
        $pdo->rollBack();
        gs_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => (int) $user['lock_seconds'],
            'message' => 'Too many wrong passwords.']);
    }
    if (!password_verify($password, $user['password_hash'])) {
        /* Same ladder as the refund switch: 5 wrong = a lock, each longer than
           the last. Shared deliberately — it is one "sensitive admin action"
           budget, not one per feature, so an attacker cannot get five fresh
           attempts by switching which setting they poke at. */
        $attempts = (int) $user['reauth_failed_attempts'] + 1;
        $ladder = [10, 30, 60, 300, 900];
        if ($attempts >= 5) {
            $level = min((int) $user['reauth_lock_level'] + 1, 255);
            $seconds = $ladder[min($level, count($ladder)) - 1];
            $pdo->prepare(
                'UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = :l,
                        reauth_locked_until = DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE id = :id'
            )->execute([':l' => $level, ':s' => $seconds, ':id' => $user['id']]);
            $pdo->commit();
            gs_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $seconds,
                'message' => 'Too many wrong passwords.']);
        }
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')
            ->execute([':a' => $attempts, ':id' => $user['id']]);
        $pdo->commit();
        gs_reply(401, ['ok' => false, 'error' => 'wrong_password',
            'attemptsLeft' => 5 - $attempts, 'message' => 'Wrong password.']);
    }
    $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')
        ->execute([':id' => $user['id']]);

    $v = $pdo->prepare('SELECT id FROM venues WHERE name = :n');
    $v->execute([':n' => $venueName]);
    $venueId = $v->fetchColumn();
    if ($venueId === false) {
        $pdo->rollBack();
        gs_reply(404, ['ok' => false, 'message' => 'Unknown venue.']);
    }

    /* Nothing to do if neither field moved — avoids filling the history with
       rows that record no change. */
    $cur = $pdo->prepare(
        'SELECT id, account_name, mobile_number FROM gcash_accounts
          WHERE venue_id = :v AND is_active = 1 AND valid_until IS NULL'
    );
    $cur->execute([':v' => $venueId]);
    $current = $cur->fetch();
    if ($current && $current['account_name'] === $acctName && $current['mobile_number'] === $digits) {
        $pdo->rollBack();
        gs_reply(400, ['ok' => false, 'message' => 'That is already this venue\'s account.']);
    }

    /* Supersede, then insert. Both inside one transaction: the UNIQUE index
       allows only one active account per venue, so a half-done change would
       either leave the venue with none or refuse outright. */
    if ($current) {
        $pdo->prepare('UPDATE gcash_accounts SET is_active = 0, valid_until = NOW() WHERE id = :id')
            ->execute([':id' => $current['id']]);
    }
    $pdo->prepare(
        'INSERT INTO gcash_accounts (venue_id, account_name, mobile_number, note, created_by_user_id)
         VALUES (:v, :n, :m, :o, :u)'
    )->execute([
        ':v' => $venueId, ':n' => $acctName, ':m' => $digits,
        ':o' => $note !== '' ? mb_substr($note, 0, 500) : null,
        ':u' => (int) $_SESSION['user_id'],
    ]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP gcash-account-save: ' . $e->getMessage());
    gs_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
}

gs_reply(200, [
    'ok'      => true,
    'venue'   => $venueName,
    'name'    => $acctName,
    'number'  => $digits,
    'previous'=> $current ? $current['mobile_number'] : null,
    'message' => 'Saved. New payments for ' . $venueName . ' go to this account, and receipts are checked against it.',
]);
