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
use Illuminate\Support\Facades\Log;

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
        /* Pages ask many times; an outage gets one line per request, and its own 503 page. */
        static $told = false;
        if (!$told) {
            $told = true;
            sql_failed($e, optional: true);
        }
        return null;
    }
}

/* A catch around a query reports here, after its own rollback and clean-up, then goes on
   to its friendly reply. laravel.log is the one place to look for SQL errors. With APP_DEBUG
   on the error is rethrown, so it is seen instead of "Something went wrong" or an empty list.
   A refusal is an answer, not a failure: a procedure's SIGNAL 45000, a deadlock (40001) and
   a duplicate key (1062) are never rethrown, whatever the caller does with them.
   $optional: work that must not cost the action around it (a notification, an email).
   No stack trace in the line: its arguments could carry part of a password. */
function sql_failed(Throwable $e, bool $optional = false): void
{
    $refusal = $e instanceof PDOException
        && (in_array($e->getCode(), ['45000', '40001'], true) || ($e->errorInfo[1] ?? null) === 1062);
    Log::error('SQL failed at ' . $e->getFile() . ':' . $e->getLine() . ': ' . $e->getMessage());
    if (!$refusal && !$optional && config('app.debug')) {
        throw $e;
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
            sql_failed($e);
            /* keep the default */
        }
    }
    return $gap;
}

/* The deadline rules, applied before anyone looks. Once per request. */
function venusep_expire_due_bookings()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = venusep_db();
    if ($pdo === null) {
        return;
    }
    try {
        $pdo->exec('CALL sp_expire_due_bookings()');
        require_once __DIR__ . '/documents.php';
        doc_remove_finished_ids($pdo);
        /* After the response: an email must not slow a page, and loading the booking
           code here, inside a function, would hide its variables from the page. */
        app()->terminating(function () use ($pdo) {
            try {
                legacy('bookings', 'receipt-emails');
                mail_status_sweep($pdo);
            } catch (\Throwable $e) {
                sql_failed($e, optional: true);
            }
        });
    } catch (\Throwable $e) {
        sql_failed($e);
    }
}
