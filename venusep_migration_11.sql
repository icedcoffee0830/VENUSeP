-- ============================================================================
--  VENUSeP — MIGRATION 11: the hostel is PAY TO USE (decided 2026-10-27).
--  Run against an EXISTING venusep database, after 10. Safe to re-run.
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_11.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  A hostel guest pays BEFORE using the bed, whatever the refund switch says
--  (venues still follow it, DB-DECISIONS #18):
--    online : approval opens payment at once — GCash from Pay now, or cash at
--             the front desk — and it is due by the END of the check-in day;
--             unpaid by then, the beds are released;
--    counter: the stay is only recorded once the cash is taken
--             (admin/booking-create.php writes it already paid).
--  Check-in is refused until the stay is paid (admin/booking-action.php).
--  The POS and the OR no longer hold anything up.
--
--  bookings.pay_to_use is a SNAPSHOT, like refunds_allowed: every NEW hostel
--  booking gets it (trg_bookings_type_ins), and hostel stays made before this
--  migration keep the policy they were booked under.
-- ============================================================================

-- this file stores text with em dashes (timeline notes, an FAQ answer): read it
-- as UTF-8 however it is run, with no extra flag needed
SET NAMES utf8mb4;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'pay_to_use') = 0,
  'ALTER TABLE bookings ADD COLUMN pay_to_use BOOLEAN NOT NULL DEFAULT FALSE AFTER refunds_allowed',
  'SELECT ''bookings.pay_to_use already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DELIMITER $$

-- A booking's type must match its room's type. Every new hostel booking is
-- pay to use (2026-10-27).
DROP TRIGGER IF EXISTS trg_bookings_type_ins$$
CREATE TRIGGER trg_bookings_type_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_rt VARCHAR(20);
    SELECT room_type INTO v_rt FROM rooms WHERE id = NEW.room_id;
    -- room_type is 'event'/'hostel'; booking_type is 'venue'/'hostel'. A 'venue'
    -- booking belongs on an 'event' room; a 'hostel' booking on a 'hostel' room.
    IF v_rt IS NULL
       OR (NEW.booking_type = 'hostel' AND v_rt <> 'hostel')
       OR (NEW.booking_type = 'venue'  AND v_rt <> 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking type must match the room type.';
    END IF;
    SET NEW.pay_to_use = (NEW.booking_type = 'hostel');
END$$

DROP FUNCTION IF EXISTS fn_payment_deadline$$
CREATE FUNCTION fn_payment_deadline(p_booking_id BIGINT UNSIGNED)
RETURNS DATETIME
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_prepay BOOLEAN;
    DECLARE v_pay_to_use BOOLEAN;
    DECLARE v_type VARCHAR(20);
    DECLARE v_first DATE;
    DECLARE v_last DATE;
    DECLARE v_start TIME DEFAULT '00:00:00';
    DECLARE v_grace INT DEFAULT 3;
    DECLARE v_deadline DATETIME;

    SELECT refunds_allowed, pay_to_use INTO v_prepay, v_pay_to_use FROM bookings WHERE id = p_booking_id;
    SET v_first = fn_booking_first_day(p_booking_id);
    SET v_last  = fn_booking_last_day(p_booking_id);
    IF v_first IS NULL THEN
        RETURN NULL;
    END IF;

    IF v_pay_to_use THEN
        RETURN TIMESTAMP(v_first, '23:59:59');
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

    SET @venusep_action_note = 'Not approved before the booking date; the request expired.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE reservation_status = 'pending'
          AND fn_request_expiry(id, booking_type) <= NOW();

    -- Only a booking STILL WAITING for its money: paid or under-review bookings
    -- are never touched. A pay-to-use hostel stay is due by the end of its
    -- check-in day, so a guest who never arrived and paid loses the beds then.
    SET @venusep_action_note = 'Not paid by the deadline; the hold was released.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE (refunds_allowed = TRUE OR pay_to_use = TRUE)
          AND reservation_status = 'approved'
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = 'Payment not received within the post-event window.';
    UPDATE bookings
        SET payment_status = 'overdue'
        WHERE refunds_allowed = FALSE AND pay_to_use = FALSE
          AND reservation_status IN ('approved','completed')
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = NULL;
END$$

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

-- The two built-in "How do I pay for a hostel bed?" answers (one per refund
-- policy) described the POS chain. Both now say pay to use. An answer an admin
-- already edited is left alone; its "reset to default" text is updated.
SET @hostel_pay = 'The hostel is **pay to use**: your stay is paid **before** you get your bed. After staff approve your ID and booking, pay any time up to your check-in day — **GCash** to the designated hostel account from My Bookings, or **cash at the front desk** when you arrive. A stay not paid by the **end of its check-in day** is released. Booking at the front desk? You pay in cash there and then. You get the **VENUSeP System Receipt** once your payment is confirmed, and the **Official Receipt** from the University Cashier later.';
UPDATE faqs
   SET answer = IF(answer = default_answer, @hostel_pay, answer),
       default_answer = @hostel_pay
 WHERE is_builtin = 1 AND section_key = 'hostel' AND default_question = 'How do I pay for a hostel bed?';

CREATE OR REPLACE VIEW v_booking_summary AS
SELECT
    b.id AS booking_id,
    CONCAT('VNS-', YEAR(b.submitted_at), '-', LPAD(b.id, 6, '0')) AS booking_reference,
    b.customer_id, c.full_name AS customer_name,
    b.room_id, r.name AS room_name, v.id AS venue_id, v.name AS venue_name,
    b.booking_type,
    b.reservation_status, rs.staff_label AS reservation_staff_label, rs.customer_label AS reservation_customer_label,
    b.payment_status, ps.staff_label AS payment_staff_label, ps.customer_label AS payment_customer_label,
    b.payment_method, b.is_usep_affiliated, b.affiliation_verified,
    b.room_price, b.discount_percent, b.discount_amount, b.total_amount,
    b.refunds_allowed, b.pay_to_use,
    IF(b.pay_to_use, 'pay_to_use', IF(b.refunds_allowed, 'prepay', 'postpay')) AS payment_policy,
    fn_booking_first_day(b.id) AS first_day,
    fn_booking_last_day(b.id)  AS last_day,
    b.current_deadline_at, b.submitted_at, b.updated_at
FROM bookings b
JOIN customers c ON c.id = b.customer_id
JOIN rooms r ON r.id = b.room_id
JOIN venues v ON v.id = r.venue_id
JOIN reservation_statuses rs ON rs.code = b.reservation_status
JOIN payment_statuses ps ON ps.code = b.payment_status;
