-- ============================================================================
--  VENUSeP — MIGRATION 18: employee numbers, the discounted price rounds up (decided 2026-11-05).
--  Run against the EXISTING database your site uses, after 17. Safe to re-run
--  (idempotent): a number already given is never changed, a stored amount never moves.
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
--  line that must read "OK: every staff and admin account has an employee number".
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
--
--  ROLLBACK: restore the backup taken before running this, into an emptied database:
--      C:\xamp5\mysql\bin\mysql.exe -u root -e "DROP DATABASE venusep_laravel; CREATE DATABASE venusep_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < %USERPROFILE%\venusep_laravel_before_m18.sql
--  Or, by hand:
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

-- ---- check: every staff and admin account and its number ----
SELECT u.email, u.account_type, s.employee_no
  FROM users u LEFT JOIN staff s ON s.user_id = u.id
 WHERE u.account_type IN ('admin', 'staff')
 ORDER BY s.employee_no;

SELECT IF(COUNT(*) = 0, 'OK: every staff and admin account has an employee number',
          CONCAT('MISSING: ', COUNT(*), ' account(s) with no employee number')) AS employee_no_check
  FROM users u LEFT JOIN staff s ON s.user_id = u.id
 WHERE u.account_type IN ('admin', 'staff') AND (s.employee_no IS NULL OR s.employee_no = '');
