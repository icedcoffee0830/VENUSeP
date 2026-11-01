-- ============================================================================
--  VENUSeP — MIGRATION 14: no venue double booking under real concurrency
--  (decided 2026-10-30). Run against an EXISTING venusep database, after 13.
--  Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_14.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  WHAT CHANGES — sp_add_venue_slot only (no tables, no data)
--    The bug: two people booking the same room, date and hour a moment apart
--    could BOTH succeed. The procedure's GET_LOCK was released when the
--    procedure returned, but the booking pages commit later, and the conflict
--    count was a plain read of the transaction's snapshot — so the second
--    session neither waited for the first one's commit nor saw its slot.
--    The fix:
--      1. The room's event_room_details row is locked (SELECT ... FOR UPDATE)
--         before anything is checked. A row lock is held until the CALLER
--         commits or rolls back, so a second booking of the same room waits
--         here until the first one is saved or abandoned.
--         Why that row and not the rooms row: the caller has already inserted
--         its bookings row, and the foreign key check put a SHARED lock on the
--         rooms row. Two sessions upgrading their shared locks to FOR UPDATE
--         deadlock each other; event_room_details (one row per venue room)
--         is not referenced by any foreign key, so nobody holds it early.
--      2. The conflict count is a locking read (LOCK IN SHARE MODE), so it
--         reads the latest committed slots instead of the transaction's
--         older snapshot.
--    GET_LOCK stays (it still covers a caller that runs without a
--    transaction); it is now taken AFTER the row lock, so a multi-day booking
--    never waits on a GET_LOCK held by someone who is waiting on its row.
--    Same refusals, same messages: the second booking is told the time is too
--    close to another booking. Hostel beds are untouched.
--
--  ROLLBACK: re-run venusep_migration_09.sql's sp_add_venue_slot block (the
--  DROP PROCEDURE / CREATE PROCEDURE between its DELIMITER lines). It is the
--  procedure below without the two changes marked "MIGRATION 14".
-- ============================================================================

SET NAMES utf8mb4;

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
    DECLARE v_room_lock INT DEFAULT 0;

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

    -- MIGRATION 14 (1): lock the room until the caller commits. See header.
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
