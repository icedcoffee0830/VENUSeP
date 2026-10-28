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

/* The credentials, in ONE place. A function so admin backup & restore
   (includes/backup.php) hands the SAME ones to mysqldump / mysql. */
function venusep_db_config()
{
    return ['host' => '127.0.0.1', 'name' => 'venusep', 'user' => 'root', 'pass' => ''];
}

function venusep_db()
{
    static $pdo = null;
    static $tried = false;
    if ($tried) {
        return $pdo;
    }
    $tried = true;

    $dbCfg  = venusep_db_config();
    $dbHost = $dbCfg['host'];
    $dbName = $dbCfg['name'];
    $dbUser = $dbCfg['user'];
    $dbPass = $dbCfg['pass'];

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

/* =====================================================================
   THE DEADLINE RULES, APPLIED BEFORE ANYONE LOOKS (2026-10-27).

   sp_expire_due_bookings() expires requests nobody approved, releases
   unpaid pre-pay holds, opens post-pay payment windows and marks overdue
   ones. The schema always said to call it "before availability / queue /
   detail reads", but nothing in the app ever did — it only ran inside the
   two procedures that take a NEW booking, so a booking expired only when
   somebody else happened to book. Now every booking list, calendar and
   availability read calls this first.

   Once per request. Skipped while demo mode is on, because a showcase
   promises to change nothing. It runs on the next ordinary page load.
   ===================================================================== */
/* Minutes kept free before and after every VENUE booking (2026-10-27), so a
   room can take several bookings on one day. system_settings is the one
   source: sp_add_venue_slot() enforces it, and the booking page draws from
   it. 60 if the setting cannot be read. */
function venue_cleanup_gap_minutes()
{
    static $gap = null;
    if ($gap !== null) {
        return $gap;
    }
    $gap = 60;
    $pdo = venusep_db();
    if ($pdo !== null) {
        try {
            $v = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes'")->fetchColumn();
            if ($v !== false && ctype_digit((string) $v)) {
                $gap = (int) $v;
            }
        } catch (PDOException $e) {
            /* keep the default */
        }
    }
    return $gap;
}

function venusep_expire_due_bookings()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    require_once __DIR__ . '/demo-mode.php';
    if (demo_mode_on()) {
        return;
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->exec('CALL sp_expire_due_bookings()');
    } catch (PDOException $e) {
        /* Never stop a page over housekeeping. The rules run again on the
           next read. */
        error_log('VENUSeP sp_expire_due_bookings: ' . $e->getMessage());
    }
}
