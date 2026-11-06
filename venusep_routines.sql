-- =====================================================================
-- VENUSeP: every routine in one place, with what each one is for.
--
-- FOR READING AND SEARCHING. Nothing runs this file: the database got these from
-- venusep_schema.sql and the migrations, and a change goes in a new migration.
-- Written from the database at migration 19. The demo seed's own routines
-- (sp_seed_*, fn_seed_customer) are in venusep_demo_seed.sql.
--
-- In order: functions, procedures, triggers (by table), views.
-- A trigger cannot see who is signed in: a save sets @venusep_actor_user_id and
-- @venusep_action_note first (saveAs in app/Queries/SavesWithHistory.php).
-- SIGNAL SQLSTATE '45000' is a refusal: the page shows it as a sentence.
-- =====================================================================

DELIMITER $$

-- ---- FUNCTIONS ----

-- fn_booking_first_day: A booking's first day: a venue booking's start date, a hostel stay's check-in.
DROP FUNCTION IF EXISTS fn_booking_first_day$$
CREATE FUNCTION `fn_booking_first_day`(p_booking_id BIGINT UNSIGNED) RETURNS date
    READS SQL DATA
BEGIN
    DECLARE v_day DATE;
    SELECT COALESCE(vbd.start_date, hbd.check_in_date) INTO v_day
      FROM bookings b
      LEFT JOIN venue_booking_details  vbd ON vbd.booking_id = b.id
      LEFT JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
     WHERE b.id = p_booking_id;
    RETURN v_day;
END$$

-- fn_booking_last_day: A booking's last day: a venue booking's end date, a hostel stay's check-out.
DROP FUNCTION IF EXISTS fn_booking_last_day$$
CREATE FUNCTION `fn_booking_last_day`(p_booking_id BIGINT UNSIGNED) RETURNS date
    READS SQL DATA
BEGIN
    DECLARE v_day DATE;
    SELECT COALESCE(vbd.end_date, hbd.check_out_date) INTO v_day
      FROM bookings b
      LEFT JOIN venue_booking_details  vbd ON vbd.booking_id = b.id
      LEFT JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
     WHERE b.id = p_booking_id;
    RETURN v_day;
END$$

-- fn_current_discount_percent: The system-wide discount setting, 0 when it is not set. The offices now keep their own
--   (venues.discount_percent).
DROP FUNCTION IF EXISTS fn_current_discount_percent$$
CREATE FUNCTION `fn_current_discount_percent`() RETURNS decimal(5,2)
    READS SQL DATA
BEGIN
    DECLARE v DECIMAL(5,2);
    SELECT CAST(setting_value AS DECIMAL(5,2)) INTO v
    FROM system_settings WHERE setting_key = 'discount_percent';
    RETURN COALESCE(v, 0.00);
END$$

-- fn_customer_blocked: Is this customer blocked at the office this room belongs to?
DROP FUNCTION IF EXISTS fn_customer_blocked$$
CREATE FUNCTION `fn_customer_blocked`(p_customer_id BIGINT UNSIGNED, p_room_id BIGINT UNSIGNED) RETURNS tinyint(1)
    READS SQL DATA
BEGIN
    RETURN EXISTS (SELECT 1 FROM customer_office_blocks k JOIN rooms r ON r.venue_id = k.venue_id
                    WHERE r.id = p_room_id AND k.customer_id = p_customer_id AND k.lifted_at IS NULL);
END$$

-- fn_hostel_beds_free: The fewest free beds of a hostel room on any night of a stay: how many guests can really
--   fit.
DROP FUNCTION IF EXISTS fn_hostel_beds_free$$
CREATE FUNCTION `fn_hostel_beds_free`(p_room_id BIGINT UNSIGNED, p_check_in DATE, p_check_out DATE) RETURNS int(11)
    READS SQL DATA
BEGIN
    DECLARE v_total INT DEFAULT 0;
    DECLARE v_min_free INT;
    DECLARE v_night DATE;
    DECLARE v_taken INT;

    SELECT COUNT(*) INTO v_total FROM hostel_beds WHERE room_id = p_room_id AND is_active = 1;
    SET v_min_free = v_total;
    SET v_night = p_check_in;

    WHILE v_night < p_check_out DO
        SELECT COUNT(*) INTO v_taken
        FROM bed_reservation_nights brn
        JOIN hostel_beds hb ON hb.id = brn.bed_id
        JOIN bookings b ON b.id = brn.booking_id
        WHERE hb.room_id = p_room_id
          AND brn.night_date = v_night
          AND brn.released_at IS NULL
          AND b.reservation_status IN ('pending','approved')
          AND (b.current_deadline_at IS NULL OR b.current_deadline_at > NOW());
        IF (v_total - v_taken) < v_min_free THEN SET v_min_free = v_total - v_taken; END IF;
        SET v_night = DATE_ADD(v_night, INTERVAL 1 DAY);
    END WHILE;

    RETURN v_min_free;
END$$

-- fn_office_closed: Is this office closed on this day: a weekly closed day, or a closure with dates?
DROP FUNCTION IF EXISTS fn_office_closed$$
CREATE FUNCTION `fn_office_closed`(p_venue_id BIGINT UNSIGNED, p_date DATE) RETURNS tinyint(1)
    READS SQL DATA
BEGIN
    RETURN EXISTS(SELECT 1 FROM venues WHERE id = p_venue_id
                     AND FIND_IN_SET(DAYOFWEEK(p_date) - 1, closed_weekdays) > 0)
        OR EXISTS(SELECT 1 FROM venue_closures WHERE venue_id = p_venue_id
                     AND from_date <= p_date AND until_date >= p_date);
END$$

-- fn_payment_deadline: Works out a booking's pay-by date and time from its policy, its days and the office's
--   settings.
DROP FUNCTION IF EXISTS fn_payment_deadline$$
CREATE FUNCTION `fn_payment_deadline`(p_booking_id BIGINT UNSIGNED) RETURNS datetime
    READS SQL DATA
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

-- fn_request_expiry: When a pending request lapses by itself: a venue booking at its first slot, a stay at the
--   end of its check-in day.
DROP FUNCTION IF EXISTS fn_request_expiry$$
CREATE FUNCTION `fn_request_expiry`(p_booking_id BIGINT UNSIGNED, p_type VARCHAR(20)) RETURNS datetime
    READS SQL DATA
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

-- fn_room_hard_blocked: Is this room closed for the whole of this day (a hard maintenance window or an office
--   closure)?
DROP FUNCTION IF EXISTS fn_room_hard_blocked$$
CREATE FUNCTION `fn_room_hard_blocked`(p_room_id BIGINT UNSIGNED, p_date DATE) RETURNS tinyint(1)
    READS SQL DATA
BEGIN
    RETURN EXISTS(
        SELECT 1 FROM maintenance_windows
        WHERE room_id = p_room_id
          AND blocks_booking = 1
          AND from_date <= p_date
          AND (until_date IS NULL OR until_date >= p_date)
    ) OR fn_office_closed((SELECT venue_id FROM rooms WHERE id = p_room_id), p_date);
END$$

-- fn_room_live_bookings: How many of a room's bookings are pending, still to come or going on. Above 0, the room
--   cannot be deleted.
DROP FUNCTION IF EXISTS fn_room_live_bookings$$
CREATE FUNCTION `fn_room_live_bookings`(p_room_id BIGINT UNSIGNED) RETURNS int(11)
    READS SQL DATA
BEGIN
    RETURN (SELECT COUNT(*) FROM bookings b
             WHERE b.room_id = p_room_id
               AND (b.reservation_status = 'pending'
                    OR (b.reservation_status = 'approved'
                        AND (fn_booking_last_day(b.id) >= CURDATE()
                             OR EXISTS (SELECT 1 FROM hostel_booking_details h
                                         WHERE h.booking_id = b.id AND h.checked_in = 1 AND h.checked_out = 0)))));
END$$

-- fn_staff_on_room: Does this staff member handle this room: the hostel of their office, a hall given to them,
--   or a cover going on now?
DROP FUNCTION IF EXISTS fn_staff_on_room$$
CREATE FUNCTION `fn_staff_on_room`(p_user_id BIGINT UNSIGNED, p_room_id BIGINT UNSIGNED) RETURNS tinyint(1)
    READS SQL DATA
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

-- ---- PROCEDURES ----

-- sp_add_venue_slot: Holds one day of a venue booking. The real guard against a taken slot: locks the room and
--   date, re-checks closures, overlaps and the cleanup gap, and refuses with SIGNAL 45000.
DROP PROCEDURE IF EXISTS sp_add_venue_slot$$
CREATE PROCEDURE `sp_add_venue_slot`(
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

-- sp_approve_booking: The one place that knows what "approved" means: sets the status, the deadline and where the
--   payment goes next. The caller owns the transaction.
DROP PROCEDURE IF EXISTS sp_approve_booking$$
CREATE PROCEDURE `sp_approve_booking`(
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_pay_to_use BOOLEAN;
    DECLARE v_method VARCHAR(10);
    DECLARE v_status VARCHAR(40);
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_exact DECIMAL(12,2);
    DECLARE v_final DECIMAL(12,2);

    SELECT booking_type, refunds_allowed, pay_to_use, payment_method INTO v_type, v_prepay, v_pay_to_use, v_method
      FROM bookings WHERE id = p_booking_id AND reservation_status = 'pending';
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a pending booking can be approved.';
    END IF;

    
    
    SELECT room_price, total_amount INTO v_price, v_exact
      FROM bookings WHERE id = p_booking_id AND discount_amount > 0;
    IF v_exact IS NOT NULL THEN
        SET v_final = CEIL(v_exact);
        IF v_final >= v_price THEN
            SET v_final = FLOOR(v_exact);
        END IF;
        IF v_final <= 0 THEN
            SET v_final = v_price;
        END IF;
        UPDATE bookings
            SET total_amount = v_final, discount_amount = room_price - v_final
            WHERE id = p_booking_id;
    END IF;

    IF v_pay_to_use THEN
        
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

-- sp_assign_bed: Gives one guest one exact bed for every night of the stay. The caller owns the transaction
--   (migration 15); a bed taken meanwhile fails on the unique index (1062), a closure with
--   SIGNAL 45000.
DROP PROCEDURE IF EXISTS sp_assign_bed$$
CREATE PROCEDURE `sp_assign_bed`(
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
        
        IF fn_office_closed((SELECT r.venue_id FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = p_booking_id), v_night) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The office is closed during the requested stay.';
        END IF;
        INSERT INTO bed_reservation_nights (bed_reservation_id, booking_id, bed_id, night_date)
            VALUES (v_assignment, p_booking_id, p_bed_id, v_night);
        SET v_night = DATE_ADD(v_night, INTERVAL 1 DAY);
    END WHILE;
END$$

-- sp_expire_due_bookings: The deadline housekeeping: lapses overdue requests and unpaid holds. Run before pages read
--   bookings, and just BEFORE a booking's transaction, never inside it (migration 15).
DROP PROCEDURE IF EXISTS sp_expire_due_bookings$$
CREATE PROCEDURE `sp_expire_due_bookings`()
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

    
    SET @venusep_action_note = 'Paid and finished.';
    UPDATE bookings
        SET reservation_status = 'completed'
        WHERE reservation_status = 'approved'
          AND payment_status IN ('confirmed','paid_cash','refund_denied')
          AND fn_booking_last_day(id) < CURDATE();

    SET @venusep_action_note = NULL;
END$$

-- sp_notify_admins: Tells every admin. No longer used by the system: notify_account_event() tells the right
--   group instead.
DROP PROCEDURE IF EXISTS sp_notify_admins$$
CREATE PROCEDURE `sp_notify_admins`(
    IN p_kind VARCHAR(40), IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    INSERT INTO notifications (user_id, kind, actor_user_id, detail)
    SELECT u.id, p_kind, p_actor_user_id, p_detail
      FROM users u
     WHERE u.account_type = 'admin' AND u.is_active = 1 AND u.id <> COALESCE(p_actor_user_id, 0);
END$$

-- sp_notify_customer: Tells the customer of a booking, unless they did it themselves or have no account.
DROP PROCEDURE IF EXISTS sp_notify_customer$$
CREATE PROCEDURE `sp_notify_customer`(
    IN p_booking_id BIGINT UNSIGNED, IN p_kind VARCHAR(40),
    IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
    SELECT c.user_id, p_kind, p_booking_id, p_actor_user_id, p_detail
      FROM bookings b JOIN customers c ON c.id = b.customer_id
     WHERE b.id = p_booking_id AND c.user_id IS NOT NULL AND c.user_id <> COALESCE(p_actor_user_id, 0);
END$$

-- sp_notify_venue_staff: Tells the booking's group admins and the staff on its room, except whoever did it.
DROP PROCEDURE IF EXISTS sp_notify_venue_staff$$
CREATE PROCEDURE `sp_notify_venue_staff`(
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

-- sp_reset_2fa: Turns two-step verification off for an account and takes its recovery codes away.
DROP PROCEDURE IF EXISTS sp_reset_2fa$$
CREATE PROCEDURE `sp_reset_2fa`(IN p_user_id BIGINT UNSIGNED)
BEGIN
    UPDATE users
       SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL,
           totp_failed_attempts = 0, totp_lock_level = 0, totp_locked_until = NULL
     WHERE id = p_user_id;
    DELETE FROM user_recovery_codes WHERE user_id = p_user_id;
END$$

-- sp_start_await_pos: Moves a hostel booking to waiting for its POS from CEDU, with its deadline.
DROP PROCEDURE IF EXISTS sp_start_await_pos$$
CREATE PROCEDURE `sp_start_await_pos`(
    IN p_booking_id BIGINT UNSIGNED,
    IN p_actor_user_id BIGINT UNSIGNED
)
BEGIN
    DECLARE v_hours INT DEFAULT 72;
    DECLARE v_ci DATE;
    DECLARE v_cit TIME;
    DECLARE v_type VARCHAR(20);
    DECLARE v_prepay BOOLEAN;
    DECLARE v_deadline DATETIME;

    SELECT CAST(setting_value AS UNSIGNED) INTO v_hours
        FROM system_settings WHERE setting_key = 'hostel_pos_deadline_hours';

    SELECT b.booking_type, b.refunds_allowed, hbd.check_in_date, COALESCE(hrd.check_in_time, '00:00:00')
      INTO v_type, v_prepay, v_ci, v_cit
    FROM bookings b
    JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
    JOIN hostel_room_details hrd ON hrd.room_id = b.room_id
    WHERE b.id = p_booking_id;

    IF v_type <> 'hostel' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only hostel bookings can enter await_pos.';
    END IF;

    IF v_prepay THEN
        SET v_deadline = LEAST(DATE_ADD(NOW(), INTERVAL v_hours HOUR), TIMESTAMP(v_ci, v_cit));
    ELSE
        SET v_deadline = fn_payment_deadline(p_booking_id);
    END IF;

    SET @venusep_actor_user_id = p_actor_user_id;
    SET @venusep_action_note = IF(v_prepay, 'Approved; awaiting POS from CEDU.', 'Checked out; awaiting POS from CEDU.');
    UPDATE bookings
        SET reservation_status = 'approved', payment_status = 'await_pos',
            current_deadline_at = v_deadline,
            approved_at = COALESCE(approved_at, NOW()),
            updated_by_user_id = p_actor_user_id
        WHERE id = p_booking_id;
    SET @venusep_actor_user_id = NULL;
    SET @venusep_action_note = NULL;
END$$

-- ---- TRIGGERS ----

-- on bed_reservations

-- trg_bed_reservations_validate: A bed reservation must match its booking: a hostel booking, the booking's room and dates.
DROP TRIGGER IF EXISTS trg_bed_reservations_validate$$
CREATE TRIGGER trg_bed_reservations_validate BEFORE INSERT ON bed_reservations
FOR EACH ROW
BEGIN
    DECLARE v_broom BIGINT UNSIGNED;
    DECLARE v_bedroom BIGINT UNSIGNED;
    DECLARE v_type VARCHAR(20);
    DECLARE v_ci DATE;
    DECLARE v_co DATE;
    DECLARE v_active BOOLEAN;

    SELECT b.room_id, b.booking_type, hbd.check_in_date, hbd.check_out_date
      INTO v_broom, v_type, v_ci, v_co
    FROM bookings b
    JOIN hostel_booking_details hbd ON hbd.booking_id = b.id
    WHERE b.id = NEW.booking_id;

    SELECT room_id, is_active INTO v_bedroom, v_active FROM hostel_beds WHERE id = NEW.bed_id;

    IF v_type <> 'hostel' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bed assignments are only for hostel bookings.';
    END IF;
    IF v_broom <> v_bedroom OR v_active = 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The bed must be active and belong to the booking room.';
    END IF;
    IF NEW.check_in_date <> v_ci OR NEW.check_out_date <> v_co THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Bed dates must match the hostel booking dates.';
    END IF;
END$$

-- on bookings

-- trg_bookings_after_update: On a status change: writes the History line, signed with @venusep_actor_user_id and
--   @venusep_action_note, and frees the dates when the booking closes.
DROP TRIGGER IF EXISTS trg_bookings_after_update$$
CREATE TRIGGER trg_bookings_after_update AFTER UPDATE ON bookings
FOR EACH ROW
BEGIN
    IF OLD.reservation_status <> NEW.reservation_status
       OR OLD.payment_status <> NEW.payment_status THEN
        INSERT INTO booking_timeline (
            booking_id, action_code,
            old_reservation_status, new_reservation_status,
            old_payment_status, new_payment_status,
            performed_by_user_id, note
        ) VALUES (
            NEW.id, 'status_changed',
            OLD.reservation_status, NEW.reservation_status,
            OLD.payment_status, NEW.payment_status,
            COALESCE(@venusep_actor_user_id, NEW.updated_by_user_id),
            @venusep_action_note
        );
    END IF;

    IF NEW.reservation_status IN ('released','rejected','cancelled','completed','disrupted')
       AND OLD.reservation_status NOT IN ('released','rejected','cancelled','completed','disrupted') THEN
        UPDATE venue_booking_slots
            SET released_at = COALESCE(released_at, NOW())
            WHERE booking_id = NEW.id;
        UPDATE bed_reservation_nights
            SET released_at = COALESCE(released_at, NOW())
            WHERE booking_id = NEW.id;   
    END IF;
END$$

-- trg_bookings_block_ins: Refuses a new booking at an office that blocked the customer.
DROP TRIGGER IF EXISTS trg_bookings_block_ins$$
CREATE TRIGGER trg_bookings_block_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    IF fn_customer_blocked(NEW.customer_id, NEW.room_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This customer is blocked at this office, so no new booking can be made there.';
    END IF;
END$$

-- trg_bookings_room_deleted_ins: Refuses a new booking on a deleted room.
DROP TRIGGER IF EXISTS trg_bookings_room_deleted_ins$$
CREATE TRIGGER trg_bookings_room_deleted_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    IF EXISTS (SELECT 1 FROM rooms WHERE id = NEW.room_id AND deleted_at IS NOT NULL) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This room was deleted, so it cannot be booked.';
    END IF;
END$$

-- trg_bookings_type_ins: A new booking's type must match its room.
DROP TRIGGER IF EXISTS trg_bookings_type_ins$$
CREATE TRIGGER trg_bookings_type_ins BEFORE INSERT ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_rt VARCHAR(20);
    SELECT room_type INTO v_rt FROM rooms WHERE id = NEW.room_id;
    
    
    IF v_rt IS NULL
       OR (NEW.booking_type = 'hostel' AND v_rt <> 'hostel')
       OR (NEW.booking_type = 'venue'  AND v_rt <> 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking type must match the room type.';
    END IF;
    SET NEW.pay_to_use = (NEW.booking_type = 'hostel');
END$$

-- trg_bookings_type_upd: A changed booking's type must still match its room.
DROP TRIGGER IF EXISTS trg_bookings_type_upd$$
CREATE TRIGGER trg_bookings_type_upd BEFORE UPDATE ON bookings
FOR EACH ROW
BEGIN
    DECLARE v_rt VARCHAR(20);
    SELECT room_type INTO v_rt FROM rooms WHERE id = NEW.room_id;
    
    
    IF v_rt IS NULL
       OR (NEW.booking_type = 'hostel' AND v_rt <> 'hostel')
       OR (NEW.booking_type = 'venue'  AND v_rt <> 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Booking type must match the room type.';
    END IF;
END$$

-- on booking_timeline

-- trg_booking_timeline_notify: A new History line may notify the customer or staff: approved, rejected, released and the
--   like.
DROP TRIGGER IF EXISTS trg_booking_timeline_notify$$
CREATE TRIGGER trg_booking_timeline_notify AFTER INSERT ON booking_timeline
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(40) DEFAULT NULL;
    DECLARE v_staff BOOLEAN DEFAULT FALSE;     

    IF NEW.action_code = 'status_changed' THEN
        IF NEW.old_reservation_status = 'pending' AND NEW.new_reservation_status = 'approved' THEN
            SET v_kind = 'approved';
        ELSEIF NEW.new_reservation_status = 'rejected' AND NEW.old_reservation_status <> 'rejected' THEN
            SET v_kind = 'rejected';
        ELSEIF NEW.new_reservation_status = 'released' AND NEW.old_reservation_status <> 'released' THEN
            SET v_kind = 'expired';
        ELSEIF NEW.new_payment_status IN ('confirmed', 'paid_cash') AND NEW.old_payment_status NOT IN ('confirmed', 'paid_cash') THEN
            SET v_kind = 'payment_confirmed';
        ELSEIF NEW.old_payment_status = 'under_review' AND NEW.new_payment_status IN ('await_gcash', 'await_cash') THEN
            SET v_kind = 'receipt_rejected';
        ELSEIF NEW.old_payment_status IN ('await_event', 'await_pos', 'locked') AND NEW.new_payment_status IN ('await_gcash', 'await_cash') THEN
            SET v_kind = 'payment_open';
        ELSEIF NEW.new_payment_status IN ('refunded', 'refund_denied', 'refund_correction', 'refund_processing', 'refund_await_or')
               AND NEW.old_payment_status <> NEW.new_payment_status THEN
            SET v_kind = 'refund_update';
        ELSEIF NEW.new_payment_status = 'under_review' AND NEW.old_payment_status <> 'under_review' THEN
            SET v_kind = 'receipt_uploaded'; SET v_staff = TRUE;
        ELSEIF NEW.old_payment_status = 'await_gcash' AND NEW.new_payment_status = 'await_cash' THEN
            SET v_kind = 'cash_chosen'; SET v_staff = TRUE;
        ELSEIF NEW.old_payment_status = 'await_cash' AND NEW.new_payment_status = 'await_gcash' THEN
            SET v_kind = 'cash_cancelled'; SET v_staff = TRUE;
        ELSEIF NEW.new_payment_status = 'refund_requested' AND NEW.old_payment_status <> 'refund_requested' THEN
            SET v_kind = 'refund_requested'; SET v_staff = TRUE;
        END IF;
    END IF;

    IF v_kind IS NOT NULL THEN
        IF v_staff THEN
            CALL sp_notify_venue_staff(NEW.booking_id, v_kind, NEW.performed_by_user_id, JSON_OBJECT('pay', NEW.new_payment_status, 'note', NEW.note));
        ELSE
            CALL sp_notify_customer(NEW.booking_id, v_kind, NEW.performed_by_user_id, JSON_OBJECT('pay', NEW.new_payment_status, 'note', NEW.note));
        END IF;
    END IF;
END$$

-- on rooms

-- trg_rooms_deleted_upd: Refuses to undo a delete, and to delete a room with live bookings (fn_room_live_bookings).
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

-- trg_rooms_halls_follow_upd: A hall moved to another office: the old office's staff lose it, the new office's staff get
--   it.
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

-- trg_rooms_type_ins: A new room's type must match its venue's.
DROP TRIGGER IF EXISTS trg_rooms_type_ins$$
CREATE TRIGGER trg_rooms_type_ins BEFORE INSERT ON rooms
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(20);
    SELECT venue_type INTO v_kind FROM venues WHERE id = NEW.venue_id;
    IF v_kind IS NULL OR v_kind <> NEW.room_type THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room type must match the venue type.';
    END IF;
END$$

-- trg_rooms_type_upd: A changed room's type must still match its venue's.
DROP TRIGGER IF EXISTS trg_rooms_type_upd$$
CREATE TRIGGER trg_rooms_type_upd BEFORE UPDATE ON rooms
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(20);
    SELECT venue_type INTO v_kind FROM venues WHERE id = NEW.venue_id;
    IF v_kind IS NULL OR v_kind <> NEW.room_type THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Room type must match the venue type.';
    END IF;
END$$

-- on staff

-- trg_staff_employee_no_ins: Gives a new staff row its number, EMP-<year>-0001, from employee_no_counters.
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

-- trg_staff_employee_no_upd: Keeps a staff member's number from being blanked or changed.
DROP TRIGGER IF EXISTS trg_staff_employee_no_upd$$
CREATE TRIGGER trg_staff_employee_no_upd BEFORE UPDATE ON staff
FOR EACH ROW
BEGIN
    IF NOT (NEW.employee_no <=> OLD.employee_no) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An employee number is given once and never changes.';
    END IF;
END$$

-- trg_staff_halls_follow_upd: A staff member moved to another office: the old office's halls go, the new office's halls
--   come.
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

-- trg_staff_halls_new_ins: A new staff member starts with every hall of their office.
DROP TRIGGER IF EXISTS trg_staff_halls_new_ins$$
CREATE TRIGGER trg_staff_halls_new_ins AFTER INSERT ON staff
FOR EACH ROW
BEGIN
    INSERT IGNORE INTO staff_halls (user_id, room_id)
        SELECT NEW.user_id, r.id FROM rooms r
         WHERE r.venue_id = NEW.venue_id AND r.room_type = 'event' AND r.deleted_at IS NULL
           AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff';
END$$

-- trg_staff_venue_required_ins: A new staff account must have an office.
DROP TRIGGER IF EXISTS trg_staff_venue_required_ins$$
CREATE TRIGGER trg_staff_venue_required_ins BEFORE INSERT ON staff
FOR EACH ROW
BEGIN
    IF NEW.venue_id IS NULL
       AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff'
       AND NOT EXISTS (SELECT 1 FROM staff WHERE user_id = NEW.user_id) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every staff member must handle a venue.';
    END IF;
END$$

-- trg_staff_venue_required_upd: A staff account cannot be left with no office.
DROP TRIGGER IF EXISTS trg_staff_venue_required_upd$$
CREATE TRIGGER trg_staff_venue_required_upd BEFORE UPDATE ON staff
FOR EACH ROW
BEGIN
    IF NEW.venue_id IS NULL
       AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every staff member must handle a venue.';
    END IF;
END$$

-- on staff_halls

-- trg_staff_halls_ins: A staff member is given only halls of their own office.
DROP TRIGGER IF EXISTS trg_staff_halls_ins$$
CREATE TRIGGER trg_staff_halls_ins BEFORE INSERT ON staff_halls
FOR EACH ROW
BEGIN
    IF NOT EXISTS (SELECT 1 FROM staff s JOIN rooms r ON r.venue_id = s.venue_id
                    WHERE s.user_id = NEW.user_id AND r.id = NEW.room_id AND r.room_type = 'event') THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A staff member is given only halls of their own office.';
    END IF;
END$$

DELIMITER ;

-- ---- VIEWS ----

-- v_active_hostel_bed_nights: The bed-nights held right now: not released, on a live booking.
CREATE OR REPLACE VIEW `v_active_hostel_bed_nights` AS select `brn`.`bed_id` AS `bed_id`,`hb`.`room_id` AS `room_id`,`brn`.`night_date` AS `night_date`,`brn`.`booking_id` AS `booking_id`,`brn`.`bed_reservation_id` AS `bed_reservation_id` from ((`bed_reservation_nights` `brn` join `hostel_beds` `hb` on(`hb`.`id` = `brn`.`bed_id`)) join `bookings` `b` on(`b`.`id` = `brn`.`booking_id`)) where `brn`.`released_at` is null and `b`.`reservation_status` in ('pending','approved') and (`b`.`current_deadline_at` is null or `b`.`current_deadline_at` > current_timestamp());

-- v_active_venue_slots: The venue days held right now: not released, on a live booking.
CREATE OR REPLACE VIEW `v_active_venue_slots` AS select `s`.`id` AS `venue_slot_id`,`s`.`booking_id` AS `booking_id`,`s`.`room_id` AS `room_id`,`s`.`slot_date` AS `slot_date`,`s`.`start_time` AS `start_time`,`s`.`end_time` AS `end_time` from (`venue_booking_slots` `s` join `bookings` `b` on(`b`.`id` = `s`.`booking_id`)) where `s`.`released_at` is null and `b`.`reservation_status` in ('pending','approved') and (`b`.`current_deadline_at` is null or `b`.`current_deadline_at` > current_timestamp());

-- v_booking_summary: One line per booking, with its reference, customer, room and office.
CREATE OR REPLACE VIEW `v_booking_summary` AS select `b`.`id` AS `booking_id`,concat('VNS-',year(`b`.`submitted_at`),'-',lpad(`b`.`id`,6,'0')) AS `booking_reference`,`b`.`customer_id` AS `customer_id`,`c`.`full_name` AS `customer_name`,`b`.`room_id` AS `room_id`,`r`.`name` AS `room_name`,`v`.`id` AS `venue_id`,`v`.`name` AS `venue_name`,`b`.`booking_type` AS `booking_type`,`b`.`reservation_status` AS `reservation_status`,`rs`.`staff_label` AS `reservation_staff_label`,`rs`.`customer_label` AS `reservation_customer_label`,`b`.`payment_status` AS `payment_status`,`ps`.`staff_label` AS `payment_staff_label`,`ps`.`customer_label` AS `payment_customer_label`,`b`.`payment_method` AS `payment_method`,`b`.`is_usep_affiliated` AS `is_usep_affiliated`,`b`.`affiliation_verified` AS `affiliation_verified`,`b`.`room_price` AS `room_price`,`b`.`discount_percent` AS `discount_percent`,`b`.`discount_amount` AS `discount_amount`,`b`.`total_amount` AS `total_amount`,`b`.`refunds_allowed` AS `refunds_allowed`,`b`.`pay_to_use` AS `pay_to_use`,if(`b`.`pay_to_use`,'pay_to_use',if(`b`.`refunds_allowed`,'prepay','postpay')) AS `payment_policy`,`fn_booking_first_day`(`b`.`id`) AS `first_day`,`fn_booking_last_day`(`b`.`id`) AS `last_day`,`b`.`current_deadline_at` AS `current_deadline_at`,`b`.`submitted_at` AS `submitted_at`,`b`.`updated_at` AS `updated_at` from (((((`bookings` `b` join `customers` `c` on(`c`.`id` = `b`.`customer_id`)) join `rooms` `r` on(`r`.`id` = `b`.`room_id`)) join `venues` `v` on(`v`.`id` = `r`.`venue_id`)) join `reservation_statuses` `rs` on(`rs`.`code` = `b`.`reservation_status`)) join `payment_statuses` `ps` on(`ps`.`code` = `b`.`payment_status`));

-- v_gcash_active_accounts: Each office's current GCash account.
CREATE OR REPLACE VIEW `v_gcash_active_accounts` AS select `ga`.`venue_id` AS `venue_id`,`v`.`name` AS `venue_name`,`ga`.`account_name` AS `account_name`,`ga`.`mobile_number` AS `mobile_number` from (`gcash_accounts` `ga` join `venues` `v` on(`v`.`id` = `ga`.`venue_id`)) where `ga`.`active_venue_id` is not null;

-- v_room_current_maintenance: The closures going on, with hard or medium as a word.
CREATE OR REPLACE VIEW `v_room_current_maintenance` AS select `mw`.`room_id` AS `room_id`,`r`.`name` AS `room_name`,`mw`.`from_date` AS `from_date`,`mw`.`until_date` AS `until_date`,`mw`.`reason` AS `reason`,case when `mw`.`blocks_booking` then 'hard' else 'medium' end AS `severity` from (`maintenance_windows` `mw` join `rooms` `r` on(`r`.`id` = `mw`.`room_id`)) where `mw`.`from_date` <= curdate() and (`mw`.`until_date` is null or `mw`.`until_date` >= curdate());
