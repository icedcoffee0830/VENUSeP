<?php
/* =====================================================================
   GOOGLE SIGN-IN, STEP 1 — "Continue with Google" lands here and is sent
   on to Google. includes/google-auth.php explains the whole flow.

     (no purpose)     log in or sign up — the login and register pages
                      (&remember=1: "Remember me" was ticked, #23)
     ?purpose=link    connect Google to the signed-in account — only right
                      after the password was re-typed (profile-save.php,
                      google_link_begin), and that proof is spent here
     ?purpose=reauth  prove the linked Google account again, so a
                      Google-only account may set its first password

   A plain GET on purpose: starting a trip changes nothing. The worst
   another site can do by linking here is show someone Google's own
   account picker, and the callback refuses any return this browser did
   not start (the state check).
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/google-auth.php';

venusep_session_start();
header('Cache-Control: no-store');

$purpose = in_array($_GET['purpose'] ?? '', ['link', 'reauth'], true) ? $_GET['purpose'] : 'signin';

if ($purpose === 'signin') {
    if (customer_logged_in()) {
        header('Location: venusep_venue_booking.php');
        exit;
    }
    if (!google_enabled()) {
        $_SESSION['login_notice'] = 'Sign-in with Google is not set up on this server yet. Log in with your email and password.';
        header('Location: customer-login.php');
        exit;
    }
    header('Location: ' . google_begin('signin', 0, !empty($_GET['remember'])));   // "Remember me" ticked on the login page
    exit;
}

/* link / reauth: from the profile page, for the customer who is signed in. */
if (!customer_logged_in()) {
    header('Location: customer-login.php');
    exit;
}
$userId = (int) $_SESSION['user_id'];
if (!google_enabled()) {
    $_SESSION['profile_notice'] = 'Google sign-in is not set up on this server yet.';
    header('Location: customer-profile.php#accountSecurityTitle');
    exit;
}
if ($purpose === 'link') {
    if (!google_proof_valid('google_link', $userId)) {
        $_SESSION['profile_notice'] = 'Confirm your password first, then connect Google.';
        header('Location: customer-profile.php#accountSecurityTitle');
        exit;
    }
    google_proof_clear('google_link');   // one password confirmation, one trip
}
header('Location: ' . google_begin($purpose, $userId));
exit;
