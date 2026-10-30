<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDO;

/* admin/admin-login.php — the staff sign-in: password, then the two-step code
   (or setting up a phone) for admins (DB-DECISIONS #20). */
class AdminLoginController
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'two-factor', 'two-factor-views', 'passwords');

        /* Already logged in on the staff side — no reason to see the form again. */
        if (session()->has('user_id') && session()->has('account_type')
            && in_array(session('account_type'), ['admin', 'staff'], true)) {
            return redirect()->route('admin.dashboard');
        }

        $loginError = '';

        /* Sent here mid-session because the account was suspended (staff_session_heal(),
           or staff_session_refresh(), which leaves its one-time flag in the session). */
        if ($request->query->has('suspended') || session()->pull('staff_suspended_notice')) {
            $loginError = 'Your account has been suspended. Contact an administrator if you think this is a mistake.';
        }
        /* Sent here because the session had not passed two-step verification: by
           staff_session_heal() (?tfa=required) or by the EnforceTwoFactor middleware,
           which leaves this one-time notice in the session it just emptied. */
        if ($request->query('tfa') === 'required' || !empty(session('tfa_required_notice'))) {
            session()->forget('tfa_required_notice');
            $loginError = 'Admin accounts now need two-step verification. Sign in to set it up. It takes about a minute.';
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            // Do not expose database credentials/errors to users.
            $loginError = 'Unable to connect to the database. Please contact the system administrator.';
        }

        $self = route('admin.admin-login');

        /* A two-step sign-in step (DB-DECISIONS #20); the flow itself is tfa_login_step(). */
        $tfaError = '';
        if ($request->isMethod('post') && $request->request->has('tfa_step')) {
            if ($pdo !== null) {   // otherwise $loginError already says the database is down
                [$stepError, $tfaError] = tfa_login_step($pdo, 'admin', $self,
                    function (array $pending) use ($pdo) {
                        $this->finishLogin($pdo, (int) $pending['user_id'], (string) ($pending['department'] ?? ''), true);
                    });
                $loginError = $stepError !== '' ? $stepError : $loginError;
            }
        } elseif ($request->isMethod('post')) {
            $email = trim((string) $request->request->get('email', ''));
            $password = (string) $request->request->get('password', '');
            $selectedDepartment = (string) $request->request->get('department', '');

            if ($loginError === '') {
                if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $loginError = 'Please enter a valid email address.';
                } elseif ($password === '') {
                    $loginError = 'Please enter your password.';
                } else {
                    /* There is NO department column in users: the department
                       selection is a UI field only; authorization is account_type. */
                    $stmt = $pdo->prepare(
                        'SELECT id, email, username, password_hash, account_type, is_active
                         FROM users
                         WHERE email = :email
                         LIMIT 1'
                    );
                    $stmt->execute(['email' => $email]);
                    $user = $stmt->fetch();

                    if (
                        !$user ||
                        !(bool) $user['is_active'] ||
                        !in_array($user['account_type'], ['admin', 'staff'], true) ||
                        /* (string): password_hash is nullable (Google-only customers, #23) */
                        !password_verify($password, (string) $user['password_hash'])
                    ) {
                        $loginError = 'Invalid email or password.';
                    } else {
                        // Re-hash an old bcrypt (or older-settings) hash to Argon2id (DB-DECISIONS #21).
                        venusep_password_upgrade($pdo, (int) $user['id'], $password, $user['password_hash']);

                        /* The password alone never signs an admin in (DB-DECISIONS #20): next is
                           the code, or setting up a phone if this account has none yet. */
                        tfa_after_password($pdo, (int) $user['id'], 'admin', $user['account_type'], $user['email'],
                            ['department' => $selectedDepartment], $self,
                            function () use ($pdo, $user, $selectedDepartment) {
                                $this->finishLogin($pdo, (int) $user['id'], $selectedDepartment, false);   // no code on this path
                            });
                    }
                }
            }
        }

        /* Password correct, code pending: this state never sets user_id, so
           admin_require_login() still treats the visitor as logged out. */
        $pending = $pdo !== null ? tfa_pending('admin') : null;
        $tfaView = $pending === null ? 'password' : (string) $pending['mode'];   // password | verify | enroll | codes
        $tfaCsrf = $tfaView !== 'password' ? csrf_token() : '';
        $tfaLock = $tfaView === 'verify' ? tfa_lock_seconds($pdo, (int) $pending['user_id']) : 0;   // resume a running lock's countdown
        $tfaSecret = $tfaView === 'enroll' ? tfa_setup_secret((int) $pending['user_id']) : '';     // the same key on every visit until confirmed

        $response = response()->view('auth.admin-login', compact(
            'loginError', 'tfaView', 'tfaCsrf', 'tfaLock', 'tfaSecret', 'tfaError', 'pending'
        ));
        if ($tfaView !== 'password') {
            $response->header('Cache-Control', 'no-store');   // the secret and the recovery codes must never be cached
        }

        return $response;
    }

    /* The last step of every staff sign-in, and the only place a staff session is
       created: after the code, never before it. Re-reads the account because the
       password step may have been minutes ago and it could have been suspended since.
       Never returns (throws the redirect), like the old al_finish_login(). */
    private function finishLogin(PDO $pdo, int $userId, string $department, bool $passedCode): void
    {
        $stmt = $pdo->prepare('SELECT id, email, username, account_type, is_active FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user || !(bool) $user['is_active'] || !in_array($user['account_type'], ['admin', 'staff'], true)) {
            tfa_pending_clear();
            throw new HttpResponseException(redirect()->route('admin.admin-login'));
        }

        // Record the successful login using the schema's last_login_at.
        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => $userId]);

        // Start from an empty session (nothing from a previous login survives) with a new id.
        session()->flush();
        session()->regenerate(true);
        session([
            'user_id' => (int) $user['id'],
            'user_email' => $user['email'],
            'username' => $user['username'],
            'account_type' => $user['account_type'],
            'department' => $department,
            /* Only a sign-in that went through the code sets this; EnforceTwoFactor
               ends any admin session without it. Never set on the password-only path. */
            'tfa_passed' => $passedCode,
        ]);

        throw new HttpResponseException(redirect()->route('admin.dashboard'));
    }
}
