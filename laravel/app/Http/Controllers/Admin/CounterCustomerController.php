<?php

namespace App\Http\Controllers\Admin;

use App\Queries\CustomerQueries;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PDOException;

/* =====================================================================
   THE COUNTER'S "WHO IS THIS FOR" STEP — ported from admin/customer-search.php
   and admin/customer-verify.php. Called by JS (legacy counter-booker.php);
   the JSON shapes and status codes are the old ones exactly. Both answer
   "Staff only." as JSON themselves rather than redirecting.

   SEARCH FINDS ANY CUSTOMER ACCOUNT, of every office, so nobody gets a second
   one (DB-DECISIONS 28). It is still not a customer directory:
     · exact email, exact mobile, or a name of three letters or more
     · ten results, so a broad name returns a sample and not a database
     · EMAIL AND PHONE ARE MASKED (ju•••@gmail.com, 0917•••0499): enough to
       confirm what the person at the counter just said, never enough to copy
     · name, masked email and phone, USeP address or not: nothing about any
       office's bookings, payments or History
     · walk-ins (customers with no user account) are excluded: they can never
       be the "has an account" case. Searches are not logged.

   CREATE: an account made at the counter (customers.counter_venue_id = this
   office). An email any account has is refused; a mobile another account uses
   is only a warning. check=1 answers without saving (the confirm step).

   VERIFY: the CUSTOMER types their own password on the counter-facing
   screen; it is checked and thrown away. Password only (no 2FA). Nothing
   about the session changes except a short-lived note in the STAFF session —
   counter_proof[customer_id] = time() — spent by admin/booking-create.
   An account with no password (Google, or made at the counter and not set up)
   gets 409 noPassword: staff tick "ID checked" instead (BookingCreateController).
   ===================================================================== */
class CounterCustomerController
{
    public const ALREADY_HAS_ACCOUNT = 'This person already has an account. Search for them instead.';
    public const NO_PASSWORD = 'This account has no password (it signs in with Google, or was made at the counter and not set up yet). Look at a valid ID and tick “ID checked” instead.';

    private function reply(int $status, array $data)
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }

    /* These JSON routes have no `staff` middleware, so nothing else re-reads the
       account: the SESSION says staff, the DATABASE decides. staff_session_heal()
       ends a suspended (or 2FA-reset) account's session by throwing its redirect;
       here that is a "no". Also used by CustomerController::save(). */
    public static function staffStillActive(): bool
    {
        try {
            staff_session_heal();
        } catch (HttpResponseException $e) {
            return false;
        }
        return venusep_is_staff_session();
    }

    public function search(Request $request)
    {
        legacy('auth', 'bookings');
        if (!venusep_is_staff_session() || !self::staffStillActive()) {
            return $this->reply(403, ['ok' => false, 'results' => [], 'message' => 'Staff only.']);
        }

        /* Exact email, exact mobile, or a name of three letters or more; anything else finds nothing. */
        $q = trim((string) $request->query('q', ''));
        $mobile = preg_match('/\pL/u', $q) ? null : cb_normalise_mobile($q);
        if (str_contains($q, '@')) {
            $by = ['email', mb_strtolower($q)];
        } elseif ($mobile !== null) {
            $by = ['mobile', $mobile];
        } elseif (preg_match_all('/\pL/u', $q) >= 3) {
            /* what was typed is matched literally: "%%%" or "___" would match every customer */
            $by = ['name', addcslashes($q, '%_\\')];
        } else {
            return $this->reply(200, ['ok' => true, 'results' => []]);
        }

        try {
            $out = [];
            foreach (CustomerQueries::search(...$by) as $row) {
                $out[] = [
                    'id'    => (int) $row->id,
                    'name'  => (string) $row->full_name,
                    'email' => self::maskEmail((string) $row->email),
                    'phone' => self::maskPhone($row->phone),
                    'usep'  => str_ends_with(strtolower((string) $row->email), '@usep.edu.ph'),
                    'noPassword' => (bool) $row->no_password,   /* confirmed by "ID checked", not a password */
                ];
            }
            return $this->reply(200, ['ok' => true, 'results' => $out]);
        } catch (QueryException $e) {
            sql_failed($e);
            return $this->reply(500, ['ok' => false, 'results' => [], 'message' => 'Search failed.']);
        }
    }

    /* ju•••@gmail.com: enough to confirm the address the person just said. */
    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, mb_strlen($local) > 2 ? 2 : 1) . '•••@' . $domain;
    }

    /* Make an account at this office's counter: check, then save. */
    public function create(Request $request)
    {
        legacy('auth', 'bookings', 'offices', 'accounts', 'receipt-emails');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'POST');
        }
        if (!venusep_is_staff_session() || !self::staffStillActive()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Staff only.']);
        }
        $token = $request->input('csrf', $request->input('_token', $request->header('X-CSRF-TOKEN')));
        if (!csrf_valid($token)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        // Check
        $office = office_of_room($request->input('room'));
        $name   = trim((string) $request->input('full_name', ''));
        $email  = mb_strtolower(trim((string) $request->input('email', '')));
        $mobile = cb_normalise_mobile($request->input('phone', ''));
        if ($office === null || !staff_may_handle_venue($office['id'])) {
            return $this->reply(403, ['ok' => false, 'message' => 'This room belongs to another office. Only that office\'s counter can make the account here.']);
        }
        if ($name === '' || mb_strlen($name) > 190) {
            return $this->reply(400, ['ok' => false, 'field' => 'name', 'message' => 'Enter the customer’s full name.']);
        }
        if (mb_strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->reply(400, ['ok' => false, 'field' => 'email', 'message' => 'That email address doesn’t look right. Check it with the customer.']);
        }
        if ($mobile === null) {
            return $this->reply(400, ['ok' => false, 'field' => 'phone', 'message' => 'Enter an 11-digit mobile number starting 09.']);
        }
        if (CustomerQueries::emailTaken($email)) {
            return $this->reply(409, ['ok' => false, 'field' => 'email', 'error' => 'email_taken', 'message' => self::ALREADY_HAS_ACCOUNT]);
        }
        $warning = CustomerQueries::mobileUsed($mobile)
            ? 'Another account already uses this mobile number. Ask whether they already have an account before you make a new one.' : '';
        if ($request->input('check') === '1') {
            return $this->reply(200, ['ok' => true, 'email' => $email, 'warning' => $warning]);
        }

        // Save
        $pdo = venusep_db_or_fail();
        try {
            [$customerId, $outboxId] = DB::transaction(fn () => [
                CustomerQueries::createAtCounter($email, venusep_unique_username($pdo, $email), $name, $mobile, $office['id']),
                queue_counter_welcome($pdo, $name, $email, $office, (int) session('user_id')),
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[1] ?? null) !== 1062) {
                throw $e;
            }
            /* the same email registered a moment ago: uq_users_email */
            return $this->reply(409, ['ok' => false, 'field' => 'email', 'error' => 'email_taken', 'message' => self::ALREADY_HAS_ACCOUNT]);
        }
        mail_send($pdo, $outboxId);   // never throws

        return $this->reply(200, ['ok' => true, 'customerId' => $customerId, 'name' => $name,
            'phone' => self::maskPhone($mobile), 'warning' => $warning,
            'message' => 'Account made. A welcome email went to ' . $email . '.']);
    }

    /* Show enough of a number to confirm it, never enough to write it down. */
    private static function maskPhone($raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return '—';
        }
        if (strlen($d) <= 4) {
            return str_repeat('•', strlen($d));
        }
        return substr($d, 0, 4) . str_repeat('•', max(0, strlen($d) - 8)) . substr($d, -4);
    }

    /* What the staff member reads out: why, and how long to wait. */
    private static function lockedMessage(int $seconds): string
    {
        $wait = $seconds < 60 ? $seconds . ' second' . ($seconds === 1 ? '' : 's')
                              : ($m = (int) ceil($seconds / 60)) . ' minute' . ($m === 1 ? '' : 's');
        return 'Too many wrong passwords for this account, so it is locked for ' . $wait
             . '. Ask the customer to try again after that, or book them as a walk-in with no account.';
    }

    /* The route skips Laravel's CSRF middleware so an expired page still gets
       the old 400 JSON the counter JS shows; the token is checked here instead
       (old field `csrf`, Laravel's `_token`, or the X-CSRF-TOKEN header). */
    public function verify(Request $request)
    {
        legacy('auth');
        if (!$request->isMethod('post')) {
            return $this->reply(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'POST');
        }
        if (!venusep_is_staff_session() || !self::staffStillActive()) {
            return $this->reply(403, ['ok' => false, 'message' => 'Staff only.']);
        }
        $token = $request->input('csrf', $request->input('_token', $request->header('X-CSRF-TOKEN')));
        if (!csrf_valid($token)) {
            return $this->reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }

        $customerId = (int) $request->input('customer_id', 0);
        $password   = (string) $request->input('password', '');
        if ($customerId <= 0 || $password === '') {
            return $this->reply(400, ['ok' => false, 'message' => 'Pick the account and ask the customer to type their password.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was confirmed.']);
        }

        try {
            /* FOR UPDATE: two wrong passwords sent at the same moment must both count. */
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                'SELECT u.id, u.password_hash, u.is_active, u.reauth_failed_attempts, u.reauth_lock_level,
                        CASE WHEN u.reauth_locked_until > NOW()
                             THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until) ELSE 0 END AS lock_seconds
                   FROM customers c JOIN users u ON u.id = c.user_id
                  WHERE c.id = :c LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([':c' => $customerId]);
            $user = $stmt->fetch();

            if (!$user) {
                $pdo->rollBack();
                return $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
            }
            if (!(bool) $user['is_active']) {
                $pdo->rollBack();
                return $this->reply(403, ['ok' => false, 'message' => 'That account is suspended and cannot be booked for.']);
            }

            /* Created through Google (#23), or at the counter and not set up yet: there is no
               password to type. Staff tick "ID checked" instead (BookingCreateController). */
            if ($user['password_hash'] === null) {
                $pdo->rollBack();
                return $this->reply(409, ['ok' => false, 'noPassword' => true, 'message' => self::NO_PASSWORD]);
            }

            /* ATTEMPT LIMIT — the CUSTOMER's own lockout (reauth_verify, auth.php),
               so a counter screen cannot be used to guess someone's password.
               Stored on the account, not the staff session: another staff member
               or another browser does not start over.
               The reply says nothing about which part was wrong. */
            if ($no = reauth_verify($pdo, $user, $password)) {
                return isset($no['locked'])
                    ? $this->reply(423, ['ok' => false, 'seconds' => $no['locked'], 'message' => self::lockedMessage($no['locked'])])
                    : $this->reply(401, ['ok' => false, 'attemptsLeft' => $no['attemptsLeft'],
                        'message' => 'That password did not match. Try again, or book them as a walk-in with no account.']);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            sql_failed($e);
            return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was confirmed.']);
        }

        $proof = session('counter_proof');
        if (!is_array($proof)) {
            $proof = [];
        }
        $proof[$customerId] = time();   // TTL (900 s) is enforced by admin/booking-create
        session(['counter_proof' => $proof]);

        return $this->reply(200, ['ok' => true, 'message' => 'Identity confirmed.']);
    }
}
