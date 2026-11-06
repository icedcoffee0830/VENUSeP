<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/* The SQL that changes who runs the system (StaffController, DB-DECISIONS 28).
   Columns: venusep_schema.sql and venusep_migration_17.sql.

   users: every account. is_super_admin marks the one super admin (uq_users_one_super_admin
     allows only one; chk_users_super_admin keeps it an active admin with no group),
     office_group is a normal admin's group, handed_over_at a former super admin. */
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
