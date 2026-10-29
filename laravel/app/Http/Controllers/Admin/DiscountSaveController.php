<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use Illuminate\Http\Request;
use Throwable;

/* =====================================================================
   DISCOUNT SAVE (was admin/discount-save.php) — the ONLY way
   system_settings.discount_percent changes. Called by the "Change rate"
   card on Venue Management.

   POST  _token  percent=0..100  reason=<why>
   Replies with JSON.

   A SETTINGS CHANGE IS AN EVENT, NOT AN OVERWRITE: every change writes a
   system_settings_history row (old, new, who, when, why). The rate is
   SNAPSHOTTED onto each booking when it is made (DB-DECISIONS #2), so this
   never re-prices a booking already quoted. Configuration, not a
   transaction: it writes for real even while demo mode is on.
   Admin AND staff may change it (the old guard allowed both).
   ===================================================================== */
class DiscountSaveController
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
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
        }

        $raw    = $request->has('percent') ? trim((string) $request->input('percent')) : '';
        $reason = $request->has('reason') ? trim((string) $request->input('reason')) : '';

        if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw > 100) {
            return $this->reply(400, ['ok' => false, 'error' => 'bad_percent', 'message' => 'Enter a whole number from 0 to 100.']);
        }
        if ($reason === '') {
            return $this->reply(400, ['ok' => false, 'error' => 'no_reason', 'message' => 'A reason is required — it goes into the change history.']);
        }
        if (mb_strlen($reason) > 500) {
            $reason = mb_substr($reason, 0, 500);
        }
        $percent = (int) $raw;

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the rate was not changed.']);
        }

        try {
            $pdo->beginTransaction();

            /* FOR UPDATE: two admins saving at once must not interleave, or the
               history would record an "old value" that was never current. */
            $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'discount_percent' FOR UPDATE");
            $cur->execute();
            $old = $cur->fetchColumn();
            if ($old === false) {
                $pdo->rollBack();
                return $this->reply(500, ['ok' => false, 'error' => 'missing_setting', 'message' => 'The discount setting is missing from the database.']);
            }
            if ((string) $old === (string) $percent) {
                $pdo->rollBack();
                return $this->reply(400, ['ok' => false, 'error' => 'unchanged', 'message' => 'That is already the current rate.']);
            }

            $pdo->prepare(
                "UPDATE system_settings
                    SET setting_value = :v, updated_by_user_id = :u
                  WHERE setting_key = 'discount_percent'"
            )->execute([':v' => (string) $percent, ':u' => (int) session('user_id')]);

            $pdo->prepare(
                "INSERT INTO system_settings_history (setting_key, old_value, new_value, change_note, changed_by_user_id)
                 VALUES ('discount_percent', :old, :new, :note, :u)"
            )->execute([
                ':old'  => (string) $old,
                ':new'  => (string) $percent,
                ':note' => $reason,
                ':u'    => (int) session('user_id'),
            ]);

            $pdo->commit();
            return $this->reply(200, ['ok' => true, 'percent' => $percent, 'previous' => (int) $old]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return $this->reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the rate was not changed.']);
        }
    }
}
