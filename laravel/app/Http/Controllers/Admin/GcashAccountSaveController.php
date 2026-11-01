<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   GCASH ACCOUNT SAVE (was admin/gcash-account-save.php) — the ONLY way a
   venue's payout account changes.

   POST  _token, venue, account_name, mobile_number, password, note
   Replies with JSON.

   This number is where customers' money goes (payment screen + receipt
   checker both read it), so it re-asks for the password, using the same
   users.reauth_* lockout the refund switch uses. ADMIN ONLY, re-checked here.

   A CHANGE IS A NEW ROW, NOT AN EDIT. The old account is superseded
   (valid_until set) and a new active one inserted; uq_gcash_one_active
   _per_venue keeps exactly one current account per venue.
   ===================================================================== */
class GcashAccountSaveController
{
    use JsonEndpoint;

    public function __invoke(Request $request)
    {
        legacy('auth');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        if (!session()->has('user_id') || !admin_is_admin()) {
            return $this->reply(403, ['ok' => false, 'error' => 'not_admin',
                'message' => 'Only an administrator can change where a venue is paid.']);
        }
        if (!$this->csrfOk($request)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $venueName = trim((string) ($request->input('venue') ?? ''));
        $acctName  = trim((string) ($request->input('account_name') ?? ''));
        $digits    = preg_replace('/\D+/', '', (string) ($request->input('mobile_number') ?? ''));
        $note      = trim((string) ($request->input('note') ?? ''));
        $noteSent  = $request->has('note');   /* Payment Settings sends no note: keep the current one */
        $password  = (string) ($request->input('password') ?? '');

        if ($venueName === '') {
            return $this->reply(400, ['ok' => false, 'message' => 'Which venue?']);
        }
        /* The account NAME matters as much as the number: GCash masks the number on a
           receipt, and the checker falls back to matching visible letter-runs of the
           name. An empty name silently weakens every verification. */
        if ($acctName === '' || mb_strlen($acctName) > 150) {
            return $this->reply(400, ['ok' => false, 'field' => 'account_name',
                'message' => 'Enter the account name — the checker matches it when the number is masked on a receipt.']);
        }
        /* 11 digits starting 09 — anything else would quietly reject every receipt. */
        if (strlen($digits) !== 11 || substr($digits, 0, 2) !== '09') {
            return $this->reply(400, ['ok' => false, 'field' => 'mobile_number',
                'message' => 'Needs 11 digits starting with 09. The checker compares digits, so any other shape rejects every receipt.']);
        }
        if ($password === '') {
            return $this->reply(400, ['ok' => false, 'error' => 'no_password',
                'message' => 'Enter your admin password to confirm this change.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
        }

        try {
            $pdo->beginTransaction();

            /* Re-read the account and re-check the password. FOR UPDATE so two
               attempts at once both count against the lockout. */
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
                return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can change where a venue is paid.']);
            }
            if ($no = reauth_verify($pdo, $user, $password)) {   // one shared "sensitive admin action" budget (auth.php)
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $no['locked'], 'message' => 'Too many wrong passwords.'])
                    : $this->reply(401, ['ok' => false, 'error' => 'wrong_password', 'attemptsLeft' => $no['attemptsLeft'], 'message' => 'Wrong password.']);
            }

            $v = $pdo->prepare('SELECT id FROM venues WHERE name = :n');
            $v->execute([':n' => $venueName]);
            $venueId = $v->fetchColumn();
            if ($venueId === false) {
                $pdo->rollBack();
                return $this->reply(404, ['ok' => false, 'message' => 'Unknown venue.']);
            }

            /* Nothing to do if neither field moved — avoids filling the history with
               rows that record no change. */
            $cur = $pdo->prepare(
                'SELECT id, account_name, mobile_number, note FROM gcash_accounts
                  WHERE venue_id = :v AND is_active = 1 AND valid_until IS NULL'
            );
            $cur->execute([':v' => $venueId]);
            $current = $cur->fetch();
            if ($current && $current['account_name'] === $acctName && $current['mobile_number'] === $digits) {
                $pdo->rollBack();
                return $this->reply(400, ['ok' => false, 'message' => 'That is already this venue\'s account.']);
            }

            /* Supersede, then insert — one transaction, so the venue is never left
               with no account or two. */
            if ($current) {
                $pdo->prepare('UPDATE gcash_accounts SET is_active = 0, valid_until = NOW() WHERE id = :id')
                    ->execute([':id' => $current['id']]);
            }
            $pdo->prepare(
                'INSERT INTO gcash_accounts (venue_id, account_name, mobile_number, note, created_by_user_id)
                 VALUES (:v, :n, :m, :o, :u)'
            )->execute([
                ':v' => $venueId, ':n' => $acctName, ':m' => $digits,
                ':o' => $noteSent ? ($note !== '' ? mb_substr($note, 0, 500) : null) : ($current['note'] ?? null),
                ':u' => (int) session('user_id'),
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP gcash-account-save: ' . $e->getMessage());
            return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
        }

        return $this->reply(200, [
            'ok'      => true,
            'venue'   => $venueName,
            'name'    => $acctName,
            'number'  => $digits,
            'previous'=> $current ? $current['mobile_number'] : null,
            'message' => 'Saved. New payments for ' . $venueName . ' go to this account, and receipts are checked against it.',
        ]);
    }
}
