-- ============================================================================
--  VENUSeP — MIGRATION 15: the booking procedures never commit, roll back or
--  deadlock the caller's transaction (decided 2026-10-30). Run against an
--  EXISTING venusep database, after 14. Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_15.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  WHAT CHANGES — sp_assign_bed and sp_add_venue_slot only (no tables, no data)
--    1. sp_assign_bed no longer runs START TRANSACTION / COMMIT / ROLLBACK.
--       START TRANSACTION inside a procedure silently COMMITS whatever the
--       caller had open (a half-written hostel booking was saved even when a
--       later step failed), and its error handler's ROLLBACK threw away the
--       caller's whole transaction. The caller owns the transaction now: one
--       that wants the bed rows all-or-nothing opens a transaction around the
--       CALL (the Laravel site does, one per bed). Its refusals (SIGNAL 45000,
--       the UNIQUE(bed, night) 1062) are unchanged.
--    2. Both procedures run sp_expire_due_bookings() only when NO transaction
--       is open. Its UPDATEs lock every bookings row they scan, so inside a
--       booking transaction two submits a moment apart deadlocked (1213) on
--       each other's new bookings row — even for different rooms. A caller
--       that opens a transaction runs CALL sp_expire_due_bookings() just
--       before it (the Laravel booking pages do). Called on their own (no
--       transaction), the procedures still expire first, as before.
--    3. sp_add_venue_slot's maintenance re-check is a locking read (LOCK IN
--       SHARE MODE), like the conflict count since 14, so a closure saved
--       after this transaction's snapshot is still seen.
--
--  ROLLBACK: re-run the previous procedure bodies — sp_assign_bed's block
--  from venusep_schema.sql (CREATE PROCEDURE sp_assign_bed ... END$$, after a
--  DROP PROCEDURE IF EXISTS sp_assign_bed$$, inside DELIMITER $$ ... ;) and
--  the whole of venusep_migration_14.sql for sp_add_venue_slot.
-- ============================================================================

SET NAMES utf8mb4;

DELIMITER $$

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

    -- MIGRATION 15 (2): never inside the caller's transaction. See header.
    IF @@in_transaction = 0 THEN
        CALL sp_expire_due_bookings();
    END IF;

    -- MIGRATION 15 (1): no START TRANSACTION / COMMIT / ROLLBACK here.
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

    -- MIGRATION 15 (2): never inside the caller's transaction. See header.
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

    -- MIGRATION 14 (1): lock the room until the caller commits.
    SELECT COUNT(*) INTO v_room_lock
      FROM event_room_details WHERE room_id = v_room_id FOR UPDATE;

    SET v_lock = CONCAT('venusep:venue:', v_room_id, ':', DATE_FORMAT(p_slot_date, '%Y%m%d'));
    SELECT GET_LOCK(v_lock, 10) INTO v_got;
    IF v_got <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Could not obtain a room-date availability lock.';
    END IF;

    -- MIGRATION 15 (3): a locking read sees a closure committed after this
    -- transaction's snapshot was taken (a plain read would not).
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

    -- SAME DAY, DIFFERENT TIME (decided 2026-10-27): a room can hold several
    -- bookings on one date, but each needs the CLEANUP GAP (system_settings
    -- venue_cleanup_gap_minutes, 60) before and after it. Venue rooms only;
    -- hostel beds are per night.
    -- Held = a live booking's unreleased slot. Lapsed holds are released by
    -- sp_expire_due_bookings() — above when no transaction is open, else by
    -- the caller just before its transaction.
    SELECT COALESCE(MAX(CAST(setting_value AS UNSIGNED)), 60) INTO v_gap
      FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes';

    -- MIGRATION 14 (2): a locking read sees slots committed after this
    -- transaction's snapshot was taken (a plain read would not).
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
