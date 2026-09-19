<?php
/* =====================================================================
   DEMO MODE SWITCH — the ONLY way system_settings.demo_mode changes.
   Called by the Demo Mode card on admin/venusep_profile.php.

   POST  csrf, enable=0|1, password
   Replies with JSON.

   WHY THIS ASKS FOR THE PASSWORD
   ------------------------------
   Turning demo mode ON in production is the most damaging single action
   available in this system. Customers would go through the whole booking
   flow, be given a reference number, and have nothing recorded — worse
   than an outage, because an outage is visible and this is not.

   So it is treated like the refund switch and the payout account: admin
   only, re-checked server-side, password re-entered, and every change
   written to system_settings_history. Same users.reauth_* lockout, shared
   on purpose so five wrong guesses cost an attacker their whole budget of
   sensitive-action attempts rather than five per feature.

   TURNING IT OFF CLEARS THE OVERLAY. Whatever this browser pretended to
   book is dropped, so the next person does not inherit someone else's
   props — and so a half-finished demo cannot linger behind a switch that
   now says the system is live.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/demo-mode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function dm_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    dm_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
if (!isset($_SESSION['user_id']) || !admin_is_admin()) {
    dm_reply(403, ['ok' => false, 'error' => 'not_admin',
        'message' => 'Only an administrator can change demo mode.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    dm_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$enable = isset($_POST['enable']) && in_array((string) $_POST['enable'], ['0', '1'], true)
    ? (string) $_POST['enable'] : null;
if ($enable === null) {
    dm_reply(400, ['ok' => false, 'message' => 'Invalid request.']);
}
$password = (string) ($_POST['password'] ?? '');
if ($password === '') {
    dm_reply(400, ['ok' => false, 'error' => 'no_password',
        'message' => 'Enter your admin password to confirm.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    dm_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
}

try {
    $pdo->beginTransaction();

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
        dm_reply(403, ['ok' => false, 'message' => 'Only an administrator can change demo mode.']);
    }
    if ((int) $user['lock_seconds'] > 0) {
        $pdo->rollBack();
        dm_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => (int) $user['lock_seconds'],
            'message' => 'Too many wrong passwords.']);
    }
    if (!password_verify($password, $user['password_hash'])) {
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
            dm_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $seconds,
                'message' => 'Too many wrong passwords.']);
        }
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')
            ->execute([':a' => $attempts, ':id' => $user['id']]);
        $pdo->commit();
        dm_reply(401, ['ok' => false, 'error' => 'wrong_password',
            'attemptsLeft' => 5 - $attempts, 'message' => 'Wrong password.']);
    }
    $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')
        ->execute([':id' => $user['id']]);

    $cur = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'demo_mode'")->fetchColumn();
    if ((string) $cur === $enable) {
        $pdo->rollBack();
        dm_reply(400, ['ok' => false, 'message' => 'Demo mode is already ' . ($enable === '1' ? 'on' : 'off') . '.']);
    }

    $pdo->prepare("UPDATE system_settings SET setting_value = :v, updated_by_user_id = :u WHERE setting_key = 'demo_mode'")
        ->execute([':v' => $enable, ':u' => (int) $_SESSION['user_id']]);
    $pdo->prepare(
        "INSERT INTO system_settings_history (setting_key, old_value, new_value, change_note, changed_by_user_id)
         VALUES ('demo_mode', :o, :n, :note, :u)"
    )->execute([
        ':o' => (string) $cur, ':n' => $enable,
        ':note' => $enable === '1'
            ? 'Demo mode ON — bookings, payments and refunds stop being written.'
            : 'Demo mode OFF — the system is live again.',
        ':u' => (int) $_SESSION['user_id'],
    ]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP demo-mode-switch: ' . $e->getMessage());
    dm_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
}

/* Drop whatever this browser pretended to book. */
demo_reset();

dm_reply(200, [
    'ok' => true, 'enabled' => $enable === '1',
    'message' => $enable === '1'
        ? 'Demo mode is ON. Bookings, payments and refunds will not be saved, and every page now says so.'
        : 'Demo mode is OFF. The system is live — bookings are recorded again.',
]);
