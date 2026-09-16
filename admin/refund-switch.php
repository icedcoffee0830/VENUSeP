<?php
/* =====================================================================
   REFUND SWITCH — the ONLY way system_settings.refunds_enabled changes.
   Called by the "Refund Requests" card on admin/payment-settings.php.

   POST  csrf=<token>  enable=0|1  password=<the admin's password>
   Replies with JSON.

   EVERY CHECK HAPPENS HERE, ON THE SERVER (agreed 2026-09-16). The page's
   buttons and warnings are for the admin's benefit; none of them is a
   security boundary. In order:
     1. POST only, logged-in session, CSRF token matches
     2. the account is re-read from the database: still active, still ADMIN
        (a staff account is refused even if it forges the request)
     3. the account is not currently locked out
     4. the password is correct
     5. only then: change the value + write system_settings_history

   PASSWORD LOCKOUT — stored on users.reauth_*, so clearing cookies or
   switching browsers does not reset it. 5 wrong in a row = one lock, and
   each lock is longer than the last: 10s, 30s, 1m, 5m, then 15m (the max).
   A correct password resets everything.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

const RS_ATTEMPTS_PER_LOCK = 5;
const RS_LOCK_SECONDS = [10, 30, 60, 300, 900];   // lock #1, #2, #3, #4, #5 and later

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rs_reply($status, array $data)
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rs_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    rs_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if ($sessionType !== 'admin') {
    rs_reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the administrator can change the refund setting.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    rs_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$enable = isset($_POST['enable']) ? $_POST['enable'] : null;
if (!is_string($enable) || !in_array($enable, ['0', '1'], true)) {
    rs_reply(400, ['ok' => false, 'error' => 'bad_value', 'message' => 'Invalid request.']);
}
$password = isset($_POST['password']) ? $_POST['password'] : '';
if (!is_string($password) || $password === '') {
    // Not counted as a wrong attempt: nothing was tried.
    rs_reply(400, ['ok' => false, 'error' => 'no_password', 'message' => 'Enter your admin password to confirm.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    rs_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the setting was not changed.']);
}

try {
    $pdo->beginTransaction();

    /* FOR UPDATE: two wrong attempts sent at the same moment must both count. */
    $stmt = $pdo->prepare(
        'SELECT id, email, password_hash, account_type, is_active,
                reauth_failed_attempts, reauth_lock_level,
                CASE WHEN reauth_locked_until > NOW()
                     THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
           FROM users WHERE id = :id FOR UPDATE'
    );
    $stmt->execute([':id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin') {
        $pdo->rollBack();
        rs_reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the administrator can change the refund setting.']);
    }

    if ((int) $user['lock_seconds'] > 0) {
        $pdo->rollBack();
        rs_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => (int) $user['lock_seconds'],
            'message' => 'Too many wrong passwords.']);
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int) $user['reauth_failed_attempts'] + 1;

        if ($attempts >= RS_ATTEMPTS_PER_LOCK) {
            $level = min((int) $user['reauth_lock_level'] + 1, 255);
            $seconds = RS_LOCK_SECONDS[min($level, count(RS_LOCK_SECONDS)) - 1];
            $upd = $pdo->prepare(
                'UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = :level,
                        reauth_locked_until = DATE_ADD(NOW(), INTERVAL :seconds SECOND)
                  WHERE id = :id'
            );
            $upd->execute([':level' => $level, ':seconds' => $seconds, ':id' => $user['id']]);
            $pdo->commit();
            rs_reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $seconds, 'justLocked' => true,
                'message' => 'Too many wrong passwords.']);
        }

        $upd = $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id');
        $upd->execute([':a' => $attempts, ':id' => $user['id']]);
        $pdo->commit();
        rs_reply(401, ['ok' => false, 'error' => 'wrong_password',
            'attemptsLeft' => RS_ATTEMPTS_PER_LOCK - $attempts,
            'message' => 'Wrong password.']);
    }

    /* Correct password — the lockout starts over from 10 seconds. */
    $pdo->prepare(
        'UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id'
    )->execute([':id' => $user['id']]);

    $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'refunds_enabled' FOR UPDATE");
    $cur->execute();
    $old = $cur->fetchColumn();
    if ($old === false) {
        $pdo->rollBack();
        rs_reply(500, ['ok' => false, 'error' => 'missing_setting',
            'message' => 'The refunds_enabled setting is missing from the database. Re-run venusep_schema.sql.']);
    }

    $changed = ((string) $old !== $enable);
    if ($changed) {
        $pdo->prepare(
            "UPDATE system_settings SET setting_value = :v, updated_by_user_id = :u WHERE setting_key = 'refunds_enabled'"
        )->execute([':v' => $enable, ':u' => $user['id']]);

        $pdo->prepare(
            "INSERT INTO system_settings_history (setting_key, old_value, new_value, change_note, changed_by_user_id)
             VALUES ('refunds_enabled', :old, :new, :note, :u)"
        )->execute([
            ':old' => $old,
            ':new' => $enable,
            ':note' => $enable === '1'
                ? 'Refund requests turned ON (Payment Settings, password confirmed).'
                : 'Refund requests turned OFF (Payment Settings, password confirmed).',
            ':u' => $user['id'],
        ]);
    }

    $pdo->commit();
    rs_reply(200, ['ok' => true, 'enabled' => $enable === '1', 'changed' => $changed]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    rs_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the setting was not changed.']);
}
