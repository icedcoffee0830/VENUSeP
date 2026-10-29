<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Request;
use Throwable;

/* customer/forgot-password.php and customer/reset-password.php — customers
   only (#23). legacy/includes/password-reset.php explains the flow and its
   security; the rules are unchanged. */
class PasswordResetController
{
    /* STEP 1 — ask for the email, send a reset link. The reply is the same
       whatever happened, shown after a redirect. */
    public function forgot(Request $request)
    {
        legacy('auth', 'db', 'password-reset');

        $fpError = '';
        $fpEmail = '';
        if ($request->isMethod('post')) {
            $raw = $request->request->get('email');
            $fpEmail = strtolower(trim(is_string($raw) ? $raw : ''));
            if ($fpEmail === '' || mb_strlen($fpEmail) > 190 || !filter_var($fpEmail, FILTER_VALIDATE_EMAIL)) {
                $fpError = 'Please enter a valid email address.';
            } else {
                $pdo = venusep_db();
                if ($pdo === null) {
                    $fpError = 'The database is unreachable right now. Please try again in a few minutes.';
                } else {
                    try {
                        pr_request($pdo, $fpEmail, $request->server('REMOTE_ADDR'));
                    } catch (Throwable $e) {
                        /* Logged, never shown: the reply must not differ for an address that exists. */
                        error_log('VENUSeP forgot password: ' . $e->getMessage());
                    }
                    session(['forgot_sent' => true]);
                    return redirect()->route('customer.forgot-password')->header('Cache-Control', 'no-store');
                }
            }
        }
        $fpSent = !empty(session('forgot_sent'));
        session()->forget('forgot_sent');

        return response()->view('auth.forgot-password', compact('fpError', 'fpEmail', 'fpSent'))
            ->header('Cache-Control', 'no-store');
    }

    /* STEP 2 — the link from the email. The token leaves the URL at once (moved
       into the session); it does not sign anyone in. */
    public function reset(Request $request)
    {
        legacy('auth', 'db', 'password-reset');
        $pdo = venusep_db();

        /* ---- the link itself: check it, then drop it from the URL ---- */
        if ($request->query->has('token')) {
            session()->forget('pw_reset');
            $token = $request->query('token');
            $found = $pdo !== null && is_string($token) ? pr_find($pdo, $token) : null;
            if ($found !== null) {
                session()->migrate(true);   // the old session_regenerate_id(true)
                session(['pw_reset' => ['id' => $found['id'], 'user_id' => $found['user_id']]]);
            }
            return $this->noLeak(redirect()->route('customer.reset-password'));
        }

        $state = session('pw_reset');
        $reset = ($pdo !== null && is_array($state)) ? pr_find_id($pdo, (int) $state['id'], (int) $state['user_id']) : null;

        $rpErrors = [];
        if ($reset !== null && $request->isMethod('post')) {
            $new     = is_string($request->request->get('new_password')) ? $request->request->get('new_password') : '';
            $confirm = is_string($request->request->get('confirm_password')) ? $request->request->get('confirm_password') : '';
            if (strlen($new) < 8) {
                $rpErrors['new_password'] = 'Use a password of at least 8 characters.';
            } elseif ($new !== $confirm) {
                $rpErrors['confirm_password'] = 'The two passwords do not match.';
            } elseif (!venusep_password_ready()) {
                $rpErrors['form'] = VENUSEP_PASSWORD_SETUP_ERROR;
            } else {
                try {
                    $done = pr_complete($pdo, $reset['id'], $reset['user_id'], $new);
                } catch (Throwable $e) {
                    error_log('VENUSeP reset password: ' . $e->getMessage());
                    $done = null;
                    $rpErrors['form'] = 'Something went wrong, so your password was not changed. Please try again.';
                }
                if ($done === true) {
                    session()->forget('pw_reset');
                    session(['login_notice' => 'Your password was changed. Log in with your new password.']);
                    return $this->noLeak(redirect()->route('customer.customer-login'));
                }
                if ($done === false) {
                    $reset = null;   // used in another tab, replaced by a newer link, or expired meanwhile
                }
            }
        }
        if ($reset === null) {
            session()->forget('pw_reset');
        }

        return $this->noLeak(response()->view('auth.reset-password', compact('reset', 'rpErrors')));
    }

    private function noLeak($response)
    {
        return $response->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
