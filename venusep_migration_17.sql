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
--    Office contact info (phase E): each office's phone, email and location live in
--      the existing venues.contact_phone / contact_email / address columns; empty
--      ones get placeholder text (confirm with each office before go-live).
--    Blocks (phase E): customer_office_blocks, one row per block of a customer at
--      an office (reason, who, when; lifting records who, when and why). While a
--      block is on, no new booking for that customer at that office is accepted
--      (trg_bookings_block_ins), and a pending request still blocked when it is
--      due to start is cancelled by sp_expire_due_bookings (with a History note);
--      the customer is emailed (email kind booking_block_cancelled).
--    Refund switch per office (phase F): venues.refunds_enabled, changed by the super
--      admin only. Bahay Alumni and USeP Venues: on = pay before + refunds, off = pay
--      after + no refunds. USeP Hostel always pays before; its switch is refunds only.
--      The first run copies the old site-wide value (system_settings.refunds_enabled,
--      kept but no longer read) into every office, so nothing changes on day one.
--      Existing bookings keep their own rule: they already store it (refunds_allowed,
--      pay_to_use, set when made), so nothing is backfilled.
--    Counter accounts (phase G): customers.counter_venue_id = the office whose counter
--      made the account (NULL = the customer signed up themselves). Only a normal admin
--      of that office's group may correct its email, and only while it has never been
--      signed into (users.last_login_at NULL) and is not a Google account (google_sub
--      NULL); each correction is kept in customer_email_fixes (old, new, reason, who,
--      when). New email kind counter_welcome: the welcome email of such an account.
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
--      DROP TRIGGER trg_bookings_block_ins; DROP FUNCTION fn_customer_blocked; DROP TABLE customer_office_blocks;
--      then run sp_expire_due_bookings from venusep_migration_16.sql again.
--      ALTER TABLE venues DROP COLUMN refunds_enabled; (the old site-wide row is still there)
--      DROP TABLE customer_email_fixes;
--      ALTER TABLE customers DROP FOREIGN KEY fk_customers_counter_venue, DROP COLUMN counter_venue_id;
--      (The office contact text and the new email kind can stay: nothing old reads them.)
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
                     'super_admin_handover','booking_block_cancelled','counter_welcome') NOT NULL;

-- ---- each office's contact info: placeholders where none is set (never overwrites) ----
UPDATE venues SET address = 'USeP Tagum–Mabini Campus, Apokon, Tagum City' WHERE address IS NULL OR address = '';
UPDATE venues SET contact_phone = '0900 000 0000' WHERE contact_phone IS NULL OR contact_phone = '';
UPDATE venues SET contact_email = CONCAT(LOWER(REPLACE(venue_code, '-', '.')), '@example.com')
 WHERE contact_email IS NULL OR contact_email = '';

-- ---- the refund switch, one per office (DB-DECISIONS 28) ----
-- Only the first run copies the old site-wide value: a re-run never undoes the super admin's changes.
SET @refunds_first = (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'venues' AND COLUMN_NAME = 'refunds_enabled');
ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS refunds_enabled BOOLEAN NOT NULL DEFAULT FALSE;
UPDATE venues
   SET refunds_enabled = COALESCE((SELECT s.setting_value = '1' FROM system_settings s WHERE s.setting_key = 'refunds_enabled'), FALSE)
 WHERE @refunds_first = 1;
UPDATE system_settings
   SET description = 'No longer read (migration 17): each office has its own switch, venues.refunds_enabled.'
 WHERE setting_key = 'refunds_enabled';

-- ---- a customer blocked at one office (DB-DECISIONS 28): one active block per office ----
CREATE TABLE IF NOT EXISTS customer_office_blocks (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id        BIGINT UNSIGNED NOT NULL,
    venue_id           BIGINT UNSIGNED NOT NULL,
    reason             VARCHAR(500) NOT NULL,            -- never shown to the customer
    blocked_by_user_id BIGINT UNSIGNED NULL,
    blocked_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lifted_at          DATETIME NULL,                    -- NULL = the block is on
    lifted_by_user_id  BIGINT UNSIGNED NULL,
    lift_reason        VARCHAR(500) NULL,
    active_marker      TINYINT AS (IF(lifted_at IS NULL, 1, NULL)) PERSISTENT,
    UNIQUE KEY uq_customer_office_blocks_active (customer_id, venue_id, active_marker),
    KEY ix_customer_office_blocks_venue (venue_id, lifted_at),
    CONSTRAINT fk_customer_office_blocks_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_customer_office_blocks_venue FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_customer_office_blocks_by FOREIGN KEY (blocked_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_customer_office_blocks_lifted_by FOREIGN KEY (lifted_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---- accounts made at the counter, and their email corrections (DB-DECISIONS 28) ----
ALTER TABLE customers
    ADD COLUMN IF NOT EXISTS counter_venue_id BIGINT UNSIGNED NULL;   -- the office whose counter made it; NULL = signed up themselves
ALTER TABLE customers
    ADD CONSTRAINT fk_customers_counter_venue FOREIGN KEY IF NOT EXISTS (counter_venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS customer_email_fixes (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id      BIGINT UNSIGNED NOT NULL,
    old_email        VARCHAR(190) NOT NULL,
    new_email        VARCHAR(190) NOT NULL,
    reason           VARCHAR(500) NOT NULL,
    fixed_by_user_id BIGINT UNSIGNED NULL,
    fixed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_customer_email_fixes_customer (customer_id),
    CONSTRAINT fk_customer_email_fixes_customer FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_customer_email_fixes_by FOREIGN KEY (fixed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

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

-- Is this customer blocked right now at this room's office? The one copy of the rule:
-- the trigger and procedure below, and the app's booking reads, all ask it.
DROP FUNCTION IF EXISTS fn_customer_blocked$$
CREATE FUNCTION fn_customer_blocked(p_customer_id BIGINT UNSIGNED, p_room_id BIGINT UNSIGNED)
RETURNS BOOLEAN
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN EXISTS (SELECT 1 FROM customer_office_blocks k JOIN rooms r ON r.venue_id = k.venue_id
                    WHERE r.id = p_room_id AND k.customer_id = p_customer_id AND k.lifted_at IS NULL);
END$$

-- No new booking for a customer blocked at the room's office, online or at the counter.
DROP TRIGGER IF EXISTS trg_bookings_block_ins$$
CREATE TRIGGER trg_bookings_block_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    IF fn_customer_blocked(NEW.customer_id, NEW.room_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This customer is blocked at this office, so no new booking can be made there.';
    END IF;
END$$

-- Migration 16's procedure, plus one step: a pending request still blocked when it is
-- due to start is cancelled (not just expired), and its History line says why.
DROP PROCEDURE IF EXISTS sp_expire_due_bookings$$
CREATE PROCEDURE sp_expire_due_bookings ()
BEGIN
    SET @venusep_actor_user_id = NULL;

    SET @venusep_action_note = 'Event over; payment window opened.';
    UPDATE bookings
        SET payment_status = CASE WHEN payment_method = 'cash' THEN 'await_cash' ELSE 'await_gcash' END
        WHERE refunds_allowed = FALSE AND pay_to_use = FALSE
          AND payment_status = 'await_event'
          AND reservation_status IN ('approved','completed')
          AND fn_booking_last_day(id) < CURDATE();

    SET @venusep_action_note = 'Cancelled automatically: the customer was still blocked at this office when the booking was due to start.';
    UPDATE bookings
        SET reservation_status = 'cancelled', cancelled_at = NOW()
        WHERE reservation_status = 'pending'
          AND fn_request_expiry(id, booking_type) <= NOW()
          AND fn_customer_blocked(customer_id, room_id);

    SET @venusep_action_note = 'Not approved before the booking date; the request expired.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE reservation_status = 'pending'
          AND fn_request_expiry(id, booking_type) <= NOW();

    -- A guest who checked in and has not paid (a reversed payment) is never released:
    -- that would quietly erase the debt. Staff settle it in person.
    SET @venusep_action_note = 'Not paid by the deadline; the hold was released.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE (refunds_allowed = TRUE OR pay_to_use = TRUE)
          AND reservation_status = 'approved'
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW()
          AND NOT EXISTS (SELECT 1 FROM hostel_booking_details h
                           WHERE h.booking_id = bookings.id AND h.checked_in = 1);

    SET @venusep_action_note = 'Payment not received within the post-event window.';
    UPDATE bookings
        SET payment_status = 'overdue'
        WHERE refunds_allowed = FALSE AND pay_to_use = FALSE
          AND reservation_status IN ('approved','completed')
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    -- Paid and finished = Completed (Aron 2026-11-01), a paid no-show included.
    SET @venusep_action_note = 'Paid and finished.';
    UPDATE bookings
        SET reservation_status = 'completed'
        WHERE reservation_status = 'approved'
          AND payment_status IN ('confirmed','paid_cash','refund_denied')
          AND fn_booking_last_day(id) < CURDATE();

    SET @venusep_action_note = NULL;
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
