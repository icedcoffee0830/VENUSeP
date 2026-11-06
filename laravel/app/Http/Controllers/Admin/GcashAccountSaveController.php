<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use App\Queries\AccountQueries;
use App\Queries\OfficeSettingsQueries;
use App\Queries\VenueQueries;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   GCASH ACCOUNT SAVE (was admin/gcash-account-save.php) — the ONLY way a
   venue's payout account changes.

   POST  _token, venue, account_name, mobile_number, password, note
   Replies with JSON.

   This number is where customers' money goes (payment screen + receipt
   checker both read it), so it re-asks for the password, using the same
   users.reauth_* lockout the refund switch uses. The office's NORMAL ADMIN only
   (DB-DECISIONS 28: not staff, not the super admin), re-checked here.

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
        if (!session()->has('user_id') || !admin_is_admin() || admin_is_super()) {
            return $this->reply(403, ['ok' => false, 'error' => 'not_admin',
                'message' => "Only the office's admin can change where it is paid."]);
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
               attempts at once both count against the lockout. The transaction stays plain
               PDO: reauth_verify closes it itself on a wrong password. */
            $user = AccountQueries::lockForPasswordCheck((int) session('user_id'));
            if (!$user || !(bool) $user['is_active'] || $user['account_type'] !== 'admin') {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can change where a venue is paid.']);
            }
            if ($no = reauth_verify($pdo, $user, $password)) {   // one shared "sensitive admin action" budget (auth.php)
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $no['locked'], 'message' => 'Too many wrong passwords.'])
                    : $this->reply(401, ['ok' => false, 'error' => 'wrong_password', 'attemptsLeft' => $no['attemptsLeft'], 'message' => 'Wrong password.']);
            }

            $venueId = VenueQueries::findByName($venueName)['id'] ?? null;
            if ($venueId === null) {
                $pdo->rollBack();
                return $this->reply(404, ['ok' => false, 'message' => 'Unknown venue.']);
            }
            if (!staff_may_handle_venue($venueId)) {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'error' => 'not_admin',
                    'message' => "Only the office's admin can change where it is paid."]);
            }

            /* Nothing to do if neither field moved — avoids filling the history with
               rows that record no change. */
            $current = OfficeSettingsQueries::findCurrentGcash((int) $venueId);
            if ($current && $current['account_name'] === $acctName && $current['mobile_number'] === $digits) {
                $pdo->rollBack();
                return $this->reply(400, ['ok' => false, 'message' => 'That is already this venue\'s account.']);
            }

            /* Supersede, then insert — one transaction, so the venue is never left
               with no account or two. */
            OfficeSettingsQueries::replaceGcash($current, (int) $venueId, $acctName, $digits,
                $noteSent ? ($note !== '' ? mb_substr($note, 0, 500) : null) : ($current['note'] ?? null),
                (int) session('user_id'));

            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            sql_failed($e);
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
