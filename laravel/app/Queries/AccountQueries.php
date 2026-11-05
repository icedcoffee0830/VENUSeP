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
