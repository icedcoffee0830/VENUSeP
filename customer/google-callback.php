<?php
declare(strict_types=1);
/* =====================================================================
   GOOGLE SIGN-IN, STEP 2 — Google sends the browser back here.
   includes/google-auth.php explains the flow and its security.

   Never shows a page of its own: every outcome is a redirect, and every
   failure lands on the login page (or, for a signed-in customer connecting
   or confirming Google, the profile) with one plain sentence. The technical
   reason goes to the error log only — it can name the client, never the
   secret.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/google-auth.php';
require_once __DIR__ . '/../includes/accounts.php';
require_once __DIR__ . '/../includes/two-factor.php';

venusep_session_start();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');   // the URL carries the one-time code

const GC_GENERIC = 'We couldn’t sign you in with Google. Please try again, or log in with your email and password.';

/* A signed-in customer (connecting Google, or confirming it from the profile)
   goes back to the profile; anyone else to the login page. */
function gc_fail(string $message, string $log = ''): void
{
    if ($log !== '') {
        error_log('VENUSeP Google sign-in: ' . $log);
    }
    if (customer_logged_in()) {
        $_SESSION['profile_notice'] = $message === GC_GENERIC
            ? 'Google could not confirm your account, so nothing was changed. Please try again.'
            : $message;
        header('Location: customer-profile.php#accountSecurityTitle');
    } else {
        $_SESSION['login_notice'] = $message;
        header('Location: customer-login.php');
    }
    exit;
}

if (!google_enabled()) {
    gc_fail('Sign-in with Google is not set up on this server yet. Log in with your email and password.');
}

/* Consumed before anything else, so this return can never be replayed. */
$flow = google_take_flow(is_string($_GET['state'] ?? null) ? $_GET['state'] : '');
if ($flow === null) {
    gc_fail('That Google sign-in expired or was already used. Please try again.', 'state missing, wrong or expired');
}

$googleError = $_GET['error'] ?? null;
if ($googleError !== null) {
    if ($googleError === 'access_denied') {
        gc_fail('Google sign-in was cancelled.');
    }
    gc_fail(GC_GENERIC, 'Google returned error ' . mb_substr(is_string($googleError) ? $googleError : '?', 0, 60));
}
$code = $_GET['code'] ?? null;
if (!is_string($code) || $code === '' || strlen($code) > 2048) {
    gc_fail(GC_GENERIC, 'no authorization code');
}

$pdo = venusep_db();
if ($pdo === null) {
    gc_fail('The database is unreachable right now, so you could not be signed in. Please try again in a few minutes.');
}

$cfg = google_config();
try {
    $claims = google_exchange_code($code, (string) $flow['verifier'], $cfg);
} catch (Throwable $e) {
    gc_fail(GC_GENERIC, $e->getMessage());
}
$problem = google_check_claims($claims, (string) $cfg['client_id'], (string) $flow['nonce'], time());
if ($problem !== null) {
    gc_fail(GC_GENERIC, 'ID token refused: ' . $problem);
}

$sub   = (string) $claims['sub'];
$email = strtolower(trim((string) $claims['email']));
$name  = trim((string) ($claims['name'] ?? ''));

/* ---- link / reauth: a signed-in customer, back from the profile page ---- */
$purpose = (string) ($flow['purpose'] ?? 'signin');
if ($purpose !== 'signin') {
    /* Only the same customer who started the trip may finish it: a session that
       logged out, or changed hands, in between gets nothing. */
    if (!customer_logged_in() || (int) $_SESSION['user_id'] !== (int) ($flow['user_id'] ?? 0)) {
        gc_fail(GC_GENERIC, $purpose . ' finished by a different session');
    }
    $userId = (int) $_SESSION['user_id'];
    try {
        if ($purpose === 'link') {
            $problem = google_link($pdo, $userId, $sub);
            $_SESSION['profile_notice'] = $problem ?? 'Google account connected. You can now sign in with Google too.';
        } else {
            $stmt = $pdo->prepare('SELECT google_sub FROM users WHERE id = :u');
            $stmt->execute([':u' => $userId]);
            $linked = $stmt->fetchColumn();
            if (!is_string($linked) || !hash_equals($linked, $sub)) {
                $_SESSION['profile_notice'] = 'That was a different Google account from the one connected to VENUSeP. Choose the connected one.';
            } else {
                google_proof_set('google_reauth', $userId);
                $_SESSION['profile_notice'] = 'Google confirmed it’s you. Choose your password below.';
            }
        }
    } catch (PDOException $e) {
        gc_fail(GC_GENERIC, $purpose . ' failed: ' . $e->getMessage());
    }
    header('Location: customer-profile.php#accountSecurityTitle');
    exit;
}

try {
    $who = google_resolve($pdo, $sub, $email);
} catch (PDOException $e) {
    gc_fail(GC_GENERIC, 'resolve failed: ' . $e->getMessage());
}

switch ($who['action']) {
    case 'signin':
        /* Same hand-off as a correct password: the two-step code if they turned
           it on, otherwise straight in. The code step then runs on the login page. */
        $remember = !empty($flow['remember']);   // "Remember me" ticked before leaving for Google
        tfa_after_password($pdo, (int) $who['user_id'], 'customer', 'customer', (string) $who['email'],
            ['remember' => $remember], 'customer-login.php',
            function () use ($pdo, $who, $remember) {
                cl_finish_login($pdo, (int) $who['user_id'], $remember);
            });
        break;

    case 'taken':
        gc_fail('This email is already registered. Log in with your email and password. You can connect Google from your profile afterwards.');
        break;

    case 'new':
        /* Not an account yet: the login page shows the one-time "finish your
           account" screen (terms + optional mobile), and creates it from there. */
        session_regenerate_id(true);
        $_SESSION['google_signup'] = ['sub' => $sub, 'email' => $email, 'name' => mb_substr($name, 0, 190), 'at' => time(),
                                      'remember' => !empty($flow['remember'])];
        header('Location: customer-login.php');
        exit;

    default:
        gc_fail(GC_GENERIC, 'refused: staff/admin, suspended, or no customer profile');
}
