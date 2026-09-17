<?php
/* ADMIN LOG OUT — really ends the session, then shows the login page.
   The sidebar's "Log Out" used to be a plain link to admin-login.php, which
   left the session alive: the next person at the same PC could press Back
   and still be logged in as admin. */
require_once __DIR__ . '/../includes/auth.php';

venusep_logout();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: admin-login.php');
exit;
