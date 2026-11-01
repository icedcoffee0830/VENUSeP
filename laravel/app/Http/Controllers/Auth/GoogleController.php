<?php

namespace App\Http\Controllers\Auth;

use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use PDOException;
use Throwable;

/* customer/google-start.php and customer/google-callback.php — sign in with
   Google (#23). legacy/includes/google-auth.php explains the flow. The Laravel
   copy has no google-config.php, so google_enabled() is false and both steps
   end on the old "not set up on this server yet" notices; the code for a
   configured server is ported as it was. */
class GoogleController
{
    private const GC_GENERIC = 'We couldn’t sign you in with Google. Please try again, or log in with your email and password.';

    /* STEP 1 — a plain GET on purpose: starting a trip changes nothing. */
    public function start(Request $request)
    {
        legacy('auth', 'google-auth');

        $purpose = in_array($request->query('purpose', ''), ['link', 'reauth'], true) ? $request->query('purpose') : 'signin';

        if ($purpose === 'signin') {
            if (customer_logged_in()) {
                return $this->noStore(redirect()->route('customer.venues'));
            }
            if (!google_enabled()) {
                session(['login_notice' => 'Sign-in with Google is not set up on this server yet. Log in with your email and password.']);
                return $this->noStore(redirect()->route('customer.customer-login'));
            }
            return $this->noStore(redirect()->away(google_begin('signin', 0, !empty($request->query('remember')))));
        }

        /* link / reauth: from the profile page, for the customer who is signed in. */
        if (!customer_logged_in()) {
            return $this->noStore(redirect()->route('customer.customer-login'));
        }
        $userId = (int) session('user_id');
        if (!google_enabled()) {
            session(['profile_notice' => 'Google sign-in is not set up on this server yet.']);
            return $this->noStore(redirect()->to(route('customer.customer-profile') . '#accountSecurityTitle'));
        }
        if ($purpose === 'link') {
            if (!google_proof_valid('google_link', $userId)) {
                session(['profile_notice' => 'Confirm your password first, then connect Google.']);
                return $this->noStore(redirect()->to(route('customer.customer-profile') . '#accountSecurityTitle'));
            }
            google_proof_clear('google_link');   // one password confirmation, one trip
        }

        return $this->noStore(redirect()->away(google_begin($purpose, $userId)));
    }

    /* STEP 2 — Google sends the browser back. Every outcome is a redirect. */
    public function callback(Request $request)
    {
        legacy('auth', 'db', 'google-auth', 'accounts', 'two-factor');

        if (!google_enabled()) {
            $this->fail('Sign-in with Google is not set up on this server yet. Log in with your email and password.');
        }

        /* Consumed before anything else, so this return can never be replayed. */
        $state = $request->query('state');
        $flow = google_take_flow(is_string($state) ? $state : '');
        if ($flow === null) {
            $this->fail('That Google sign-in expired or was already used. Please try again.', 'state missing, wrong or expired');
        }

        $googleError = $request->query('error');
        if ($googleError !== null) {
            if ($googleError === 'access_denied') {
                $this->fail('Google sign-in was cancelled.');
            }
            $this->fail(self::GC_GENERIC, 'Google returned error ' . mb_substr(is_string($googleError) ? $googleError : '?', 0, 60));
        }
        $code = $request->query('code');
        if (!is_string($code) || $code === '' || strlen($code) > 2048) {
            $this->fail(self::GC_GENERIC, 'no authorization code');
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            $this->fail('The database is unreachable right now, so you could not be signed in. Please try again in a few minutes.');
        }

        $cfg = google_config();
        try {
            $claims = google_exchange_code($code, (string) $flow['verifier'], $cfg);
        } catch (Throwable $e) {
            $this->fail(self::GC_GENERIC, $e->getMessage());
        }
        $problem = google_check_claims($claims, (string) $cfg['client_id'], (string) $flow['nonce'], time());
        if ($problem !== null) {
            $this->fail(self::GC_GENERIC, 'ID token refused: ' . $problem);
        }

        $sub   = (string) $claims['sub'];
        $email = strtolower(trim((string) $claims['email']));
        $name  = trim((string) ($claims['name'] ?? ''));

        /* ---- link / reauth: a signed-in customer, back from the profile page ---- */
        $purpose = (string) ($flow['purpose'] ?? 'signin');
        if ($purpose !== 'signin') {
            if (!customer_logged_in() || (int) session('user_id') !== (int) ($flow['user_id'] ?? 0)) {
                $this->fail(self::GC_GENERIC, $purpose . ' finished by a different session');
            }
            $userId = (int) session('user_id');
            try {
                if ($purpose === 'link') {
                    $problem = google_link($pdo, $userId, $sub);
                    session(['profile_notice' => $problem ?? 'Google account connected. You can now sign in with Google too.']);
                } else {
                    $stmt = $pdo->prepare('SELECT google_sub FROM users WHERE id = :u');
                    $stmt->execute([':u' => $userId]);
                    $linked = $stmt->fetchColumn();
                    if (!is_string($linked) || !hash_equals($linked, $sub)) {
                        session(['profile_notice' => 'That was a different Google account from the one connected to VENUSeP. Choose the connected one.']);
                    } else {
                        google_proof_set('google_reauth', $userId);
                        session(['profile_notice' => 'Google confirmed it’s you. Choose your password below.']);
                    }
                }
            } catch (PDOException $e) {
                $this->fail(self::GC_GENERIC, $purpose . ' failed: ' . $e->getMessage());
            }
            return $this->headers(redirect()->to(route('customer.customer-profile') . '#accountSecurityTitle'));
        }

        try {
            $who = google_resolve($pdo, $sub, $email);
        } catch (PDOException $e) {
            $this->fail(self::GC_GENERIC, 'resolve failed: ' . $e->getMessage());
        }

        switch ($who['action']) {
            case 'signin':
                /* Same hand-off as a correct password: the code if they turned it on. */
                $remember = !empty($flow['remember']);
                tfa_after_password($pdo, (int) $who['user_id'], 'customer', 'customer', (string) $who['email'],
                    ['remember' => $remember], route('customer.customer-login'),
                    function () use ($pdo, $who, $remember) {
                        cl_finish_login($pdo, (int) $who['user_id'], $remember);
                    });
                break;

            case 'taken':
                $this->fail('This email is already registered. Log in with your email and password. You can connect Google from your profile afterwards.');
                break;

            case 'new':
                /* Not an account yet: the login page shows the one-time "finish your account" screen. */
                session()->migrate(true);
                session(['google_signup' => ['sub' => $sub, 'email' => $email, 'name' => mb_substr($name, 0, 190), 'at' => time(),
                                             'remember' => !empty($flow['remember'])]]);
                return $this->headers(redirect()->route('customer.customer-login'));

            default:
                $this->fail(self::GC_GENERIC, 'refused: staff/admin, suspended, or no customer profile');
        }
    }

    /* gc_fail(): a signed-in customer goes back to the profile, anyone else to the
       login page, with one plain sentence; the technical reason to the log only. */
    private function fail(string $message, string $log = ''): never
    {
        if ($log !== '') {
            error_log('VENUSeP Google sign-in: ' . $log);
        }
        if (customer_logged_in()) {
            session(['profile_notice' => $message === self::GC_GENERIC
                ? 'Google could not confirm your account, so nothing was changed. Please try again.'
                : $message]);
            $to = redirect()->to(route('customer.customer-profile') . '#accountSecurityTitle');
        } else {
            session(['login_notice' => $message]);
            $to = redirect()->route('customer.customer-login');
        }
        throw new HttpResponseException($this->headers($to));
    }

    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store');
    }

    /* The callback URL carries the one-time code: never cached, never sent on. */
    private function headers($response)
    {
        return $response->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
