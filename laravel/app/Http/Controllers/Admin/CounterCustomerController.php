<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   THE COUNTER'S "WHO IS THIS FOR" STEP — ported from admin/customer-search.php
   and admin/customer-verify.php. Called by JS (legacy counter-booker.php);
   the JSON shapes and status codes are the old ones exactly. Both answer
   "Staff only." as JSON themselves rather than redirecting.

   SEARCH IS NOT A CUSTOMER DIRECTORY, and the shape of it says so:
     · three characters minimum, so it cannot be walked with "a"
     · ten results, so a broad term returns a sample and not a database
     · the PHONE IS MASKED (0917•••0499) — enough to confirm the number the
       person at the counter just said, never enough to write it down
     · walk-ins (customers with no user account) are excluded: they can never
       be the "has an account" case.

   VERIFY: the CUSTOMER types their own password on the counter-facing
   screen; it is checked and thrown away. Password only (no 2FA). Nothing
   about the session changes except a short-lived note in the STAFF session —
   counter_proof[customer_id] = time() — spent by admin/booking-create.
   ===================================================================== */
class CounterCustomerController
{
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
        legacy('auth');
        if (!venusep_is_staff_session() || !self::staffStillActive()) {
            return $this->reply(403, ['ok' => false, 'results' => [], 'message' => 'Staff only.']);
        }

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return $this->reply(200, ['ok' => true, 'results' => []]);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'results' => [], 'message' => 'The database is unreachable.']);
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.full_name, c.phone, u.email
                   FROM customers c
                   JOIN users u ON u.id = c.user_id
                  WHERE u.is_active = 1
                    AND (c.full_name LIKE :like OR u.email LIKE :like2)
                  ORDER BY c.full_name
                  LIMIT 10"
            );
            $like = '%' . $q . '%';
            $stmt->execute([':like' => $like, ':like2' => $like]);

            $out = [];
            foreach ($stmt as $row) {
                $out[] = [
                    'id'    => (int) $row['id'],
                    'name'  => (string) $row['full_name'],
                    'email' => (string) $row['email'],
                    'phone' => self::maskPhone($row['phone']),
                ];
            }
            return $this->reply(200, ['ok' => true, 'results' => $out]);
        } catch (PDOException $e) {
            error_log('VENUSeP customer-search: ' . $e->getMessage());
            return $this->reply(500, ['ok' => false, 'results' => [], 'message' => 'Search failed.']);
        }
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
            $stmt = $pdo->prepare(
                'SELECT u.id, u.password_hash, u.is_active
                   FROM customers c JOIN users u ON u.id = c.user_id
                  WHERE c.id = :c LIMIT 1'
            );
            $stmt->execute([':c' => $customerId]);
            $user = $stmt->fetch();
        } catch (PDOException $e) {
            error_log('VENUSeP customer-verify: ' . $e->getMessage());
            return $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was confirmed.']);
        }

        if (!$user) {
            return $this->reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
        }
        if (!(bool) $user['is_active']) {
            return $this->reply(403, ['ok' => false, 'message' => 'That account is suspended and cannot be booked for.']);
        }

        /* Created through Google (#23): there is no password to type at the counter. */
        if ($user['password_hash'] === null) {
            return $this->reply(409, ['ok' => false, 'message' => 'This account signs in with Google, so it has no password to type. Ask the customer to set a password in their profile, or book them as a walk-in with no account.']);
        }

        /* A wrong password here is the customer mistyping their own — it does not
           feed the staff lockout ladder. Checked with password_verify; the reply
           says nothing about which part was wrong. */
        if (!password_verify($password, $user['password_hash'])) {
            return $this->reply(401, ['ok' => false, 'message' => 'That password did not match. Try again, or book them as a walk-in with no account.']);
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
