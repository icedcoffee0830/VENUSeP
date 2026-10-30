<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use Illuminate\Http\Request;
use Throwable;

/* =====================================================================
   REFUND SWITCH (was admin/refund-switch.php) — the ONLY way
   system_settings.refunds_enabled changes. Called by the "Refund Requests"
   card on Payment Settings.

   POST  _token  enable=0|1  password=<the admin's password>
   Replies with JSON.

   EVERY CHECK HAPPENS HERE, ON THE SERVER (agreed 2026-09-16). In order:
     1. POST only, logged-in session, CSRF token matches
     2. the account is re-read from the database: still active, still ADMIN
        (a staff account is refused even if it forges the request)
     3. the account is not currently locked out
     4. the password is correct
     5. only then: change the value + write system_settings_history

   PASSWORD LOCKOUT — reauth_verify() in legacy auth.php.
   ===================================================================== */
class RefundSwitchController
{
    use JsonEndpoint;

    public function __invoke(Request $request)
    {
        legacy('auth');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }

        $sessionType = session('account_type');
        if (!session()->has('user_id') || !in_array($sessionType, ['admin', 'staff'], true)) {
            return $this->reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        }
        if ($sessionType !== 'admin') {
            return $this->reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the administrator can change the refund setting.']);
        }
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        }

        $enable = $request->input('enable');
        if (!is_string($enable) || !in_array($enable, ['0', '1'], true)) {
            return $this->reply(400, ['ok' => false, 'error' => 'bad_value', 'message' => 'Invalid request.']);
        }
        $password = $request->input('password', '');
        if (!is_string($password) || $password === '') {
            // Not counted as a wrong attempt: nothing was tried.
            return $this->reply(400, ['ok' => false, 'error' => 'no_password', 'message' => 'Enter your admin password to confirm.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the setting was not changed.']);
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
            $stmt->execute([':id' => (int) session('user_id')]);
            $user = $stmt->fetch();

            if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin') {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the administrator can change the refund setting.']);
            }

            if ($no = reauth_verify($pdo, $user, $password)) {   // the shared lockout (auth.php)
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $no['locked'], 'message' => 'Too many wrong passwords.']
                        + ($no['justLocked'] ? ['justLocked' => true] : []))
                    : $this->reply(401, ['ok' => false, 'error' => 'wrong_password', 'attemptsLeft' => $no['attemptsLeft'], 'message' => 'Wrong password.']);
            }

            $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'refunds_enabled' FOR UPDATE");
            $cur->execute();
            $old = $cur->fetchColumn();
            if ($old === false) {
                $pdo->rollBack();
                return $this->reply(500, ['ok' => false, 'error' => 'missing_setting',
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
            return $this->reply(200, ['ok' => true, 'enabled' => $enable === '1', 'changed' => $changed]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the setting was not changed.']);
        }
    }
}
