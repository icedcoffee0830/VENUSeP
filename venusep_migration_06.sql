-- ============================================================================
--  VENUSeP — MIGRATION 06: same-day hostel check-in (decided 2026-10-27)
--  Run against an EXISTING venusep database. Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_06.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  venusep_schema.sql carries the same definitions, so a FRESH build needs
--  nothing from this file. This exists only to bring an already-created
--  database up to date without rebuilding it.
--
--  WHAT CHANGES
--    A hostel guest may now book a bed for TODAY (customer/booking-submit.php
--    dropped the 12-hour rule for the hostel). While refunds are ON a stay
--    normally waits for the POS from CEDU before it can be paid — there is no
--    time for that on the day itself, so a same-day stay is paid in CASH at the
--    front desk on arrival, by the end of its check-in day:
--      1. fn_payment_deadline: a pre-pay hostel stay whose day-before deadline
--         has passed is due at the END of its check-in day (was: 2 PM, which
--         expired a booking made after 2 PM the moment it was made).
--      2. sp_approve_booking: a pre-pay hostel stay whose check-in is today
--         (or already here) is approved straight to await_cash / cash,
--         skipping the POS. Later stays still go through the POS as before.
--  Venue bookings are untouched.
-- ============================================================================

-- this file stores a timeline note with an em dash: read it as UTF-8 however
-- it is run, with no extra flag needed
SET NAMES utf8mb4;

DELIMITER $$

DROP FUNCTION IF EXISTS fn_payment_deadline$$
CREATE FUNCTION fn_payment_deadline(p_booking_id BIGINT UNSIGNED)
RETURNS DATETIME
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_prepay BOOLEAN;
    DECLARE v_type VARCHAR(20);
    DECLARE v_first DATE;
    DECLARE v_last DATE;
    DECLARE v_start TIME DEFAULT '00:00:00';
    DECLARE v_grace INT DEFAULT 3;
    DECLARE v_deadline DATETIME;

    SELECT refunds_allowed INTO v_prepay FROM bookings WHERE id = p_booking_id;
    SET v_first = fn_booking_first_day(p_booking_id);
    SET v_last  = fn_booking_last_day(p_booking_id);
    IF v_first IS NULL THEN
        RETURN NULL;
    END IF;

    IF v_prepay THEN
        SET v_deadline = TIMESTAMP(DATE_SUB(v_first, INTERVAL 1 DAY), '23:59:59');
        IF v_deadline <= NOW() THEN
            SELECT booking_type INTO v_type FROM bookings WHERE id = p_booking_id;
            IF v_type = 'venue' THEN
                SELECT COALESCE(MIN(start_time), '00:00:00') INTO v_start
                  FROM venue_booking_slots WHERE booking_id = p_booking_id AND slot_date = v_first;
                SET v_deadline = TIMESTAMP(v_first, v_start);
            ELSE
                SET v_deadline = TIMESTAMP(v_first, '23:59:59');
            END IF;
        END IF;
    ELSE
        SELECT CAST(setting_value AS SIGNED) INTO v_grace
          FROM system_settings WHERE setting_key = 'postpay_grace_days';
        SET v_deadline = TIMESTAMP(DATE_ADD(v_last, INTERVAL v_grace DAY), '23:59:59');
    END IF;
    RETURN v_deadline;
END$$

DROP PROCEDURE IF EXISTS sp_approve_booking$$
CREATE PROCEDURE sp_approve_booking (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_method VARCHAR(10);
    DECLARE v_status VARCHAR(40);

    SELECT booking_type, refunds_allowed, payment_method INTO v_type, v_prepay, v_method
      FROM bookings WHERE id = p_booking_id AND reservation_status = 'pending';
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a pending booking can be approved.';
    END IF;

    IF v_type = 'hostel' AND v_prepay AND fn_booking_first_day(p_booking_id) <= CURDATE() THEN
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
