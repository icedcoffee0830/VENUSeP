<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use PDOException;

/* customer/customer-login.php — password sign-in, the two-step code for
   customers who turned it on (#20), and the one-time "finish your account"
   screen for a brand-new Google identity (#23). */
class CustomerLoginController
{
    public function __invoke(Request $request)
    {
        legacy('auth', 'two-factor', 'two-factor-views', 'passwords', 'accounts', 'google-auth');

        $pdo = venusep_db();
        if ($pdo === null) {
            return response('Database connection failed.', 500);
        }

        $loginError = '';
        $self = route('customer.customer-login');

        /* Already signed in — including a device remembered by "Remember me" (#23),
           which customer_logged_in() signs back in here — straight to the booking page. */
        if ($request->isMethod('get') && customer_logged_in()) {
            return redirect()->route('customer.venues');
        }

        /* One sentence left by another page (the Google callback, mostly), shown once. */
        $loginNotice = is_string(session('login_notice')) ? session('login_notice') : '';
        session()->forget('login_notice');
        /* Bug 2: disabled while signed in (customer_session_heal() left this), or
           the right password on a disabled account (below). */
        $accountDisabled = (bool) session()->pull('customer_disabled_notice', false);

        $post = $request->request;
        $tfaError = '';
        $googleErrors = [];
        if ($request->isMethod('post') && $post->has('tfa_step')) {
            [$loginError, $tfaError] = tfa_login_step($pdo, 'customer', $self,
                function (array $pending) use ($pdo) {
                    cl_finish_login($pdo, (int) $pending['user_id'], !empty($pending['remember']));
                });
        } elseif ($request->isMethod('post') && $post->has('google_step')) {
            /* The one-time "finish your account" screen (google-callback put it in the
               session). Name, email and Google id come from the SESSION, except the name. */
            $signup = google_signup_pending();
            if ($signup === null) {
                $loginNotice = 'That sign-up timed out. Choose Continue with Google again.';
            } elseif ($post->get('google_step') === 'cancel') {
                session()->forget('google_signup');
                return redirect()->route('customer.customer-login');
            } else {
                legacy('bookings');   /* cb_normalise_mobile() — the same rule as registration */
                $gName  = trim((string) $post->get('full_name', ''));
                $gPhone = trim((string) $post->get('contact_number', ''));
                $gPhoneNorm = $gPhone === '' ? null : cb_normalise_mobile($gPhone);
                if ($gName === '' || mb_strlen($gName) > 190) {
                    $googleErrors['full_name'] = 'Enter your full name.';
                }
                if ($gPhone !== '' && $gPhoneNorm === null) {
                    $googleErrors['contact_number'] = 'Enter a mobile number in the form 09XX XXX XXXX.';
                }
                if (empty($post->get('terms'))) {
                    $googleErrors['terms'] = 'You must agree to the Terms and Conditions.';
                }
                if (!$googleErrors) {
                    try {
                        $newUserId = google_create_customer($pdo, (string) $signup['sub'], (string) $signup['email'], $gName, $gPhoneNorm);
                    } catch (PDOException $e) {
                        session()->forget('google_signup');
                        if ($e->getCode() === '23000') {
                            $loginNotice = 'This email is already registered. Log in with your email and password. You can connect Google from your profile afterwards.';
                        } else {
                            error_log('VENUSeP Google sign-up: ' . $e->getMessage());
                            $loginNotice = 'Something went wrong, so the account was not created. Please try again.';
                        }
                        $newUserId = null;
                    }
                    if ($newUserId !== null) {
                        cl_finish_login($pdo, $newUserId, !empty($signup['remember']));   // never returns
                    }
                }
            }
        } elseif ($request->isMethod('post')) {
            $email = trim((string) $post->get('email', ''));
            $password = (string) $post->get('password', '');
            $rememberMe = $post->has('remember_me');

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $loginError = 'Please enter a valid email address.';
            } elseif ($password === '') {
                $loginError = 'Please enter your password.';
            } elseif (($lock = SignInThrottle::secondsLeft($email)) > 0) {
                $loginError = SignInThrottle::message($lock);   // the password is not even checked
            } else {
                $stmt = $pdo->prepare(
                    'SELECT
                        u.id AS user_id,
                        u.email,
                        u.password_hash,
                        u.is_active,
                        c.id AS customer_id,
                        c.full_name,
                        c.phone,
                        c.address,
                        c.university_id_no
                     FROM users u
                     INNER JOIN customers c ON c.user_id = u.id
                     WHERE u.email = :email
                       AND u.account_type = :account_type
                     LIMIT 1'
                );
                $stmt->execute([':email' => $email, ':account_type' => 'customer']);
                $customer = $stmt->fetch();

                if ($customer && $customer['is_active'] && $customer['password_hash'] === null) {
                    /* Created through Google, no password set yet. */
                    $loginError = google_enabled()
                        ? 'This account uses Google sign-in. Use Continue with Google below.'
                        : 'This account uses Google sign-in, which is not set up on this server yet.';
                } elseif ($customer && !$customer['is_active'] && password_verify($password, (string) $customer['password_hash'])) {
                    /* Only AFTER the password checked out, so this reveals nothing to a stranger. */
                    $accountDisabled = true;
                } elseif (!$customer || !$customer['is_active'] || !password_verify($password, $customer['password_hash'])) {
                    // One generic message so the page does not reveal whether an email exists.
                    $lock = SignInThrottle::failed($email);   // counted for an unknown email too
                    $loginError = $lock > 0 ? SignInThrottle::message($lock) : 'Invalid email or password.';
                } else {
                    SignInThrottle::passed($email);
                    // Re-hash an old bcrypt (or older-settings) hash to Argon2id (DB-DECISIONS #21).
                    venusep_password_upgrade($pdo, (int) $customer['user_id'], $password, $customer['password_hash']);
                    /* "Remember me" rides along through the code step (it is in the pending state). */
                    tfa_after_password($pdo, (int) $customer['user_id'], 'customer', 'customer', $customer['email'],
                        ['remember' => $rememberMe], $self,
                        function () use ($pdo, $customer, $rememberMe) {
                            cl_finish_login($pdo, (int) $customer['user_id'], $rememberMe);
                        });
                }
            }
        }

        /* Password correct, code pending: not logged in yet (customer_logged_in() needs user_id). */
        $pending = tfa_pending('customer');
        $tfaView = $pending === null ? 'password' : 'verify';
        /* A brand-new Google identity waiting for its one-time "finish your account" screen. */
        $googleSignup = $tfaView === 'password' ? google_signup_pending() : null;
        if ($googleSignup !== null) {
            $tfaView = 'google_signup';
        }
        $tfaCsrf = $tfaView !== 'password' ? csrf_token() : '';
        $googleOn = google_enabled();
        $tfaLock = $tfaView === 'verify' ? tfa_lock_seconds($pdo, (int) $pending['user_id']) : 0;   // resume a running lock's countdown

        /* The "finish your account" form keeps what was typed. */
        $gsName  = $post->has('full_name') ? (string) $post->get('full_name') : (string) ($googleSignup['name'] ?? '');
        $gsPhone = $post->has('contact_number') ? (string) $post->get('contact_number') : '';
        $gsTerms = !empty($post->get('terms'));

        $response = response()->view('auth.customer-login', compact(
            'loginError', 'loginNotice', 'accountDisabled', 'tfaView', 'tfaCsrf', 'tfaError', 'tfaLock', 'pending',
            'googleSignup', 'googleErrors', 'googleOn', 'gsName', 'gsPhone', 'gsTerms'
        ));
        if ($tfaView !== 'password') {
            $response->header('Cache-Control', 'no-store');
        }

        return $response;
    }
}
