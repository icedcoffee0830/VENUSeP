<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for the counter's customer handling (DB-DECISIONS 28): finding any customer
   account, making one at the counter, and correcting a counter account's wrong email.
   Columns: venusep_schema.sql and venusep_migration_17.sql.

   customers.counter_venue_id: the office whose counter made the account (NULL = the
     customer signed up themselves).
   customers.user_id NULL: a walk-in with no account (DB-DECISIONS #4).
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

    /* The customer's account, locked for the password check at the counter (reauth_verify,
       auth.php): two wrong passwords at the same moment must both count. Null when there is none. */
    public static function lockForPasswordCheck(int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.password_hash, u.is_active, u.reauth_failed_attempts, u.reauth_lock_level,
                   CASE WHEN u.reauth_locked_until > NOW()
                        THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until)
                        ELSE 0 END AS lock_seconds
            FROM customers c
            JOIN users u ON u.id = c.user_id
            WHERE c.id = ?
            LIMIT 1
            FOR UPDATE
        ", [$customerId]);
        return $row ? (array) $row : null;
    }

    /* Customer Management: every customer, or for a normal admin ($scope: its office ids) those
       who booked there or were made at its counter, with their bookings counted at those offices. */
    public static function listForAdmin(?array $scope): array
    {
        $offices = $scope === null ? '' : implode(',', array_map('intval', $scope) ?: [0]);
        $mine = $scope === null ? '' : " AND r.venue_id IN ($offices)";
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT u.id AS user_id, u.email, u.is_active, u.last_login_at, u.google_sub IS NULL AS not_google,
                   c.id AS customer_id, c.full_name, c.phone, c.university_id_no, c.created_at, c.counter_venue_id,
                   (SELECT COUNT(*) FROM bookings b JOIN rooms r ON r.id = b.room_id
                     WHERE b.customer_id = c.id $mine) AS booking_count,
                   (SELECT COUNT(*) FROM bookings b JOIN rooms r ON r.id = b.room_id
                     WHERE b.customer_id = c.id $mine
                       AND b.reservation_status IN ('pending', 'approved')
                       AND (b.payment_status = 'overdue' OR fn_booking_last_day(b.id) >= CURDATE())) AS open_count
            FROM customers c
            JOIN users u ON u.id = c.user_id
            WHERE u.account_type = 'customer'"
            . ($scope === null ? '' : " AND (EXISTS (SELECT 1 FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.customer_id = c.id $mine)
                                              OR c.counter_venue_id IN ($offices))") . "
            ORDER BY c.full_name
        "));
    }

    /* The account an admin is about to change, or null. */
    public static function findUser(int $userId): ?array
    {
        $row = DB::selectOne('SELECT id, account_type, is_active FROM users WHERE id = ?', [$userId]);
        return $row ? (array) $row : null;
    }

    /* What the booking pages show of the signed-in customer, or null. */
    public static function findContact(int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT c.id, c.full_name, c.phone, c.university_id_no, c.photo_path, u.email
            FROM customers c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE c.id = ?
            LIMIT 1
        ", [$customerId]);
        return $row ? (array) $row : null;
    }

    /* A customer account's own address and name, or null. */
    public static function findEmailAndName(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.email, c.full_name
            FROM users u
            JOIN customers c ON c.user_id = u.id
            WHERE u.id = ?
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    /* The active account a counter booking is for, and whether it has no password, or null. */
    public static function findForCounterBooking(int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT c.id, c.full_name, u.password_hash IS NULL AS no_password
            FROM customers c
            JOIN users u ON u.id = c.user_id
            WHERE c.id = ? AND u.is_active = 1
            LIMIT 1
        ", [$customerId]);
        return $row ? (array) $row : null;
    }

    /* A walk-in guest with no account. */
    public static function addWalkIn(string $name, string $phone, string $email, string $address): int
    {
        DB::insert("
            INSERT INTO customers (user_id, full_name, phone, contact_email, address)
            VALUES (NULL, ?, ?, ?, ?)
        ", [$name, $phone, $email !== '' ? $email : null, $address !== '' ? $address : null]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* Take away a walk-in whose first booking could not be finished. */
    public static function removeWalkIn(int $customerId): void
    {
        DB::delete('DELETE FROM customers WHERE id = ? AND user_id IS NULL', [$customerId]);
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
