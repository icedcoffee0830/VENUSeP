-- ============================================================================
--  VENUSeP — MIGRATION 07: unapproved requests expire on their date
--  (decided 2026-10-27). Run against an EXISTING venusep database, AFTER
--  migration 06. Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_07.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  venusep_schema.sql carries the same definitions, so a FRESH build needs
--  nothing from this file.
--
--  WHAT CHANGES
--    1. fn_request_expiry (new): when a request nobody approved lapses. For a
--       venue that is the start of its first slot; for a hostel stay, the end
--       of its check-in day. Both refund policies use it.
--    2. sp_expire_due_bookings:
--       - a PENDING request whose date has arrived is released / expired,
--         under BOTH policies (post-pay requests used to never lapse;
--         pre-pay ones lapsed a day early);
--       - the "unpaid pre-pay hold" rule now touches only bookings still
--         WAITING for payment. It used to release paid and under-review
--         bookings on their event day.
--    The app now runs this procedure before every booking or availability
--    read (includes/db.php). Before, it ran only when a new booking was made.
--
--    The first run expires every request that is already past its date. On
--    the 2026-10-27 copy of the database that is bookings 17, 22 and 163.
-- ============================================================================

DELIMITER $$

DROP FUNCTION IF EXISTS fn_request_expiry$$
CREATE FUNCTION fn_request_expiry(p_booking_id BIGINT UNSIGNED, p_type VARCHAR(20))
RETURNS DATETIME
NOT DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_first DATE;
    DECLARE v_start TIME;
    IF p_type = 'venue' THEN
        SELECT MIN(slot_date) INTO v_first FROM venue_booking_slots WHERE booking_id = p_booking_id;
        IF v_first IS NULL THEN
            RETURN NULL;
        END IF;
        SELECT MIN(start_time) INTO v_start
          FROM venue_booking_slots WHERE booking_id = p_booking_id AND slot_date = v_first;
        RETURN TIMESTAMP(v_first, v_start);
    END IF;
    SELECT check_in_date INTO v_first FROM hostel_booking_details WHERE booking_id = p_booking_id;
    IF v_first IS NULL THEN
        RETURN NULL;
    END IF;
    RETURN TIMESTAMP(v_first, '23:59:59');
END$$

DROP PROCEDURE IF EXISTS sp_expire_due_bookings$$
CREATE PROCEDURE sp_expire_due_bookings ()
BEGIN
    SET @venusep_actor_user_id = NULL;

    SET @venusep_action_note = 'Event over; payment window opened.';
    UPDATE bookings
        SET payment_status = CASE WHEN payment_method = 'cash' THEN 'await_cash' ELSE 'await_gcash' END
        WHERE refunds_allowed = FALSE
          AND payment_status = 'await_event'
          AND reservation_status IN ('approved','completed')
          AND fn_booking_last_day(id) < CURDATE();

    SET @venusep_action_note = 'Not approved before the booking date; the request expired.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE reservation_status = 'pending'
          AND fn_request_expiry(id, booking_type) <= NOW();

    -- Only a booking STILL WAITING for its money. This used to match every
    -- approved pre-pay booking, so a PAID one (or one whose receipt sat with
    -- staff) was released on its event day, because its day-before deadline
    -- had passed (found 2026-10-27).
    SET @venusep_action_note = 'Not paid by the deadline; the hold was released.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE refunds_allowed = TRUE
          AND reservation_status = 'approved'
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = 'Payment not received within the post-event window.';
    UPDATE bookings
        SET payment_status = 'overdue'
        WHERE refunds_allowed = FALSE
          AND reservation_status IN ('approved','completed')
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW();

    SET @venusep_action_note = NULL;
END$$

DELIMITER ;
