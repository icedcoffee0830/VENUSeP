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

/* =====================================================================
   ONE CLOCK — the app and the database must agree on what day it is.

   They did not. php.ini here sets date.timezone=Europe/Berlin while MySQL
   runs on SYSTEM (UTC+8), leaving PHP six hours behind the database — on a
   DIFFERENT CALENDAR DAY for eight hours out of every twenty-four.

   That matters because "today" is load-bearing all over this system and is
   asked of BOTH engines: fn_payment_deadline() and CURDATE() answer in
   MySQL's timezone, while payment_policy_for() and cb_is_refundable()
   answer in PHP's. Six hours of disagreement means a booking whose event is
   today in the database is tomorrow in PHP, a deadline lands on the wrong
   date, and a refund the database considers eligible is refused by the app.

   Set HERE rather than in php.ini so the app is correct on any machine it
   is deployed to, whatever that server happens to be configured for.
   VENUSEP_TZ is the university's own timezone; venusep_schema.sql already
   declares the same +08:00 offset.
   ===================================================================== */
define('VENUSEP_TZ', 'Asia/Manila');
if (date_default_timezone_get() !== VENUSEP_TZ) {
    date_default_timezone_set(VENUSEP_TZ);
}

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
        /* Pin the CONNECTION's timezone to match PHP's, rather than trusting
           the server's SYSTEM setting to be what this app expects. NOW() and
           CURDATE() — which fn_payment_deadline(), sp_expire_due_bookings()
           and the demo seed all rely on — now answer in the same timezone the
           PHP above computes in. */
        $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
        $pdo = null;
    }
    return $pdo;
}

/* =====================================================================
   FAIL LOUD — for pages that cannot honestly render without the database.

   venusep_db() returns null and lets the caller decide. That was right when
   the database supplied only a room's name and the page could fall back to a
   hard-coded copy. It stops being right once the database supplies the rooms,
   the prices, the availability and the bookings: there is nothing safe to fall
   back TO, and a page that renders anyway would be showing invented data as
   though it were real.

   So catalog + booking pages call this instead. It stops the page dead with a
   503 and a short human explanation, rather than rendering something wrong.
   Never prints the PDO error — it can carry the host and user name.
   ===================================================================== */
function venusep_db_or_fail()
{
    $pdo = venusep_db();
    if ($pdo !== null) {
        return $pdo;
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Retry-After: 120');
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>VENUSeP | Temporarily unavailable</title>'
       . '<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#faf8f5;color:#1f1e1e;font:16px/1.6 Inter,system-ui,-apple-system,"Segoe UI",sans-serif;padding:24px}'
       . '.c{max-width:26rem;text-align:center}h1{font-size:20px;margin:0 0 10px}'
       . 'p{margin:0 0 18px;color:#6e6a64;font-size:14.5px}'
       . 'a{display:inline-block;padding:10px 18px;border-radius:999px;background:#a11626;color:#fff;'
       . 'text-decoration:none;font-weight:600;font-size:14px}</style></head><body><div class="c">'
       . '<h1>VENUSeP is temporarily unavailable</h1>'
       . '<p>We could not reach the booking database, so this page cannot be shown right now. '
       . 'Nothing you have booked is affected. Please try again in a few minutes.</p>'
       . '<a href="javascript:location.reload()">Try again</a>'
       . '</div></body></html>';
    exit;
}
