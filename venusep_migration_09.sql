-- ============================================================================
--  VENUSeP — MIGRATION 09: same day, different time, with a cleanup gap
--  (decided 2026-10-27). Run against an EXISTING venusep database, after 08.
--  Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_09.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  WHAT CHANGES
--    1. system_settings.venue_cleanup_gap_minutes = 60 (new): the time kept free
--       before and after every venue booking. The booking page and the server
--       read this same value.
--    2. sp_add_venue_slot: a room can take several bookings on one date, as long
--       as each keeps that gap from the others. Before, the page blocked the
--       whole date as soon as anything was booked on it.
--       Also fixed: a PAID pre-pay booking no longer stops counting as "held"
--       once its day-before payment deadline passes, which let its event day be
--       booked over.
--    Hostel beds are untouched.
-- ============================================================================

INSERT INTO system_settings (setting_key, setting_value, value_type, description) VALUES
  ('venue_cleanup_gap_minutes', '60', 'integer', 'Venue rooms: minutes kept free before and after every booking for cleanup and setup, so one room can take several bookings on the same day (2026-10-27). Not used for hostel beds.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

DELIMITER $$

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

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        IF v_got = 1 THEN DO RELEASE_LOCK(v_lock); END IF;
        RESIGNAL;
    END;

    CALL sp_expire_due_bookings();

    IF p_end_time <= p_start_time THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Slot end time must be later than start time.';
    END IF;

    SELECT room_id, booking_type INTO v_room_id, v_type FROM bookings WHERE id = p_booking_id;
    IF v_type <> 'venue' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Venue slots are only for venue bookings.';
    END IF;

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
      AND COALESCE(until_date, '9999-12-31') >= p_slot_date;
    IF v_block > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'HARD maintenance on the requested date.';
    END IF;

    -- SAME DAY, DIFFERENT TIME (decided 2026-10-27): a room can hold several
    -- bookings on one date, but each needs the CLEANUP GAP (system_settings
    -- venue_cleanup_gap_minutes, 60) before and after it. Venue rooms only;
    -- hostel beds are per night.
    -- Held = a live booking's unreleased slot. The old extra test, "deadline
    -- still in the future", let a PAID pre-pay booking be booked over on its
    -- event day (its day-before deadline had passed). Lapsed holds are already
    -- released by sp_expire_due_bookings() at the top of this procedure.
    SELECT COALESCE(MAX(CAST(setting_value AS UNSIGNED)), 60) INTO v_gap
      FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes';

    SELECT COUNT(*) INTO v_conflict
    FROM venue_booking_slots s
    JOIN bookings b ON b.id = s.booking_id
    WHERE s.room_id = v_room_id
      AND s.slot_date = p_slot_date
      AND s.released_at IS NULL
      AND b.reservation_status IN ('pending','approved')
      AND p_start_time < ADDTIME(s.end_time, SEC_TO_TIME(v_gap * 60))
      AND ADDTIME(p_end_time, SEC_TO_TIME(v_gap * 60)) > s.start_time;
    IF v_conflict > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That time is too close to another booking: bookings need a cleanup gap before and after them.';
    END IF;

    INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time)
        VALUES (p_booking_id, v_room_id, p_slot_date, p_start_time, p_end_time);

    DO RELEASE_LOCK(v_lock);
END$$

DELIMITER ;
