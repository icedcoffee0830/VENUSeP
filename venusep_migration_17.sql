-- ============================================================================
--  VENUSeP — MIGRATION 17: roles and offices (decided 2026-11-04, DB-DECISIONS 28).
--  Run against an EXISTING venusep database, after 16. Safe to re-run
--  (idempotent): an account an admin has changed since is never overwritten.
--
--      Windows (cmd, not PowerShell):
--          C:\xampp\mysql\bin\mysql.exe -u root venusep < venusep_migration_17.sql
--
--  It ends by listing every admin with its role, so check that list.
--
--  WHAT CHANGES
--    Offices: each office belongs to a group (venues.office_group):
--      Bahay Alumni = bahay_alumni; USeP Venues and USeP Hostel = cedu.
--    Admins: exactly one super admin (users.is_super_admin, at most one is
--      enforced by a unique key, and a super admin is always active); every
--      other admin belongs to one group (users.office_group).
--      users.handed_over_at marks a super admin who handed over: suspended for good.
--    Staff: staff.both_cedu_offices = ticked for both USeP Venues and USeP Hostel
--      (only counts when the staff member's office is one of those two).
--    Booking notifications go to the office's staff and the group's normal
--      admins, never to the super admin (sp_notify_venue_staff).
--    A new email kind for the super admin handover.
--    Starting accounts:
--      admin@gmail.com = super admin;
--      francishubero@gmail.com = Bahay Alumni normal admin;
--      cedu.admin@gmail.com, "CEDU Admin", NEW = USeP CEDU normal admin.
--        Its temporary password is ceduadmin123. Change it at once; it sets
--        up two-step verification at first sign-in, like every admin.
--      Any other admin keeps no group (sees nothing) until Aron decides.
--
--  ROLLBACK: restore the backup taken before running this. Or, by hand:
--      ALTER TABLE users DROP CONSTRAINT chk_users_super_admin, DROP CONSTRAINT chk_users_office_group,
--          DROP INDEX uq_users_one_super_admin, DROP COLUMN super_admin_marker,
--          DROP COLUMN is_super_admin, DROP COLUMN office_group, DROP COLUMN handed_over_at;
--      ALTER TABLE venues DROP COLUMN office_group;
--      ALTER TABLE staff DROP COLUMN both_cedu_offices;
--      then run sp_notify_venue_staff from venusep_migration_12.sql again.
--  The CEDU Admin account stays unless you delete it.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- the two groups ----
ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS office_group ENUM('bahay_alumni', 'cedu') NULL;

UPDATE venues SET office_group = 'bahay_alumni' WHERE venue_code = 'BAHAY-ALUMNI' AND office_group IS NULL;
UPDATE venues SET office_group = 'cedu' WHERE venue_code IN ('USEP-VENUES', 'USEP-HOSTEL') AND office_group IS NULL;

-- ---- admins: one super admin, the rest one group each ----
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS is_super_admin BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS office_group ENUM('bahay_alumni', 'cedu') NULL,   -- a normal admin's group
    ADD COLUMN IF NOT EXISTS handed_over_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS super_admin_marker TINYINT AS (IF(is_super_admin, 1, NULL)) PERSISTENT,
    ADD UNIQUE KEY IF NOT EXISTS uq_users_one_super_admin (super_admin_marker);

-- a super admin is an active admin with no group; only an admin has a group
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_super_admin;
ALTER TABLE users ADD CONSTRAINT chk_users_super_admin
    CHECK (is_super_admin = 0 OR (account_type = 'admin' AND is_active = 1 AND office_group IS NULL));
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_office_group;
ALTER TABLE users ADD CONSTRAINT chk_users_office_group
    CHECK (office_group IS NULL OR account_type = 'admin');

-- ---- staff: Venues and Hostel both, set per person ----
ALTER TABLE staff
    ADD COLUMN IF NOT EXISTS both_cedu_offices BOOLEAN NOT NULL DEFAULT FALSE;

-- ---- the starting accounts (only the first time: a handover is never undone) ----
SET @has_super_admin = (SELECT COUNT(*) FROM users WHERE is_super_admin = 1 OR handed_over_at IS NOT NULL);
UPDATE users SET is_super_admin = 1, office_group = NULL
 WHERE email = 'admin@gmail.com' AND account_type = 'admin' AND is_active = 1 AND @has_super_admin = 0;

UPDATE users SET office_group = 'bahay_alumni'
 WHERE email = 'francishubero@gmail.com' AND account_type = 'admin'
   AND is_super_admin = 0 AND handed_over_at IS NULL AND office_group IS NULL;

INSERT IGNORE INTO users (email, username, password_hash, account_type, office_group)
    VALUES ('cedu.admin@gmail.com', 'ceduadmin',
            '$argon2id$v=19$m=65536,t=4,p=1$dE5jbFR5bkZDT2NFanRaOA$toynXxuMDtm9GKwzIWpEFNSBxQRArtbw0lUuNOGViE0',
            'admin', 'cedu');
INSERT IGNORE INTO staff (user_id, venue_id, full_name)
    SELECT id, NULL, 'CEDU Admin' FROM users WHERE email = 'cedu.admin@gmail.com' AND account_type = 'admin';

-- ---- the email both sides of a handover get ----
ALTER TABLE email_outbox
    MODIFY kind ENUM('walkin_booking','walkin_receipt','receipt_copy','password_reset',
                     'booking_approved','booking_rejected','payment_due','payment_overdue','booking_cancelled',
                     'super_admin_handover') NOT NULL;

DELIMITER $$

-- A booking's news: that office's staff (a CEDU staff member ticked for both
-- offices gets both) and its group's normal admins. Never the super admin.
DROP PROCEDURE IF EXISTS sp_notify_venue_staff$$
CREATE PROCEDURE sp_notify_venue_staff (
    IN p_booking_id BIGINT UNSIGNED, IN p_kind VARCHAR(40),
    IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    DECLARE v_venue BIGINT UNSIGNED;
    DECLARE v_group VARCHAR(20);
    SELECT r.venue_id, v.office_group INTO v_venue, v_group
      FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN venues v ON v.id = r.venue_id
     WHERE b.id = p_booking_id;
    INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
    SELECT u.id, p_kind, p_booking_id, p_actor_user_id, p_detail
      FROM users u
      LEFT JOIN staff s ON s.user_id = u.id
      LEFT JOIN venues mine ON mine.id = s.venue_id
     WHERE u.is_active = 1 AND u.id <> COALESCE(p_actor_user_id, 0)
       AND ((u.account_type = 'admin' AND u.is_super_admin = 0 AND u.office_group = v_group)
            OR (u.account_type = 'staff'
                AND (s.venue_id = v_venue
                     OR (s.both_cedu_offices = 1 AND mine.office_group = 'cedu' AND v_group = 'cedu'))));
END$$

DELIMITER ;

-- ---- check: every admin and its role ----
SELECT u.email,
       CASE WHEN u.is_super_admin THEN 'super admin'
            WHEN u.handed_over_at IS NOT NULL THEN 'former super admin (suspended)'
            WHEN u.office_group IS NULL THEN 'NO GROUP: decide'
            ELSE CONCAT('normal admin, ', u.office_group) END AS role,
       IF(u.is_active, 'active', 'suspended') AS state
  FROM users u
 WHERE u.account_type = 'admin'
 ORDER BY u.is_super_admin DESC, u.office_group, u.email;
