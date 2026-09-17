<?php
/* =====================================================================
   DATABASE CONNECTION — the ONE place the MySQL credentials live.

   Included by:
     admin/admin-login.php, customer/customer-login.php   (the logins)
     includes/refund-policy.php                           (the refund switch)
     admin/refund-switch.php                              (changing it)

   WHY ONE FILE: the two login pages used to each open their own connection
   with their own copy of the credentials (one said 127.0.0.1, the other
   localhost). Change the password on one and the other silently breaks.

   XAMPP defaults: user root, empty password. Change them HERE only.
   For production, move them to environment variables or a config file
   outside the web root.

   Returns null when the database cannot be reached — callers decide what
   that means (a login shows an error; the refund switch fails safe to OFF).
   Never echoes the PDO error: it can contain the host and user name.
   ===================================================================== */

function venusep_db()
{
    static $pdo = null;
    static $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $dbHost = '127.0.0.1';
    $dbName = 'venusep';
    $dbUser = 'root';
    $dbPass = '';

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    } catch (PDOException $e) {
        $pdo = null;
    }
    return $pdo;
}
