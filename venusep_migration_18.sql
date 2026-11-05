-- ============================================================================
--  VENUSeP — MIGRATION 18: employee numbers, the discounted price rounds up, deleted rooms,
--  staff per hall, covers and moves between groups (decided 2026-11-05).
--  Run against the EXISTING database your site uses, after 17. Safe to re-run
--  (idempotent): a number already given is never changed, a stored amount never moves, and a
--  hall an admin took from a staff member is not given back (the hall backfill runs once).
--
--  THE DATABASE NAME in these commands must be the one your site uses: DB_DATABASE in
--  laravel\.env. Aron's site uses venusep_laravel, not venusep; a build from SETUP.md
--  uses venusep. XAMPP installed somewhere else means another path to mysql.exe.
--
--      Windows (cmd, not PowerShell), from the folder that holds this file:
--          C:\xamp5\mysql\bin\mysqldump.exe -u root --routines --triggers --events --single-transaction venusep_laravel > %USERPROFILE%\venusep_laravel_before_m18.sql
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_18.sql
--
--  It ends by listing every staff and admin account with its employee number, then one
--  line that must read "OK: every staff and admin account has an employee number". After it:
--    - anyone who had the tick for both CEDU offices (no list = nobody). They keep their own
--      office only: set a cover where they still need the other one (7 days at most);
--    - any hall with no active staff member, then one line: "OK: every hall has a staff
--      member", or "NO STAFF: n hall(s)...". Nothing breaks then: bookings of such a hall
--      reach the group's admin only. Assign someone on Staff Management.
--
--  WHAT CHANGES
--    Employee numbers: every staff and admin account has one, in the format the first
--      ones were given by hand: EMP-<year>-<4 digits>, e.g. EMP-2026-0001. The database
--      gives it (trg_staff_employee_no_ins) when the account's staff row is made, the
--      next number of that year (employee_no_counters keeps the last one per year, so two
--      accounts made at once never get the same); nobody types it, and it never changes
--      (trg_staff_employee_no_upd). staff.employee_no is now required.
--    Accounts made before this: an admin with no staff row gets one (its name = its
--      username, as the profile page did on first save); each account with no number
--      gets the next free one of the year its staff row was made. Numbers typed by
--      hand earlier (EMP-2021-0043) are kept.
--    The USeP discount: when a booking is approved with it, the final price (what the
--      customer pays) rounds UP to the whole peso, e.g. 1,234.20 -> 1,235.00, and the
--      discount is the rest (room price - final price), so the numbers always add up.
--      Done in sp_approve_booking, which every approval goes through (staff approving a
--      request, and the counter). Bookings approved before this keep their amounts.
--    Deleted rooms: deleting a hall or hostel room sets rooms.deleted_at / deleted_by_user_id
--      (and is_active = 0, chk_rooms_deleted_inactive). The row stays, hidden, so past bookings,
--      receipts, History and reports keep its name. No undo, none while a booking is pending,
--      coming up or going on (fn_room_live_bookings, trg_rooms_deleted_upd), and no new booking
--      on it (trg_bookings_room_deleted_ins). Its name is free again: uq_rooms_venue_name now
--      counts undeleted rooms only (rooms.live_name_marker).
--    Staff per hall: staff_halls, one row per staff member and hall of their own office
--      (trg_staff_halls_ins). The hostel is one unit: its staff have no rows. Every existing
--      staff member gets every hall of their office, so nothing narrows on day one; a new or
--      moved one gets them too (trg_staff_halls_new_ins, trg_staff_halls_follow_upd), and a
--      hall moved to another office goes to its staff (trg_rooms_halls_follow_upd).
--      Booking news goes to the staff of that hall and the group's normal admins
--      (fn_staff_on_room, sp_notify_venue_staff).
--    Covers: staff_covers replaces the tick for both CEDU offices (staff.both_cedu_offices stays
--      as a record of who had it, no longer read). Moves between groups: staff_moves.
--
--  ROLLBACK: restore the backup taken before running this, into an emptied database:
--      C:\xamp5\mysql\bin\mysql.exe -u root -e "DROP DATABASE venusep_laravel; CREATE DATABASE venusep_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < %USERPROFILE%\venusep_laravel_before_m18.sql
--  Or, by hand:
--      DROP TRIGGER trg_rooms_deleted_upd; DROP TRIGGER trg_bookings_room_deleted_ins;
--      DROP TRIGGER trg_staff_halls_ins; DROP TRIGGER trg_staff_halls_new_ins;
--      DROP TRIGGER trg_staff_halls_follow_upd; DROP TRIGGER trg_rooms_halls_follow_upd;
--      DROP FUNCTION fn_room_live_bookings; DROP FUNCTION fn_staff_on_room;
--      then run sp_notify_venue_staff from venusep_migration_17.sql again.
--      DROP TABLE staff_moves; DROP TABLE staff_covers; DROP TABLE staff_halls;
--      (rooms.deleted_at and its two companions can stay only while no room is deleted: a
--      deleted room would show again to the old code. Restore the backup if any was deleted.)
--      DROP TRIGGER trg_staff_employee_no_ins; DROP TRIGGER trg_staff_employee_no_upd;
--      DROP TABLE employee_no_counters;
--      ALTER TABLE staff MODIFY employee_no VARCHAR(80) NULL;
--      (The numbers given and the admins' staff rows can stay: nothing old minds them.)
--      then run sp_approve_booking from venusep_migration_11.sql again.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- employee numbers ----
-- an admin with no staff row yet (the seeded one): give it one, so it can hold the number
INSERT IGNORE INTO staff (user_id, venue_id, full_name)
    SELECT u.id, NULL, u.username FROM users u
     WHERE u.account_type = 'admin' AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.id);

-- every row with no number: the next free numbers of the year the row was made, oldest first
UPDATE staff s
  JOIN (SELECT x.user_id, CONCAT('EMP-', x.yr, '-', LPAD(x.seq, GREATEST(4, LENGTH(x.seq)), '0')) AS employee_no
          FROM (SELECT t.user_id, YEAR(t.created_at) AS yr,
                       COALESCE((SELECT MAX(CAST(SUBSTRING(e.employee_no, 10) AS UNSIGNED)) FROM staff e
                                  WHERE e.employee_no LIKE CONCAT('EMP-', YEAR(t.created_at), '-%')), 0)
                       + ROW_NUMBER() OVER (PARTITION BY YEAR(t.created_at) ORDER BY t.created_at, t.user_id) AS seq
                  FROM staff t
                 WHERE t.employee_no IS NULL OR t.employee_no = '') x) n ON n.user_id = s.user_id
   SET s.employee_no = n.employee_no;

-- DEFAULT '': a strict server refuses a missing value before the trigger below can fill it
ALTER TABLE staff MODIFY employee_no VARCHAR(80) NOT NULL DEFAULT '';

-- the last number given each year; its row lock makes two accounts made at the same moment
-- take turns instead of the same number
CREATE TABLE IF NOT EXISTS employee_no_counters (
    year_no SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_no INT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO employee_no_counters (year_no, last_no)
    SELECT CAST(SUBSTRING(employee_no, 5, 4) AS UNSIGNED), MAX(CAST(SUBSTRING(employee_no, 10) AS UNSIGNED))
      FROM staff WHERE employee_no REGEXP '^EMP-[0-9]{4}-[0-9]+$'
     GROUP BY SUBSTRING(employee_no, 5, 4)
    ON DUPLICATE KEY UPDATE last_no = GREATEST(last_no, VALUES(last_no));

DELIMITER $$

-- A new staff row gets the next number of this year (employee_no_counters). A number
-- already given (a backup being restored) is kept, and an INSERT ... ON DUPLICATE KEY
-- UPDATE of a row that exists takes none (it updates that row instead).
DROP TRIGGER IF EXISTS trg_staff_employee_no_ins$$
CREATE TRIGGER trg_staff_employee_no_ins BEFORE INSERT ON staff
FOR EACH ROW
BEGIN
    DECLARE v_no INT UNSIGNED;
    IF (NEW.employee_no IS NULL OR NEW.employee_no = '')
       AND NOT EXISTS (SELECT 1 FROM staff WHERE user_id = NEW.user_id) THEN
        INSERT INTO employee_no_counters (year_no, last_no) VALUES (YEAR(CURDATE()), 1)
            ON DUPLICATE KEY UPDATE last_no = last_no + 1;
        SELECT last_no INTO v_no FROM employee_no_counters WHERE year_no = YEAR(CURDATE());
        SET NEW.employee_no = CONCAT('EMP-', YEAR(CURDATE()), '-', LPAD(v_no, GREATEST(4, LENGTH(v_no)), '0'));
    END IF;
END$$

-- Nobody edits an employee number, the app included.
DROP TRIGGER IF EXISTS trg_staff_employee_no_upd$$
CREATE TRIGGER trg_staff_employee_no_upd BEFORE UPDATE ON staff
FOR EACH ROW
BEGIN
    IF NOT (NEW.employee_no <=> OLD.employee_no) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An employee number is given once and never changes.';
    END IF;
END$$

-- Migration 11's procedure, plus one step first: a USeP discount's final price rounds up
-- to the whole peso, and the discount is the rest (Aron 2026-11-05).
DROP PROCEDURE IF EXISTS sp_approve_booking$$
CREATE PROCEDURE sp_approve_booking (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_pay_to_use BOOLEAN;
    DECLARE v_method VARCHAR(10);
    DECLARE v_status VARCHAR(40);

    SELECT booking_type, refunds_allowed, pay_to_use, payment_method INTO v_type, v_prepay, v_pay_to_use, v_method
      FROM bookings WHERE id = p_booking_id AND reservation_status = 'pending';
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a pending booking can be approved.';
    END IF;

    -- CEIL in both: the result is the same whether MariaDB assigns left to right or all at once.
    UPDATE bookings
        SET total_amount = CEIL(total_amount),
            discount_amount = room_price - CEIL(total_amount)
        WHERE id = p_booking_id AND discount_amount > 0;

    IF v_pay_to_use THEN
        -- Payment opens at once: GCash from Pay now, or cash at the front desk.
        SET @venusep_actor_user_id = p_actor_user_id;
        SET @venusep_action_note = 'Approved; pay before check-in (GCash online, or cash at the front desk).';
        UPDATE bookings
            SET reservation_status = 'approved',
                payment_status = IF(v_method = 'cash', 'await_cash', 'await_gcash'),
                current_deadline_at = fn_payment_deadline(p_booking_id),
                approved_at = COALESCE(approved_at, NOW()),
                updated_by_user_id = p_actor_user_id
            WHERE id = p_booking_id;
        SET @venusep_actor_user_id = NULL;
        SET @venusep_action_note = NULL;
    ELSEIF v_type = 'hostel' AND v_prepay AND fn_booking_first_day(p_booking_id) <= CURDATE() THEN
        SET @venusep_actor_user_id = p_actor_user_id;
        SET @venusep_action_note = 'Approved; same-day stay — pay in cash at the front desk on arrival.';
        UPDATE bookings
            SET reservation_status = 'approved', payment_status = 'await_cash', payment_method = 'cash',
                current_deadline_at = fn_payment_deadline(p_booking_id),
                approved_at = COALESCE(approved_at, NOW()),
                updated_by_user_id = p_actor_user_id
            WHERE id = p_booking_id;
        SET @venusep_actor_user_id = NULL;
        SET @venusep_action_note = NULL;
    ELSEIF v_type = 'hostel' AND v_prepay THEN
        CALL sp_start_await_pos(p_booking_id, p_actor_user_id);
    ELSE
        IF v_prepay THEN
            SET v_status = IF(v_method = 'cash', 'await_cash', 'await_gcash');
        ELSE
            SET v_status = 'await_event';
        END IF;
        SET @venusep_actor_user_id = p_actor_user_id;
        SET @venusep_action_note = IF(v_prepay, 'Approved; pay before the event.', 'Approved; pay after the event.');
        UPDATE bookings
            SET reservation_status = 'approved', payment_status = v_status,
                current_deadline_at = fn_payment_deadline(p_booking_id),
                approved_at = COALESCE(approved_at, NOW()),
                updated_by_user_id = p_actor_user_id
            WHERE id = p_booking_id;
        SET @venusep_actor_user_id = NULL;
        SET @venusep_action_note = NULL;
    END IF;
END$$

DELIMITER ;

-- ======== phase M (2026-11-05): deleted rooms, staff per hall, covers, moves ========

-- ---- a deleted hall or hostel room stays as a hidden row, so records keep its name ----
ALTER TABLE rooms
    ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS deleted_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS live_name_marker TINYINT AS (IF(deleted_at IS NULL, 1, NULL)) PERSISTENT;
ALTER TABLE rooms
    ADD CONSTRAINT fk_rooms_deleted_by FOREIGN KEY IF NOT EXISTS (deleted_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;
-- a deleted room is never bookable again
ALTER TABLE rooms DROP CONSTRAINT IF EXISTS chk_rooms_deleted_inactive;
ALTER TABLE rooms ADD CONSTRAINT chk_rooms_deleted_inactive CHECK (deleted_at IS NULL OR is_active = 0);
-- a deleted room's name is free for a new room of the same office
ALTER TABLE rooms
    DROP INDEX IF EXISTS uq_rooms_venue_name,
    ADD UNIQUE KEY uq_rooms_venue_name (venue_id, name, live_name_marker);

-- ---- staff per hall: a staff member covers one or more halls of their own office ----
-- (the hostel is one unit: hostel staff cover the whole hostel and have no rows here)
SET @staff_halls_first = (SELECT COUNT(*) = 0 FROM information_schema.TABLES
                           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff_halls');
CREATE TABLE IF NOT EXISTS staff_halls (
    user_id             BIGINT UNSIGNED NOT NULL,
    room_id             BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NULL,
    assigned_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, room_id),
    KEY ix_staff_halls_room (room_id),
    CONSTRAINT fk_staff_halls_staff FOREIGN KEY (user_id) REFERENCES staff(user_id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_halls_room FOREIGN KEY (room_id) REFERENCES rooms(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_halls_by FOREIGN KEY (assigned_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- the first run only: every staff member gets every hall of their office, so nothing
-- narrows on day one (a re-run never gives back a hall an admin took away)
INSERT IGNORE INTO staff_halls (user_id, room_id)
    SELECT s.user_id, r.id
      FROM staff s
      JOIN users u ON u.id = s.user_id AND u.account_type = 'staff'
      JOIN rooms r ON r.venue_id = s.venue_id AND r.room_type = 'event' AND r.deleted_at IS NULL
     WHERE @staff_halls_first = 1;

-- ---- covers: a staff member also handles another hall or office for a while (max 7 days) ----
CREATE TABLE IF NOT EXISTS staff_covers (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,        -- the staff member covering
    venue_id            BIGINT UNSIGNED NOT NULL,        -- the office covered
    room_id             BIGINT UNSIGNED NULL,            -- one hall of it; NULL = the whole office (the hostel always)
    starts_at           DATETIME NOT NULL,
    ends_at             DATETIME NOT NULL,               -- it ends by itself: nothing runs at this time
    reason              VARCHAR(500) NOT NULL,
    created_by_user_id  BIGINT UNSIGNED NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    approved_at         DATETIME NULL,                   -- NULL = waiting for the other group's admin
    approved_by_user_id BIGINT UNSIGNED NULL,            --   (expires unanswered at starts_at)
    rejected_at         DATETIME NULL,
    rejected_by_user_id BIGINT UNSIGNED NULL,
    reject_reason       VARCHAR(500) NULL,
    ended_at            DATETIME NULL,                   -- ended early by an admin
    ended_by_user_id    BIGINT UNSIGNED NULL,
    KEY ix_staff_covers_user (user_id, ends_at),
    KEY ix_staff_covers_venue (venue_id, ends_at),
    CONSTRAINT chk_staff_covers_week CHECK (ends_at > starts_at AND ends_at <= starts_at + INTERVAL 7 DAY),
    CONSTRAINT fk_staff_covers_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_covers_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_covers_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_covers_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_staff_covers_approved_by FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_staff_covers_rejected_by FOREIGN KEY (rejected_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_staff_covers_ended_by FOREIGN KEY (ended_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- a permanent move to the other group: the destination's admin approves (3 days) ----
CREATE TABLE IF NOT EXISTS staff_moves (
    id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id              BIGINT UNSIGNED NOT NULL,
    from_venue_id        BIGINT UNSIGNED NOT NULL,
    to_venue_id          BIGINT UNSIGNED NOT NULL,
    reason               VARCHAR(500) NOT NULL,
    replacements         TEXT NULL,                      -- JSON {"hall id": replacement staff user id}
    requested_by_user_id BIGINT UNSIGNED NULL,
    requested_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,   -- unanswered: expires 3 days later
    decided_at           DATETIME NULL,
    decided_by_user_id   BIGINT UNSIGNED NULL,
    approved             BOOLEAN NULL,                   -- NULL = waiting; 1 = moved; 0 = rejected
    reject_reason        VARCHAR(500) NULL,
    KEY ix_staff_moves_user (user_id),
    CONSTRAINT fk_staff_moves_user FOREIGN KEY (user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_moves_from FOREIGN KEY (from_venue_id) REFERENCES venues(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_moves_to FOREIGN KEY (to_venue_id) REFERENCES venues(id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_staff_moves_requested_by FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_staff_moves_decided_by FOREIGN KEY (decided_by_user_id) REFERENCES users(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- the tick for both CEDU offices (migration 17) is replaced by covers: no longer read.
-- The column and its values stay as a record of who had it (listed at the end).
ALTER TABLE staff MODIFY both_cedu_offices BOOLEAN NOT NULL DEFAULT FALSE
    COMMENT 'No longer read (migration 18): replaced by staff_covers.';

DELIMITER $$

-- A booking still to come or going on: pending, approved and not over yet, or a hostel
-- guest checked in and not out. The one copy of "this room cannot be deleted".
DROP FUNCTION IF EXISTS fn_room_live_bookings$$
CREATE FUNCTION fn_room_live_bookings(p_room_id BIGINT UNSIGNED)
RETURNS INT
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN (SELECT COUNT(*) FROM bookings b
             WHERE b.room_id = p_room_id
               AND (b.reservation_status = 'pending'
                    OR (b.reservation_status = 'approved'
                        AND (fn_booking_last_day(b.id) >= CURDATE()
                             OR EXISTS (SELECT 1 FROM hostel_booking_details h
                                         WHERE h.booking_id = b.id AND h.checked_in = 1 AND h.checked_out = 0)))));
END$$

-- Deleting a room: only with no live booking, and never undone.
DROP TRIGGER IF EXISTS trg_rooms_deleted_upd$$
CREATE TRIGGER trg_rooms_deleted_upd BEFORE UPDATE ON rooms
FOR EACH ROW
BEGIN
    IF OLD.deleted_at IS NOT NULL AND NOT (NEW.deleted_at <=> OLD.deleted_at) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A deleted room stays deleted.';
    END IF;
    IF OLD.deleted_at IS NULL AND NEW.deleted_at IS NOT NULL AND fn_room_live_bookings(OLD.id) > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This room has bookings still to come or going on, so it cannot be deleted.';
    END IF;
END$$

-- No new booking on a deleted room, online or at the counter.
DROP TRIGGER IF EXISTS trg_bookings_room_deleted_ins$$
CREATE TRIGGER trg_bookings_room_deleted_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM rooms WHERE id = NEW.room_id AND deleted_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This room was deleted, so it cannot be booked.';
    END IF;
END$$

-- A staff member is given only halls of their own office (the hostel has none: one unit).
DROP TRIGGER IF EXISTS trg_staff_halls_ins$$
CREATE TRIGGER trg_staff_halls_ins BEFORE INSERT ON staff_halls
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM staff s JOIN rooms r ON r.venue_id = s.venue_id
                    WHERE s.user_id = NEW.user_id AND r.id = NEW.room_id AND r.room_type = 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A staff member is given only halls of their own office.';
    END IF;
END$$

-- A new staff member starts with every hall of their office (an admin narrows it later).
DROP TRIGGER IF EXISTS trg_staff_halls_new_ins$$
CREATE TRIGGER trg_staff_halls_new_ins AFTER INSERT ON staff
FOR EACH ROW
BEGIN
    INSERT IGNORE INTO staff_halls (user_id, room_id)
        SELECT NEW.user_id, r.id FROM rooms r
         WHERE r.venue_id = NEW.venue_id AND r.room_type = 'event' AND r.deleted_at IS NULL
           AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff';
END$$

-- Moved to another office: the old office's halls go, and every hall of the new one comes.
DROP TRIGGER IF EXISTS trg_staff_halls_follow_upd$$
CREATE TRIGGER trg_staff_halls_follow_upd AFTER UPDATE ON staff
FOR EACH ROW
BEGIN
    IF NOT (NEW.venue_id <=> OLD.venue_id) THEN
        DELETE h FROM staff_halls h JOIN rooms r ON r.id = h.room_id
         WHERE h.user_id = NEW.user_id AND NOT (r.venue_id <=> NEW.venue_id);
        INSERT IGNORE INTO staff_halls (user_id, room_id)
            SELECT NEW.user_id, r.id FROM rooms r
             WHERE r.venue_id = NEW.venue_id AND r.room_type = 'event' AND r.deleted_at IS NULL
               AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff';
    END IF;
END$$

-- A hall moved to another office leaves its old office's staff and goes to the new one's.
DROP TRIGGER IF EXISTS trg_rooms_halls_follow_upd$$
CREATE TRIGGER trg_rooms_halls_follow_upd AFTER UPDATE ON rooms
FOR EACH ROW
BEGIN
    IF NEW.venue_id <> OLD.venue_id THEN
        DELETE h FROM staff_halls h JOIN staff s ON s.user_id = h.user_id
         WHERE h.room_id = NEW.id AND NOT (s.venue_id <=> NEW.venue_id);
        INSERT IGNORE INTO staff_halls (user_id, room_id)
            SELECT s.user_id, NEW.id FROM staff s JOIN users u ON u.id = s.user_id
             WHERE s.venue_id = NEW.venue_id AND u.account_type = 'staff' AND NEW.room_type = 'event';
    END IF;
END$$

-- Is this staff member on this room right now: one of their halls (the whole hostel for
-- hostel staff), or a cover going on. Booking news (below) and the "My halls" lists ask it.
DROP FUNCTION IF EXISTS fn_staff_on_room$$
CREATE FUNCTION fn_staff_on_room(p_user_id BIGINT UNSIGNED, p_room_id BIGINT UNSIGNED)
RETURNS BOOLEAN
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN EXISTS (SELECT 1 FROM staff s JOIN rooms r ON r.id = p_room_id AND r.venue_id = s.venue_id
                    WHERE s.user_id = p_user_id
                      AND (r.room_type = 'hostel'
                           OR EXISTS (SELECT 1 FROM staff_halls h WHERE h.user_id = p_user_id AND h.room_id = r.id)))
        OR EXISTS (SELECT 1 FROM staff_covers c JOIN rooms r ON r.id = p_room_id AND r.venue_id = c.venue_id
                    WHERE c.user_id = p_user_id AND (c.room_id IS NULL OR c.room_id = r.id)
                      AND c.approved_at IS NOT NULL AND c.ended_at IS NULL
                      AND c.starts_at <= NOW() AND c.ends_at > NOW());
END$$

-- Migration 17's procedure, narrowed: a booking's news goes to the staff of its hall (the
-- whole hostel for hostel staff, plus anyone covering it now) and its group's normal admins.
DROP PROCEDURE IF EXISTS sp_notify_venue_staff$$
CREATE PROCEDURE sp_notify_venue_staff (
    IN p_booking_id BIGINT UNSIGNED, IN p_kind VARCHAR(40),
    IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
    SELECT u.id, p_kind, p_booking_id, p_actor_user_id, p_detail
      FROM bookings b
      JOIN rooms r ON r.id = b.room_id
      JOIN venues v ON v.id = r.venue_id
      JOIN users u
     WHERE b.id = p_booking_id AND u.is_active = 1 AND u.id <> COALESCE(p_actor_user_id, 0)
       AND ((u.account_type = 'admin' AND u.is_super_admin = 0 AND u.office_group = v.office_group)
            OR (u.account_type = 'staff' AND fn_staff_on_room(u.id, r.id)));
END$$

DELIMITER ;

-- ---- check: every staff and admin account and its number ----
SELECT u.email, u.account_type, s.employee_no
  FROM users u LEFT JOIN staff s ON s.user_id = u.id
 WHERE u.account_type IN ('admin', 'staff')
 ORDER BY s.employee_no;

SELECT IF(COUNT(*) = 0, 'OK: every staff and admin account has an employee number',
          CONCAT('MISSING: ', COUNT(*), ' account(s) with no employee number')) AS employee_no_check
  FROM users u LEFT JOIN staff s ON s.user_id = u.id
 WHERE u.account_type IN ('admin', 'staff') AND (s.employee_no IS NULL OR s.employee_no = '');

-- ---- check: who had the tick for both CEDU offices (replaced by covers) ----
-- Each keeps their own office and all its halls; an admin sets a cover where needed.
SELECT u.email, s.full_name, v.name AS had_both_cedu_tick_keeps_this_office
  FROM staff s JOIN users u ON u.id = s.user_id LEFT JOIN venues v ON v.id = s.venue_id
 WHERE s.both_cedu_offices = 1 AND u.account_type = 'staff'
 ORDER BY s.full_name;

-- ---- check: every hall (and the hostel, one unit) has at least one active staff member ----
DROP TEMPORARY TABLE IF EXISTS m18_halls_with_no_staff;
CREATE TEMPORARY TABLE m18_halls_with_no_staff AS
    SELECT v.id AS vid, r.id AS rid, v.name AS office, r.name AS hall_with_no_staff
      FROM rooms r JOIN venues v ON v.id = r.venue_id
     WHERE r.room_type = 'event' AND r.deleted_at IS NULL
       AND NOT EXISTS (SELECT 1 FROM staff_halls h JOIN users u ON u.id = h.user_id
                        WHERE h.room_id = r.id AND u.is_active = 1 AND u.account_type = 'staff')
    UNION ALL
    SELECT v.id, 0, v.name, '(the whole hostel)'
      FROM venues v
     WHERE v.venue_type = 'hostel'
       AND NOT EXISTS (SELECT 1 FROM staff s JOIN users u ON u.id = s.user_id
                        WHERE s.venue_id = v.id AND u.is_active = 1 AND u.account_type = 'staff');
SELECT office, hall_with_no_staff FROM m18_halls_with_no_staff ORDER BY vid, rid;
SELECT IF(COUNT(*) = 0, 'OK: every hall has a staff member',
          CONCAT('NO STAFF: ', COUNT(*), ' hall(s) listed above; assign a staff member to each on Staff Management')) AS hall_staff_check
  FROM m18_halls_with_no_staff;
DROP TEMPORARY TABLE m18_halls_with_no_staff;
