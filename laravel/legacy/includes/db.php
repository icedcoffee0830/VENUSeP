<?php
/* =====================================================================
   DATABASE — Laravel shim for the old includes/db.php.

   The legacy includes in this folder run unchanged inside Laravel. They
   call venusep_db(); here that returns Laravel's own PDO, so there is ONE
   connection and ONE place the credentials live: laravel/.env (DB_*).
   config/database.php gives that connection everything the old db.php set
   by hand: FETCH_ASSOC by default, real prepares, time_zone +08:00.

   Every other function the old db.php defined is kept with the same name
   and the same behaviour, because the legacy includes call them.
   ===================================================================== */

use Illuminate\Support\Facades\DB;

if (!defined('VENUSEP_TZ')) {
    define('VENUSEP_TZ', 'Asia/Manila');
}
if (date_default_timezone_get() !== VENUSEP_TZ) {
    date_default_timezone_set(VENUSEP_TZ);
}

/* The credentials, read from Laravel's config. includes/backup.php hands
   the same ones to mysqldump / mysql. */
function venusep_db_config()
{
    $c = config('database.connections.' . config('database.default'));
    return [
        'host' => (string) $c['host'],
        'name' => (string) $c['database'],
        'user' => (string) $c['username'],
        'pass' => (string) $c['password'],
    ];
}

function venusep_db()
{
    try {
        return DB::connection()->getPdo();
    } catch (Throwable $e) {
        return null;
    }
}

/* For pages that cannot honestly render without the database: a 503 and a
   short human explanation, never the PDO error. */
function venusep_db_or_fail()
{
    $pdo = venusep_db();
    if ($pdo !== null) {
        return $pdo;
    }
    abort(response()->view('errors.db-unavailable', [], 503)->header('Retry-After', '120'));
}

/* Minutes kept free before and after every VENUE booking. 60 if unreadable. */
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

/* The deadline rules, applied before anyone looks. Once per request;
   skipped while demo mode is on. */
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
        error_log('VENUSeP sp_expire_due_bookings: ' . $e->getMessage());
    }
}
