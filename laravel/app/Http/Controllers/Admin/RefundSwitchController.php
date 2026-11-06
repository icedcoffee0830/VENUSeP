<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use App\Queries\AccountQueries;
use App\Queries\OfficeSettingsQueries;
use Illuminate\Http\Request;
use Throwable;

/* =====================================================================
   REFUND SWITCH (was admin/refund-switch.php) — the ONLY way an office's
   venues.refunds_enabled changes (one switch per office, DB-DECISIONS 28).
   Called by the "Refund Requests" card on Payment Settings.

   POST  _token  venue_id=<office>  enable=0|1  password=<the admin's password>
   Replies with JSON.

   EVERY CHECK HAPPENS HERE, ON THE SERVER (agreed 2026-09-16). In order:
     1. POST only, logged-in session, CSRF token matches
     2. the account is re-read from the database: still active, still the SUPER ADMIN
        (anyone else is refused even if they forge the request)
     3. the account is not currently locked out
     4. the password is correct
     5. only then: change the office's value + write venue_settings_history
        (field 'refunds': Office Settings history and Activity show it)

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
        if (!admin_is_super()) {   // the super admin only (DB-DECISIONS 28)
            return $this->reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the super admin can change the refund setting.']);
        }
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        }

        $enable = $request->input('enable');
        $venueId = $request->input('venue_id');
        if (!is_string($enable) || !in_array($enable, ['0', '1'], true) || !is_string($venueId) || !ctype_digit($venueId)) {
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

            /* FOR UPDATE: two wrong attempts sent at the same moment must both count. The
               transaction stays plain PDO: reauth_verify closes it itself on a wrong password. */
            $user = AccountQueries::lockForPasswordCheck((int) session('user_id'));

            if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin' || !(bool) $user['is_super_admin']) {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'error' => 'not_admin', 'message' => 'Only the super admin can change the refund setting.']);
            }

            if ($no = reauth_verify($pdo, $user, $password)) {   // the shared lockout (auth.php)
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $no['locked'], 'message' => 'Too many wrong passwords.']
                        + ($no['justLocked'] ? ['justLocked' => true] : []))
                    : $this->reply(401, ['ok' => false, 'error' => 'wrong_password', 'attemptsLeft' => $no['attemptsLeft'], 'message' => 'Wrong password.']);
            }

            $old = OfficeSettingsQueries::lockRefundSwitch((int) $venueId);
            if ($old === null) {
                $pdo->rollBack();
                return $this->reply(404, ['ok' => false, 'error' => 'no_office', 'message' => 'That office no longer exists.']);
            }

            $changed = ((string) $old !== $enable);
            if ($changed) {
                OfficeSettingsQueries::setRefundSwitch((int) $venueId, (int) $enable);
                OfficeSettingsQueries::addHistory((int) $venueId, 'refunds', (string) $old, $enable, $enable === '1'
                    ? 'Refund requests turned ON (Payment Settings, password confirmed).'
                    : 'Refund requests turned OFF (Payment Settings, password confirmed).', (int) $user['id']);
            }

            $pdo->commit();
            return $this->reply(200, ['ok' => true, 'enabled' => $enable === '1', 'changed' => $changed]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sql_failed($e);
            return $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the setting was not changed.']);
        }
    }
}
