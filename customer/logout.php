<?php
/* CUSTOMER LOG OUT — really ends the session, then shows the login page.
   "Log Out" (sidebar + profile) used to be a plain link to customer-login.php,
   which left the session alive. */
require_once __DIR__ . '/../includes/auth.php';

venusep_logout();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: customer-login.php');
exit;
