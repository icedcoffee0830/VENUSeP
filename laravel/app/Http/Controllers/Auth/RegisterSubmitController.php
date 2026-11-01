<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDOException;

/* register-submit.php — the ONLY way an account is created. JSON replies.
   customer: anyone (public sign-up) · staff: admins only, with a venue ·
   admin: admins only, re-entering THEIR OWN password (users.reauth_* lockout).
   See the old file's header for the full reasoning; the rules are unchanged. */
class RegisterSubmitController
{
    /* rg_reply(): the JSON reply, ending the request (thrown, like the old exit). */
    private function reply(int $status, array $data): never
    {
        throw new HttpResponseException(
            response()->json($data, $status)->header('Cache-Control', 'no-store')
        );
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'passwords');

        if (!$request->isMethod('post')) {
            throw new HttpResponseException(
                response()->json(['ok' => false, 'message' => 'Use POST.'], 405)
                    ->header('Allow', 'POST')->header('Cache-Control', 'no-store')
            );
        }
        /* The CSRF token is checked by Laravel (an expired page gets the old JSON reply, bootstrap/app.php). */

        $post = $request->request;
        $kindRaw = (string) $post->get('kind', '');
        $kind = in_array($kindRaw, ['staff', 'admin'], true) ? $kindRaw : 'customer';

        /* Creating a staff or admin account is an ADMIN action, re-checked server-side. */
        if ($kind !== 'customer' && !admin_is_admin()) {
            $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can create a staff or admin account.']);
        }

        $name    = trim((string) $post->get('full_name', ''));
        $email   = strtolower(trim((string) $post->get('email', '')));
        $phone   = trim((string) $post->get('contact_number', ''));
        $pass    = (string) $post->get('password', '');
        $confirm = (string) $post->get('confirm_password', '');
        $venueRaw = $post->get('venue_id', '');

        if ($name === '' || mb_strlen($name) > 190) {
            $this->reply(400, ['ok' => false, 'field' => 'full_name', 'message' => 'Enter your full name.']);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
            $this->reply(400, ['ok' => false, 'field' => 'email', 'message' => 'Enter a valid email address.']);
        }
        if ($pass === '' || strlen($pass) < 8) {
            $this->reply(400, ['ok' => false, 'field' => 'password', 'message' => 'Use a password of at least 8 characters.']);
        }
        if ($pass !== $confirm) {
            $this->reply(400, ['ok' => false, 'field' => 'confirm_password', 'message' => 'The two passwords do not match.']);
        }
        if (empty($post->get('terms'))) {
            $this->reply(400, ['ok' => false, 'field' => 'terms', 'message' => 'Please accept the terms to continue.']);
        }
        /* A phone is optional, but if given it must be a PH mobile (GCash refunds). */
        legacy('bookings');
        $phoneNorm = $phone === '' ? null : cb_normalise_mobile($phone);
        if ($phone !== '' && $phoneNorm === null) {
            $this->reply(400, ['ok' => false, 'field' => 'contact_number',
                'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable, so the account was not created.']);
        }

        $venueId = null;
        /* Every staff account belongs to ONE venue (decided 2026-10-27). */
        if ($kind === 'staff' && $venueRaw === '') {
            $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Choose the venue this staff member will handle.']);
        }
        if ($kind === 'staff') {
            if (!is_scalar($venueRaw) || !ctype_digit((string) $venueRaw) || (int) $venueRaw < 1) {
                $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'Select a valid venue.']);
            }
            $venueCheck = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
            $venueCheck->execute([':id' => (int) $venueRaw]);
            $venueId = $venueCheck->fetchColumn();
            if ($venueId === false) {
                $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue does not exist.']);
            }
            $venueId = (int) $venueId;
        }

        /* A NEW ADMIN needs the creating admin's own password, with the shared
           re-entry lockout: 5 wrong = a lock, each longer than the last. */
        if ($kind === 'admin') {
            $creatorPw = (string) $post->get('creator_password', '');
            if ($creatorPw === '') {
                $this->reply(400, ['ok' => false, 'field' => 'creator_password', 'error' => 'no_password',
                    'message' => 'Enter your own admin password to create another administrator.']);
            }
            try {
                $pdo->beginTransaction();
                $u = $pdo->prepare(
                    'SELECT id, password_hash, account_type, is_active, reauth_failed_attempts, reauth_lock_level,
                            CASE WHEN reauth_locked_until > NOW()
                                 THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
                       FROM users WHERE id = :id FOR UPDATE'
                );
                $u->execute([':id' => (int) session('user_id')]);
                $creator = $u->fetch();
                if (!$creator || !(bool) $creator['is_active'] || $creator['account_type'] !== 'admin') {
                    $pdo->rollBack();
                    $this->reply(403, ['ok' => false, 'message' => 'Only an administrator can create an admin account.']);
                }
                if ($no = reauth_verify($pdo, $creator, $creatorPw)) {   // the shared lockout (auth.php)
                    isset($no['locked'])
                        ? $this->reply(423, ['ok' => false, 'error' => 'locked', 'seconds' => $no['locked'],
                            'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                        : $this->reply(401, ['ok' => false, 'field' => 'creator_password', 'error' => 'wrong_password',
                            'attemptsLeft' => $no['attemptsLeft'],
                            'message' => 'That is not your password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('VENUSeP register-submit (admin re-auth): ' . $e->getMessage());
                $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
            }
        }

        /* A unique username derived from the email — the same rule Google sign-up uses. */
        legacy('accounts');
        try {
            $username = venusep_unique_username($pdo, $email);
        } catch (PDOException $e) {
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
        }

        /* Argon2id is required (DB-DECISIONS #21): refuse before touching the database. */
        if (!venusep_password_ready()) {
            $this->reply(500, ['ok' => false, 'message' => VENUSEP_PASSWORD_SETUP_ERROR]);
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare(
                'INSERT INTO users (email, username, password_hash, account_type)
                 VALUES (:e, :u, :p, :t)'
            )->execute([':e' => $email, ':u' => $username, ':p' => venusep_password_hash($pass), ':t' => $kind]);
            $userId = (int) $pdo->lastInsertId();

            if ($kind !== 'customer') {
                /* staff: their one venue; admin: none — they handle every venue */
                $pdo->prepare(
                    'INSERT INTO staff (user_id, venue_id, full_name, phone) VALUES (:u, :v, :n, :p)'
                )->execute([':u' => $userId, ':v' => $kind === 'staff' ? $venueId : null, ':n' => $name, ':p' => $phoneNorm]);
            } else {
                $pdo->prepare(
                    'INSERT INTO customers (user_id, full_name, phone) VALUES (:u, :n, :p)'
                )->execute([':u' => $userId, ':n' => $name, ':p' => $phoneNorm]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            /* 23000: normally uq_users_email; a venue deleted meanwhile is reported on the venue field. */
            if ($e->getCode() === '23000') {
                if ($kind === 'staff' && strpos($e->getMessage(), 'fk_staff_venue') !== false) {
                    $this->reply(400, ['ok' => false, 'field' => 'venue_id', 'message' => 'That venue no longer exists.']);
                }
                $this->reply(409, ['ok' => false, 'field' => 'email',
                    'message' => 'That email address is already registered. Try logging in instead.']);
            }
            error_log('VENUSeP register-submit: ' . $e->getMessage());
            $this->reply(500, ['ok' => false, 'message' => 'Something went wrong, so the account was not created.']);
        }

        /* A new staff or admin account is announced to the other admins (2026-10-28). */
        if ($kind !== 'customer') {
            legacy('notifications');
            try {
                $venueName = '';
                if ($venueId !== null) {
                    $vn = $pdo->prepare('SELECT name FROM venues WHERE id = :v');
                    $vn->execute([':v' => $venueId]);
                    $venueName = (string) $vn->fetchColumn();
                }
                notify_admins($pdo, 'account_created', (int) session('user_id'),
                    ['email' => $email, 'role' => $kind, 'venue' => $venueName]);
            } catch (PDOException $e) {
                error_log('VENUSeP register-submit (notify): ' . $e->getMessage());
            }
        }

        /* A new customer is NOT logged in automatically; an admin stays signed in as themselves. */
        $this->reply(200, [
            'ok'      => true,
            'kind'    => $kind,
            'message' => $kind === 'admin'
                ? 'Administrator account created. They set up two-step verification the first time they sign in.'
                : ($kind === 'staff' ? 'Staff account created.' : 'Account created. You can sign in now.'),
        ]);
    }
}
