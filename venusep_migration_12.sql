-- ============================================================================
--  VENUSeP — MIGRATION 12: in-app notifications (decided 2026-10-28).
--  Run against an EXISTING venusep database, after 11. Safe to re-run.
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_12.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  notifications is everyone's inbox: one row per person per event, unread
--  until that person clicks it (read_at). The words are NOT stored — the
--  bell writes them from kind + booking when it is shown (includes/
--  notifications.php), so the wording can change without touching old rows.
--
--  WHO WRITES THEM
--    booking status changes   trg_booking_timeline_notify, on the timeline
--                             row trg_bookings_after_update already writes —
--                             so every page that changes a booking is covered
--    a new online request     customer/booking-submit.php (an INSERT, which
--                             the timeline does not record)
--    staff/admin accounts     register-submit.php, admin/staff-save.php,
--                             admin/venue-save.php (staff moved)
--  The person who acted is never notified of their own action. Read
--  notifications older than 90 days are deleted by the bell's endpoint.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS notifications (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NOT NULL,          -- who it is for
    kind          VARCHAR(40) NOT NULL,              -- see includes/notifications.php
    booking_id    BIGINT UNSIGNED NULL,              -- the booking it is about, if any
    actor_user_id BIGINT UNSIGNED NULL,              -- who did it (NULL = the system)
    detail        TEXT NULL,                         -- JSON: {pay, note} for booking events, {email, role, venue} for accounts
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at       DATETIME NULL,                     -- NULL = unread
    KEY idx_notifications_inbox (user_id, read_at, id),
    CONSTRAINT fk_notifications_user    FOREIGN KEY (user_id)       REFERENCES users (id)    ON DELETE CASCADE,
    CONSTRAINT fk_notifications_booking FOREIGN KEY (booking_id)    REFERENCES bookings (id) ON DELETE CASCADE,
    CONSTRAINT fk_notifications_actor   FOREIGN KEY (actor_user_id) REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER $$

-- Staff of the booking's venue + every admin, except whoever acted.
DROP PROCEDURE IF EXISTS sp_notify_venue_staff$$
CREATE PROCEDURE sp_notify_venue_staff (
    IN p_booking_id BIGINT UNSIGNED, IN p_kind VARCHAR(40),
    IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    DECLARE v_venue BIGINT UNSIGNED;
    SELECT r.venue_id INTO v_venue FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = p_booking_id;
    INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
    SELECT u.id, p_kind, p_booking_id, p_actor_user_id, p_detail
      FROM users u LEFT JOIN staff s ON s.user_id = u.id
     WHERE u.is_active = 1 AND u.id <> COALESCE(p_actor_user_id, 0)
       AND (u.account_type = 'admin' OR (u.account_type = 'staff' AND s.venue_id = v_venue));
END$$

-- The booking's customer, if they have an account (a walk-in has no login),
-- unless they did it themselves.
DROP PROCEDURE IF EXISTS sp_notify_customer$$
CREATE PROCEDURE sp_notify_customer (
    IN p_booking_id BIGINT UNSIGNED, IN p_kind VARCHAR(40),
    IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
    SELECT c.user_id, p_kind, p_booking_id, p_actor_user_id, p_detail
      FROM bookings b JOIN customers c ON c.id = b.customer_id
     WHERE b.id = p_booking_id AND c.user_id IS NOT NULL AND c.user_id <> COALESCE(p_actor_user_id, 0);
END$$

-- Every active admin, except whoever acted (staff/admin account events).
DROP PROCEDURE IF EXISTS sp_notify_admins$$
CREATE PROCEDURE sp_notify_admins (
    IN p_kind VARCHAR(40), IN p_actor_user_id BIGINT UNSIGNED, IN p_detail TEXT
)
BEGIN
    INSERT INTO notifications (user_id, kind, actor_user_id, detail)
    SELECT u.id, p_kind, p_actor_user_id, p_detail
      FROM users u
     WHERE u.account_type = 'admin' AND u.is_active = 1 AND u.id <> COALESCE(p_actor_user_id, 0);
END$$

-- Every status change already lands in booking_timeline; this decides which
-- ones someone should hear about, and who. First match wins, so the order
-- matters: a staff rejection of a receipt (under_review -> await_*) is a
-- "receipt rejected" for the customer, not a "cash chosen" for staff.
DROP TRIGGER IF EXISTS trg_booking_timeline_notify$$
CREATE TRIGGER trg_booking_timeline_notify AFTER INSERT ON booking_timeline
FOR EACH ROW
BEGIN
    DECLARE v_kind VARCHAR(40) DEFAULT NULL;
    DECLARE v_staff BOOLEAN DEFAULT FALSE;     -- FALSE: the customer; TRUE: venue staff + admins

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

DELIMITER ;
