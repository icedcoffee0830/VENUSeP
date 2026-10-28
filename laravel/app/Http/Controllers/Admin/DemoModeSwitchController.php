<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   DEMO MODE SWITCH (was admin/demo-mode-switch.php) — the ONLY way
   system_settings.demo_mode changes. Called by the Demo Mode card on the
   admin profile page.

   POST  _token, enable=0|1, password
   Replies with JSON.

   Turning demo mode ON in production is the most damaging single action in
   the system (customers book and nothing is recorded), so it is admin only,
   re-checked server-side, password re-entered (the shared users.reauth_*
   lockout), and every change written to system_settings_history.

   TURNING IT OFF (or on) CLEARS THE OVERLAY: whatever this browser pretended
   to book is dropped (demo_reset()).
   ===================================================================== */
class DemoModeSwitchController
{
    use JsonEndpoint;

    public function __invoke(Request $request)
    {
        legacy('auth', 'demo-mode');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        if (!session()->has('user_id') || !admin_is_admin()) {
            return $this->reply(403, ['ok' => false, 'error' => 'not_admin',
                'message' => 'Only an administrator can change demo mode.']);
        }
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $enable = $request->has('enable') && in_array((string) $request->input('enable'), ['0', '1'], true)
            ? (string) $request->input('enable') : null;
        if ($enable === null) {
            return $this->reply(400, ['ok' => false, 'message' => 'Invalid request.']);
        }
        $password = (string) ($request->input('password') ?? '');
        if ($password === '') {
            return $this->reply(400, ['ok' => false, 'error' => 'no_password',
                'message' => 'Enter your admin password to confirm.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
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
            $u->execute([':id' => (int) session('user_id')]);
            $user = $u->fetch();
            if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin') {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can change demo mode.']);
            }
            if ((int) $user['lock_seconds'] > 0) {
                $pdo->rollBack();
                return $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => (int) $user['lock_seconds'],
                    'message' => 'Too many wrong passwords.']);
            }
            if (!password_verify($password, (string) $user['password_hash'])) {
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
                    return $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $seconds,
                        'message' => 'Too many wrong passwords.']);
                }
                $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')
                    ->execute([':a' => $attempts, ':id' => $user['id']]);
                $pdo->commit();
                return $this->reply(401, ['ok' => false, 'error' => 'wrong_password',
                    'attemptsLeft' => 5 - $attempts, 'message' => 'Wrong password.']);
            }
            $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')
                ->execute([':id' => $user['id']]);

            $cur = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'demo_mode'")->fetchColumn();
            if ((string) $cur === $enable) {
                $pdo->rollBack();
                return $this->reply(400, ['ok' => false, 'message' => 'Demo mode is already ' . ($enable === '1' ? 'on' : 'off') . '.']);
            }

            $pdo->prepare("UPDATE system_settings SET setting_value = :v, updated_by_user_id = :u WHERE setting_key = 'demo_mode'")
                ->execute([':v' => $enable, ':u' => (int) session('user_id')]);
            $pdo->prepare(
                "INSERT INTO system_settings_history (setting_key, old_value, new_value, change_note, changed_by_user_id)
                 VALUES ('demo_mode', :o, :n, :note, :u)"
            )->execute([
                ':o' => (string) $cur, ':n' => $enable,
                ':note' => $enable === '1'
                    ? 'Demo mode ON — bookings, payments and refunds stop being written.'
                    : 'Demo mode OFF — the system is live again.',
                ':u' => (int) session('user_id'),
            ]);
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP demo-mode-switch: ' . $e->getMessage());
            return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }

        /* Drop whatever this browser pretended to book. */
        demo_reset();

        return $this->reply(200, [
            'ok' => true, 'enabled' => $enable === '1',
            'message' => $enable === '1'
                ? 'Demo mode is ON. Bookings, payments and refunds will not be saved, and every page now says so.'
                : 'Demo mode is OFF. The system is live — bookings are recorded again.',
        ]);
    }
}
