<?php
/* CUSTOMER LOG OUT — really ends the session, then shows the login page.
   "Log Out" (sidebar + profile) used to be a plain link to customer-login.php,
   which left the session alive. It also forgets this device if "Remember me"
   was ticked (#23), or the cookie would sign them straight back in. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/remember-me.php';

venusep_session_start();
$pdo = venusep_db();
if ($pdo !== null) {
    remember_forget_current($pdo);
} else {
    remember_cookie_clear();   // the row expires on its own; this device is forgotten either way
}
venusep_logout();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: customer-login.php');
exit;
