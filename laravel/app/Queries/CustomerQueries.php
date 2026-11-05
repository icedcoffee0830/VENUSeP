<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for the counter's customer handling (DB-DECISIONS 28): finding any customer
   account, making one at the counter, and correcting a counter account's wrong email.
   Columns: venusep_schema.sql and venusep_migration_17.sql.

   customers.counter_venue_id: the office whose counter made the account (NULL = the
     customer signed up themselves).
   customer_email_fixes: one row per corrected email (old, new, reason, who, when).
   Read only: users (an account never signed into = last_login_at NULL; a Google
     account = google_sub set). */
class CustomerQueries
{
    /* A stored phone is the mobile (11 digits, 09...) whatever spaces, dashes or +63 it was typed with. */
    private const SAME_MOBILE = "REGEXP_REPLACE(c.phone, '[^0-9]', '') IN (?, ?)";

    private static function mobileArgs(string $mobile): array
    {
        return [$mobile, '63' . substr($mobile, 1)];
    }

    /* Active customer accounts of every office, ten at most, by the exact email, the exact
       mobile, or part of the name (LIKE, wildcards already escaped by the caller).
       Only what the counter needs to tell people apart: nothing about bookings. */
    public static function search(string $by, string $value): array
    {
        [$where, $args] = match ($by) {
            'email'  => ['u.email = ?', [$value]],
            'mobile' => [self::SAME_MOBILE, self::mobileArgs($value)],
            'name'   => ['c.full_name LIKE ?', ['%' . $value . '%']],
        };
        return DB::select("
            SELECT c.id, c.full_name, c.phone, u.email, u.password_hash IS NULL AS no_password
            FROM customers c
            JOIN users u ON u.id = c.user_id
            WHERE u.is_active = 1 AND $where
            ORDER BY c.full_name
            LIMIT 10
        ", $args);
    }

    /* Any account (customer, staff or admin) already has this email. */
    public static function emailTaken(string $email): bool
    {
        return DB::selectOne('SELECT 1 AS x FROM users WHERE email = ?', [$email]) !== null;
    }

    /* Another customer account already gives this mobile. */
    public static function mobileUsed(string $mobile): bool
    {
        return DB::selectOne('
            SELECT 1 AS x
            FROM customers c
            WHERE c.user_id IS NOT NULL AND ' . self::SAME_MOBILE, self::mobileArgs($mobile)) !== null;
    }

    /* A customer account made at this office's counter, inside the caller's transaction:
       no password yet (the welcome email says how to choose one). Returns the customer id. */
    public static function createAtCounter(string $email, string $username, string $name, string $mobile, int $venueId): int
    {
        DB::insert("
            INSERT INTO users (email, username, password_hash, account_type)
            VALUES (?, ?, NULL, 'customer')
        ", [$email, $username]);
        $userId = (int) DB::getPdo()->lastInsertId();
        DB::insert("
            INSERT INTO customers (user_id, full_name, phone, counter_venue_id)
            VALUES (?, ?, ?, ?)
        ", [$userId, $name, $mobile, $venueId]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* What the email-fix rule reads about one customer account, or null for a walk-in or none. */
    public static function account(int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT c.id, c.full_name, c.counter_venue_id, u.id AS user_id, u.email, u.last_login_at, u.google_sub
            FROM customers c
            JOIN users u ON u.id = c.user_id
            WHERE c.id = ?
        ", [$customerId]);
        return $row ? (array) $row : null;
    }

    /* Correct the email of a counter account never signed into, inside the caller's
       transaction. Anything sent to the wrong address stops working: its unused reset
       links, and a password someone there may have chosen. False when the account was
       signed into, linked to Google or changed a moment ago. */
    public static function fixEmail(array $account, string $newEmail, string $reason, int $actor): bool
    {
        $changed = DB::update("
            UPDATE users
            SET email = ?, password_hash = NULL, email_verified_at = NULL
            WHERE id = ? AND email = ? AND last_login_at IS NULL AND google_sub IS NULL
        ", [$newEmail, $account['user_id'], $account['email']]);
        if ($changed !== 1) {
            return false;
        }
        DB::update('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$account['user_id']]);
        DB::insert("
            INSERT INTO customer_email_fixes (customer_id, old_email, new_email, reason, fixed_by_user_id)
            VALUES (?, ?, ?, ?, ?)
        ", [$account['id'], $account['email'], $newEmail, $reason, $actor]);
        return true;
    }
}
