<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/* The SQL for accounts: signing in, registering, the profile page, and who runs the system
   (StaffController, DB-DECISIONS 28). Columns: venusep_schema.sql and venusep_migration_17.sql.

   users: every account. is_super_admin marks the one super admin (uq_users_one_super_admin
     allows only one; chk_users_super_admin keeps it an active admin with no group),
     office_group is a normal admin's group, handed_over_at a former super admin.
     password_hash NULL: a Google account with no password yet. uq_users_email: one account
     per email. reauth_*: the lockout for retyping a password (reauth_verify, auth.php).
   staff: a staff member's or admin's profile; venue_id is the one office a staff member handles.
   customers: a customer's profile.
   password_resets: emailed reset links; used_at set = spent. */
class AccountQueries
{
    /* Hand the super admin over, inside the caller's transaction: the old one is suspended
       for good (never deleted, never a normal admin), the normal admin becomes the super
       admin. Anything other than exactly one row each is refused, so the caller rolls back. */
    /* The signed-in administrator's row, locked until the caller's transaction ends, with what
       reauth_verify() (legacy/includes/auth.php) needs to check the password: two wrong
       tries at the same moment must both count. */
    public static function lockForPasswordCheck(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.password_hash, u.account_type, u.is_active, u.is_super_admin,
                   u.reauth_failed_attempts, u.reauth_lock_level,
                   CASE WHEN u.reauth_locked_until > NOW()
                        THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until)
                        ELSE 0 END AS lock_seconds
            FROM users u
            WHERE u.id = ?
            FOR UPDATE
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    /* The super admin's row for a handover: the password check's columns, with their email and
       name, locked until the caller's transaction ends; or null. */
    public static function lockForHandover(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.email, u.password_hash, u.account_type, u.is_active, u.is_super_admin,
                   u.reauth_failed_attempts, u.reauth_lock_level, COALESCE(s.full_name, u.username) AS full_name,
                   CASE WHEN u.reauth_locked_until > NOW()
                        THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until)
                        ELSE 0 END AS lock_seconds
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            WHERE u.id = ?
            FOR UPDATE
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    /* Seconds left on a lock from wrong passwords, 0 when there is none. */
    public static function passwordLockSeconds(int $userId): int
    {
        return (int) (DB::selectOne("
            SELECT CASE WHEN reauth_locked_until > NOW()
                        THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until)
                        ELSE 0 END AS seconds
            FROM users
            WHERE id = ?
        ", [$userId])->seconds ?? 0);
    }

    /* The account's role, read fresh instead of trusting the session; or null. */
    public static function findTypeAndActive(int $userId): ?array
    {
        $row = DB::selectOne('SELECT account_type, is_active FROM users WHERE id = ?', [$userId]);
        return $row ? (array) $row : null;
    }

    /* The account behind a staff sign-in, and whether a cover going on now gives them the
       picked office; or null. */
    public static function findStaffSignIn(string $email, int $department): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.email, u.username, u.password_hash, u.account_type, u.is_active,
                   s.venue_id AS staff_venue_id, v.name AS staff_venue_name,
                   EXISTS (SELECT 1 FROM staff_covers c
                            WHERE c.user_id = u.id AND c.venue_id = ?
                              AND " . STAFF_COVER_ON_SQL . ") AS cover_card
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            LEFT JOIN venues v ON v.id = s.venue_id
            WHERE u.email = ?
            LIMIT 1
        ", [$department, $email]);
        return $row ? (array) $row : null;
    }

    /* The account behind a customer sign-in, or null. */
    public static function findCustomerSignIn(string $email): ?array
    {
        $row = DB::selectOne("
            SELECT u.id AS user_id, u.email, u.password_hash, u.is_active,
                   c.id AS customer_id, c.full_name, c.phone, c.address, c.university_id_no
            FROM users u
            JOIN customers c ON c.user_id = u.id
            WHERE u.email = ? AND u.account_type = 'customer'
            LIMIT 1
        ", [$email]);
        return $row ? (array) $row : null;
    }

    /* The account as it stands at the last step of a sign-in, or null. */
    public static function findForSession(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT id, email, username, account_type, is_active
            FROM users
            WHERE id = ?
            LIMIT 1
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    public static function markSignedIn(int $userId): void
    {
        DB::update('UPDATE users SET last_login_at = NOW() WHERE id = ?', [$userId]);
    }

    /* A new account and its profile, all or nothing. $group: an admin's; $venueId: a staff member's. */
    public static function create(string $email, string $username, string $hash, string $kind, ?string $group, ?int $venueId, string $name, ?string $phone): void
    {
        DB::transaction(function () use ($email, $username, $hash, $kind, $group, $venueId, $name, $phone) {
            DB::insert("
                INSERT INTO users (email, username, password_hash, account_type, office_group)
                VALUES (?, ?, ?, ?, ?)
            ", [$email, $username, $hash, $kind, $group]);
            $userId = (int) DB::getPdo()->lastInsertId();
            if ($kind === 'customer') {
                DB::insert('INSERT INTO customers (user_id, full_name, phone) VALUES (?, ?, ?)', [$userId, $name, $phone]);
            } else {
                DB::insert('INSERT INTO staff (user_id, venue_id, full_name, phone) VALUES (?, ?, ?, ?)', [$userId, $venueId, $name, $phone]);
            }
        });
    }

    /* An account's email and password hash, or null. */
    public static function findEmailAndHash(int $userId): ?array
    {
        $row = DB::selectOne('SELECT email, password_hash FROM users WHERE id = ?', [$userId]);
        return $row ? (array) $row : null;
    }

    /* The new email; a reset link mailed to the old address stops working. Inside the caller's transaction. */
    private static function saveEmail(int $userId, string $email): void
    {
        DB::update('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
        self::spendResetLinks($userId);
    }

    private static function spendResetLinks(int $userId): void
    {
        DB::update('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /* A customer's own details, from the profile page. */
    public static function saveCustomerProfile(int $userId, string $email, string $name, ?string $phone, ?string $address, ?string $universityId): void
    {
        DB::transaction(function () use ($userId, $email, $name, $phone, $address, $universityId) {
            self::saveEmail($userId, $email);
            DB::update("
                UPDATE customers
                SET full_name = ?,
                    phone = ?,
                    address = ?,
                    university_id_no = ?
                WHERE user_id = ?
            ", [$name, $phone, $address, $universityId, $userId]);
        });
    }

    /* A staff member's or admin's own details. The seeded admin has no staff row: made on first save. */
    public static function saveStaffProfile(int $userId, string $email, string $name, ?string $phone): void
    {
        DB::transaction(function () use ($userId, $email, $name, $phone) {
            self::saveEmail($userId, $email);
            DB::insert("
                INSERT INTO staff (user_id, full_name, phone)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE full_name = VALUES(full_name), phone = VALUES(phone)
            ", [$userId, $name, $phone]);
        });
    }

    /* A Google-only account's first password. False when one was set meanwhile. */
    public static function setFirstPassword(int $userId, string $hash): bool
    {
        return DB::update("
            UPDATE users
            SET password_hash = ?,
                password_changed_at = NOW()
            WHERE id = ? AND password_hash IS NULL
        ", [$hash, $userId]) === 1;
    }

    /* A new password, inside the caller's transaction: old reset links stop working. */
    public static function changePassword(int $userId, string $hash): void
    {
        DB::update('UPDATE users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?', [$hash, $userId]);
        self::spendResetLinks($userId);
    }

    /* A temporary password from an admin: the owner must replace it, and any lock is lifted. */
    public static function resetPassword(int $userId, string $hash): void
    {
        DB::update("
            UPDATE users
            SET password_hash = ?,
                password_changed_at = NULL,
                reauth_failed_attempts = 0,
                reauth_lock_level = 0,
                reauth_locked_until = NULL
            WHERE id = ?
        ", [$hash, $userId]);
    }

    /* What Google sign-in needs to know of an account, or null. */
    public static function findPasswordAndGoogle(int $userId): ?array
    {
        $row = DB::selectOne('SELECT password_hash, google_sub FROM users WHERE id = ?', [$userId]);
        return $row ? (array) $row : null;
    }

    public static function unlinkGoogle(int $userId): void
    {
        DB::update('UPDATE users SET google_sub = NULL WHERE id = ? AND password_hash IS NOT NULL', [$userId]);
    }

    /* An admin may have no staff row yet: made here, or saving a photo would match nothing. */
    public static function ensureStaffRow(int $userId): void
    {
        DB::insert("
            INSERT INTO staff (user_id, full_name)
            SELECT id, username FROM users WHERE id = ?
            ON DUPLICATE KEY UPDATE user_id = user_id
        ", [$userId]);
    }

    /* $table: customers or staff. $path NULL removes the photo. */
    public static function savePhoto(string $table, int $userId, ?string $path): void
    {
        DB::update("UPDATE {$table} SET photo_path = ? WHERE user_id = ?", [$path, $userId]);
    }

    public static function setActive(int $userId, int $active): void
    {
        DB::update('UPDATE users SET is_active = ? WHERE id = ?', [$active, $userId]);
    }

    /* Every admin and staff account for Staff Management. */
    public static function listStaffSide(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT u.id, u.email, u.account_type, u.is_active, u.last_login_at,
                   u.is_super_admin, u.office_group, u.handed_over_at,
                   COALESCE(s.full_name, u.username) AS full_name,
                   s.employee_no, s.position_role, s.phone, s.venue_id,
                   v.name AS venue_name, v.office_group AS venue_group, v.venue_type
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            LEFT JOIN venues v ON v.id = s.venue_id
            WHERE u.account_type IN ('admin', 'staff')
            ORDER BY u.account_type, COALESCE(s.full_name, u.username)
        "));
    }

    /* The account an admin is about to change, or null. */
    public static function findForManaging(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.email, u.account_type, u.is_active, u.is_super_admin, u.handed_over_at,
                   s.user_id AS staff_user_id, s.venue_id AS old_venue_id, s.venue_id, s.full_name
            FROM users u
            LEFT JOIN staff s ON s.user_id = u.id
            WHERE u.id = ?
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    /* An admin saves a staff-side account. $venueGiven false leaves its office alone. */
    public static function saveStaffAccount(int $userId, string $email, string $name, ?string $role, ?string $phone, bool $venueGiven, ?int $venueId): void
    {
        DB::transaction(function () use ($userId, $email, $name, $role, $phone, $venueGiven, $venueId) {
            DB::update('UPDATE users SET email = ? WHERE id = ?', [$email, $userId]);
            if ($venueGiven) {
                DB::insert("
                    INSERT INTO staff (user_id, venue_id, full_name, position_role, phone)
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE venue_id = VALUES(venue_id), full_name = VALUES(full_name),
                                            position_role = VALUES(position_role), phone = VALUES(phone)
                ", [$userId, $venueId, $name, $role, $phone]);
            } else {
                DB::insert("
                    INSERT INTO staff (user_id, full_name, position_role, phone)
                    VALUES (?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE full_name = VALUES(full_name),
                                            position_role = VALUES(position_role), phone = VALUES(phone)
                ", [$userId, $name, $role, $phone]);
            }
        });
    }

    public static function countActiveAdmins(): int
    {
        return (int) DB::selectOne("SELECT COUNT(*) AS n FROM users WHERE account_type = 'admin' AND is_active = 1")->n;
    }

    public static function handOverSuperAdmin(int $fromId, int $toId): void
    {
        $old = DB::update("
            UPDATE users
            SET is_super_admin = 0, is_active = 0, handed_over_at = NOW()
            WHERE id = ? AND is_super_admin = 1
        ", [$fromId]);
        $new = DB::update("
            UPDATE users
            SET is_super_admin = 1, office_group = NULL
            WHERE id = ? AND account_type = 'admin' AND is_super_admin = 0
              AND is_active = 1 AND handed_over_at IS NULL
        ", [$toId]);
        if ($old !== 1 || $new !== 1) {
            throw new RuntimeException("AccountQueries::handOverSuperAdmin changed $old and $new rows");
        }
    }
}
