-- ============================================================================
--  VENUSeP — MIGRATION 16: the offices are separate (decided 2026-11-01).
--  Run against an EXISTING venusep database, after 15. Safe to re-run
--  (idempotent): settings an admin has changed are never overwritten.
--
--      Windows (cmd, not PowerShell):
--          C:\xampp\mysql\bin\mysql.exe -u root venusep < venusep_migration_16.sql
--
--  A fresh build runs venusep_schema.sql, then every migration in order
--  (SETUP.md), so this is not repeated in the schema file.
--
--  WHAT CHANGES
--    Per office (venues): opening hours, regular closed days, USeP discount,
--      grace days, 12-hour lead time, cleaning gap; one-off closures
--      (venue_closures) and a settings history (venue_settings_history).
--    Bookings: the rate shown at booking, organization, deadline extensions,
--      date moves, write-off request.
--    Finished bookings become Completed by themselves; a stay keeps its bed
--      while the guest is checked in.
--    Refunds made outside the system; voided System Receipts; hostel check-out;
--      IDs: encrypted flag, removal date, and a log of who opened one.
--    Demo mode's setting is removed.
--
--  ROLLBACK: restore the backup taken before running this. The old site keeps
--  working on the new columns, but only a restore undoes the function changes.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- each office's settings; NULL during the first run = "not seeded yet" ----
ALTER TABLE venues
    ADD COLUMN IF NOT EXISTS opens_at            TIME NULL,
    ADD COLUMN IF NOT EXISTS closes_at           TIME NULL,
    ADD COLUMN IF NOT EXISTS closed_weekdays     VARCHAR(20) NULL,   -- comma list, 0 = Sunday .. 6 = Saturday
    ADD COLUMN IF NOT EXISTS discount_percent    TINYINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS postpay_grace_days  SMALLINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS lead_hours          SMALLINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS cleanup_gap_minutes SMALLINT UNSIGNED NULL;

UPDATE venues SET opens_at = '07:00:00' WHERE opens_at IS NULL;
UPDATE venues SET closes_at = '22:00:00' WHERE closes_at IS NULL;
UPDATE venues SET closed_weekdays = IF(venue_type = 'hostel', '', '0,6') WHERE closed_weekdays IS NULL;
UPDATE venues SET lead_hours = IF(venue_type = 'hostel', 0, 12) WHERE lead_hours IS NULL;
UPDATE venues SET discount_percent = COALESCE(
        (SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = 'discount_percent'), 20)
    WHERE discount_percent IS NULL;
UPDATE venues SET postpay_grace_days = COALESCE(
        (SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = 'postpay_grace_days'), 3)
    WHERE postpay_grace_days IS NULL;
UPDATE venues SET cleanup_gap_minutes = COALESCE(
        (SELECT CAST(setting_value AS UNSIGNED) FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes'), 60)
    WHERE cleanup_gap_minutes IS NULL;

ALTER TABLE venues
    MODIFY opens_at            TIME NOT NULL DEFAULT '07:00:00',
    MODIFY closes_at           TIME NOT NULL DEFAULT '22:00:00',
    MODIFY closed_weekdays     VARCHAR(20) NOT NULL DEFAULT '0,6',
    MODIFY discount_percent    TINYINT UNSIGNED NOT NULL DEFAULT 20,
    MODIFY postpay_grace_days  SMALLINT UNSIGNED NOT NULL DEFAULT 3,
    MODIFY lead_hours          SMALLINT UNSIGNED NOT NULL DEFAULT 12,
    MODIFY cleanup_gap_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60;

CREATE TABLE IF NOT EXISTS venue_closures (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id           BIGINT UNSIGNED NOT NULL,
    from_date          DATE NOT NULL,
    until_date         DATE NOT NULL,
    reason             VARCHAR(500) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NULL,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_venue_closures (venue_id, from_date, until_date),
    CONSTRAINT fk_venue_closures_venue FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_venue_closures_user FOREIGN KEY (created_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS venue_settings_history (
    id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    venue_id           BIGINT UNSIGNED NOT NULL,
    field              VARCHAR(40) NOT NULL,
    old_value          VARCHAR(100) NULL,
    new_value          VARCHAR(100) NULL,
    note               VARCHAR(500) NULL,
    changed_by_user_id BIGINT UNSIGNED NULL,
    changed_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_venue_settings_history (venue_id, changed_at),
    CONSTRAINT fk_venue_settings_history_venue FOREIGN KEY (venue_id) REFERENCES venues(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_venue_settings_history_user FOREIGN KEY (changed_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---- bookings ----
ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS quoted_discount_percent TINYINT UNSIGNED NULL,  -- the rate shown when booked
    ADD COLUMN IF NOT EXISTS organization_name       VARCHAR(190) NULL,      -- "Booking for", on the receipt
    ADD COLUMN IF NOT EXISTS deadline_extensions     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS date_moves              SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS write_off_reason        VARCHAR(500) NULL,      -- set = a request waits for an admin
    ADD COLUMN IF NOT EXISTS write_off_requested_by  BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS write_off_requested_at  DATETIME NULL;

INSERT IGNORE INTO payment_statuses (code, staff_label, customer_label, sort_order)
    VALUES ('written_off', 'Written off', 'Closed', 16);

-- ---- refunds the school made outside the system ----
ALTER TABLE refunds
    ADD COLUMN IF NOT EXISTS outside_system    BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS cashier_reference VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS refunded_on       DATE NULL;

-- ---- a reversed payment voids its receipt; the number is never reused ----
ALTER TABLE system_receipts
    ADD COLUMN IF NOT EXISTS voided_at         DATETIME NULL,
    ADD COLUMN IF NOT EXISTS voided_by_user_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS void_reason       VARCHAR(500) NULL;

-- ---- hostel check-out ----
ALTER TABLE hostel_booking_details
    ADD COLUMN IF NOT EXISTS checked_out    BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS checked_out_at DATETIME NULL;

-- ---- IDs: encrypted when stored, removed when the booking is finished ----
ALTER TABLE booking_documents
    ADD COLUMN IF NOT EXISTS encrypted  BOOLEAN NOT NULL DEFAULT FALSE,
    ADD COLUMN IF NOT EXISTS removed_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS document_access_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id BIGINT UNSIGNED NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    opened_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_document_access (document_id, opened_at),
    CONSTRAINT fk_document_access_document FOREIGN KEY (document_id) REFERENCES booking_documents(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_document_access_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---- the emails online customers now get ----
ALTER TABLE email_outbox
    MODIFY kind ENUM('walkin_booking','walkin_receipt','receipt_copy','password_reset',
                     'booking_approved','booking_rejected','payment_due','payment_overdue','booking_cancelled') NOT NULL;

-- ---- demo mode is gone ----
DELETE FROM system_settings_history WHERE setting_key = 'demo_mode';
DELETE FROM system_settings WHERE setting_key = 'demo_mode';

DELIMITER $$

-- Is this office closed on this date? A regular closed day or a one-off closure.
DROP FUNCTION IF EXISTS fn_office_closed$$
CREATE FUNCTION fn_office_closed(p_venue_id BIGINT UNSIGNED, p_date DATE)
RETURNS BOOLEAN
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN EXISTS(SELECT 1 FROM venues WHERE id = p_venue_id
                     AND FIND_IN_SET(DAYOFWEEK(p_date) - 1, closed_weekdays) > 0)
        OR EXISTS(SELECT 1 FROM venue_closures WHERE venue_id = p_venue_id
                     AND from_date <= p_date AND until_date >= p_date);
END$$

-- A room is hard-blocked by its own closure or by its office's.
DROP FUNCTION IF EXISTS fn_room_hard_blocked$$
CREATE FUNCTION fn_room_hard_blocked(p_room_id BIGINT UNSIGNED, p_date DATE)
RETURNS BOOLEAN
NOT DETERMINISTIC READS SQL DATA
BEGIN
    RETURN EXISTS(
        SELECT 1 FROM maintenance_windows
        WHERE room_id = p_room_id
          AND blocks_booking = 1
          AND from_date <= p_date
          AND (until_date IS NULL OR until_date >= p_date)
    ) OR fn_office_closed((SELECT venue_id FROM rooms WHERE id = p_room_id), p_date);
END$$

-- The pay-by moment, from the booking's own policy and its office's settings.
-- It never lands on a day the office is closed (no cashier): pre-pay moves it
-- earlier (it must stay before the event), post-pay moves it later.
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
    DECLARE v_venue BIGINT UNSIGNED;
    DECLARE v_tries INT DEFAULT 0;

    SELECT b.refunds_allowed, b.pay_to_use, r.venue_id, v.postpay_grace_days
      INTO v_prepay, v_pay_to_use, v_venue, v_grace
      FROM bookings b JOIN rooms r ON r.id = b.room_id JOIN venues v ON v.id = r.venue_id
     WHERE b.id = p_booking_id;
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
        WHILE fn_office_closed(v_venue, DATE(v_deadline)) AND v_tries < 31 DO
            SET v_deadline = DATE_SUB(v_deadline, INTERVAL 1 DAY);
            SET v_tries = v_tries + 1;
        END WHILE;
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
        SET v_deadline = TIMESTAMP(DATE_ADD(v_last, INTERVAL v_grace DAY), '23:59:59');
        WHILE fn_office_closed(v_venue, DATE(v_deadline)) AND v_tries < 31 DO
            SET v_deadline = DATE_ADD(v_deadline, INTERVAL 1 DAY);
            SET v_tries = v_tries + 1;
        END WHILE;
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

    -- A guest who is checked in keeps the bed: staff settle it in person.
    SET @venusep_action_note = 'Not paid by the deadline; the hold was released.';
    UPDATE bookings
        SET reservation_status = 'released', payment_status = 'expired'
        WHERE (refunds_allowed = TRUE OR pay_to_use = TRUE)
          AND reservation_status = 'approved'
          AND payment_status IN ('await_gcash','await_cash','await_pos')
          AND current_deadline_at IS NOT NULL
          AND current_deadline_at <= NOW()
          AND NOT EXISTS (SELECT 1 FROM hostel_booking_details h
                           WHERE h.booking_id = bookings.id AND h.checked_in = 1 AND h.checked_out = 0);

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

DROP PROCEDURE IF EXISTS sp_assign_bed$$
CREATE PROCEDURE sp_assign_bed (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_occupant_id BIGINT UNSIGNED,
    IN p_bed_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_ci DATE;
    DECLARE v_co DATE;
    DECLARE v_night DATE;
    DECLARE v_assignment BIGINT UNSIGNED;
    DECLARE v_block INT DEFAULT 0;

    IF @@in_transaction = 0 THEN
        CALL sp_expire_due_bookings();
    END IF;

    SELECT check_in_date, check_out_date INTO v_ci, v_co
        FROM hostel_booking_details WHERE booking_id = p_booking_id FOR UPDATE;

    SELECT COUNT(*) INTO v_block
    FROM bookings b
    JOIN maintenance_windows mw ON mw.room_id = b.room_id
    WHERE b.id = p_booking_id
      AND mw.blocks_booking = 1
      AND mw.from_date < v_co
      AND COALESCE(mw.until_date, '9999-12-31') >= v_ci;
    IF v_block > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HARD maintenance falls during the requested stay.';
    END IF;

    INSERT INTO bed_reservations (booking_id, occupant_id, bed_id, check_in_date, check_out_date)
        VALUES (p_booking_id, p_occupant_id, p_bed_id, v_ci, v_co);
    SET v_assignment = LAST_INSERT_ID();

    SET v_night = v_ci;
    WHILE v_night < v_co DO
        -- a stay cannot be split around a night its office is closed
        IF fn_office_closed((SELECT r.venue_id FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = p_booking_id), v_night) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The office is closed during the requested stay.';
        END IF;
        INSERT INTO bed_reservation_nights (bed_reservation_id, booking_id, bed_id, night_date)
            VALUES (v_assignment, p_booking_id, p_bed_id, v_night);
        SET v_night = DATE_ADD(v_night, INTERVAL 1 DAY);
    END WHILE;
END$$

DROP PROCEDURE IF EXISTS sp_add_venue_slot$$
CREATE PROCEDURE sp_add_venue_slot (
    IN p_booking_id BIGINT UNSIGNED,
    IN p_slot_date DATE,
    IN p_start_time TIME,
    IN p_end_time TIME
)
BEGIN
    DECLARE v_room_id BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(20);
    DECLARE v_conflict INT DEFAULT 0;
    DECLARE v_block INT DEFAULT 0;
    DECLARE v_lock VARCHAR(128);
    DECLARE v_got INT DEFAULT 0;
    DECLARE v_gap INT DEFAULT 60;
    DECLARE v_room_lock INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_got = 1 THEN DO RELEASE_LOCK(v_lock); END IF;
        RESIGNAL;
    END;

    IF @@in_transaction = 0 THEN
        CALL sp_expire_due_bookings();
    END IF;

    IF p_end_time <= p_start_time THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Slot end time must be later than start time.';
    END IF;

    SELECT room_id, booking_type INTO v_room_id, v_type FROM bookings WHERE id = p_booking_id;
    IF v_type <> 'venue' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Venue slots are only for venue bookings.';
    END IF;

    -- lock the room until the caller commits (migration 14)
    SELECT COUNT(*) INTO v_room_lock
      FROM event_room_details WHERE room_id = v_room_id FOR UPDATE;

    SET v_lock = CONCAT('venusep:venue:', v_room_id, ':', DATE_FORMAT(p_slot_date, '%Y%m%d'));
    SELECT GET_LOCK(v_lock, 10) INTO v_got;
    IF v_got <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Could not obtain a room-date availability lock.';
    END IF;

    SELECT COUNT(*) INTO v_block
    FROM maintenance_windows
    WHERE room_id = v_room_id
      AND blocks_booking = 1
      AND from_date <= p_slot_date
      AND COALESCE(until_date, '9999-12-31') >= p_slot_date
    LOCK IN SHARE MODE;
    IF v_block > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HARD maintenance on the requested date.';
    END IF;
    IF fn_office_closed((SELECT venue_id FROM rooms WHERE id = v_room_id), p_slot_date) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The office is closed on the requested date.';
    END IF;

    -- The cleaning gap belongs to the office (migration 16).
    SELECT v.cleanup_gap_minutes INTO v_gap
      FROM rooms r JOIN venues v ON v.id = r.venue_id WHERE r.id = v_room_id;

    SELECT COUNT(*) INTO v_conflict
    FROM venue_booking_slots s
    JOIN bookings b ON b.id = s.booking_id
    WHERE s.room_id = v_room_id
      AND s.slot_date = p_slot_date
      AND s.released_at IS NULL
      AND b.reservation_status IN ('pending','approved')
      AND p_start_time < ADDTIME(s.end_time, SEC_TO_TIME(v_gap * 60))
      AND ADDTIME(p_end_time, SEC_TO_TIME(v_gap * 60)) > s.start_time
    LOCK IN SHARE MODE;
    IF v_conflict > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That time is too close to another booking: bookings need a cleanup gap before and after them.';
    END IF;

    INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time)
        VALUES (p_booking_id, v_room_id, p_slot_date, p_start_time, p_end_time);

    DO RELEASE_LOCK(v_lock);
END$$

DELIMITER ;
