-- ============================================================================
--  VENUSeP — MIGRATION 19: rounding never cancels the USeP discount, and a booking is
--  never free (decided 2026-11-05, option B). Also the FAQ wording: the refund rule and
--  the receipt's new name, and what customers read on a written-off booking (decided 2026-11-06).
--  Run against the EXISTING database your site uses, after 18. Safe to re-run
--  (idempotent): it replaces sp_approve_booking and rewords FAQ text and the one status label
--  that still have the old words. No table and no stored amount changes.
--
--  THE DATABASE NAME in these commands must be the one your site uses: DB_DATABASE in
--  laravel\.env. Aron's site uses venusep_laravel, not venusep; a build from SETUP.md
--  uses venusep. XAMPP installed somewhere else means another path to mysql.exe.
--
--      Windows (cmd, not PowerShell), from the folder that holds this file:
--          C:\xamp5\mysql\bin\mysqldump.exe -u root --routines --triggers --events --single-transaction venusep_laravel > %USERPROFILE%\venusep_laravel_before_m19.sql
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_19.sql
--
--  It ends with one line that must read "OK: migration 19 is in: the rounding, the FAQ wording and the written-off label".
--
--  WHAT CHANGES
--    The USeP discount at approval, in sp_approve_booking (every approval goes through it:
--    staff approving a request, and the counter). Migration 18 rounded the final price UP to
--    the whole peso. Now:
--      - UP to the whole peso, as before: 1,999 -> 1,599.20 -> 1,600;
--      - DOWN instead when rounding up would give back the full price (the discount
--        cancelled): 3 -> 2.40 -> 2, not 3;
--      - never free: when rounding down would give 0 (1 -> 0.80 -> 0), the full price stays
--        and there is no discount (discount_amount 0; discount_percent keeps the rate given).
--      An exact amount is unchanged (4,000 -> 3,200). The discount is always the rest
--      (room price - final price). Bookings approved before this keep their amounts.
--
--    The FAQ, built-in answers and their "Restore" copies (faqs.answer, faqs.default_answer):
--      - "What if USeP closes or cancels my venue?" no longer promises the payment back: with
--        refunds off nothing is refundable, even when the office closes the room. Only the
--        exact old text changes, so an answer someone reworded is left alone: read that one
--        on the FAQ page afterwards;
--      - "VENUSeP System Receipt" becomes "VENUSeP Transaction Receipt", wherever it is used.
--
--    The written-off status (payment_statuses.customer_label): customers read "Closed by the
--    office", not "Closed". Staff keep "Written off". Only the old word changes, so a label
--    someone reworded is left alone.
--
--  ROLLBACK: for the rounding alone, run sp_approve_booking from venusep_migration_18.sql
--  again (from its "DROP PROCEDURE IF EXISTS sp_approve_booking$$" to its "END$$", between a
--  DELIMITER $$ and a DELIMITER ;). For everything, the FAQ wording too, restore the backup
--  taken before running this:
--      C:\xamp5\mysql\bin\mysql.exe -u root -e "DROP DATABASE venusep_laravel; CREATE DATABASE venusep_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < %USERPROFILE%\venusep_laravel_before_m19.sql
-- ============================================================================

SET NAMES utf8mb4;

DELIMITER $$

-- Migration 18's procedure; only the rounding step is new.
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
    DECLARE v_price DECIMAL(12,2);
    DECLARE v_exact DECIMAL(12,2);
    DECLARE v_final DECIMAL(12,2);

    SELECT booking_type, refunds_allowed, pay_to_use, payment_method INTO v_type, v_prepay, v_pay_to_use, v_method
      FROM bookings WHERE id = p_booking_id AND reservation_status = 'pending';
    IF v_type IS NULL THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a pending booking can be approved.';
    END IF;

    -- The USeP discount's final price: up to the whole peso; down when up gives back the
    -- full price; never 0 (then the full price and no discount).
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

-- ---- FAQ wording ----
-- Exact old text only, so a reworded answer stays; the second run finds nothing to change.
SET @m19_old_close = 'If USeP has to close a room you have booked — for maintenance or any other reason — the venue office will offer you a **replacement room** or a **new date**. If you cannot accept either, your payment is returned in full. This does not depend on the refund policy.';
SET @m19_new_close = 'If USeP has to close a room you have booked — for maintenance or any other reason — the venue office will offer you a **replacement room** or a **new date**. Each office decides whether it takes refund requests, and your booking keeps the rule that applied when you made it. If the rule was on, you can request a refund. If it was off, nothing can be refunded through VENUSeP, even when the office closes the room.';
UPDATE faqs SET answer = @m19_new_close WHERE answer = @m19_old_close COLLATE utf8mb4_bin;
UPDATE faqs SET default_answer = @m19_new_close WHERE default_answer = @m19_old_close COLLATE utf8mb4_bin;

UPDATE faqs SET answer = REPLACE(answer, 'VENUSeP System Receipt', 'VENUSeP Transaction Receipt')
 WHERE answer LIKE '%VENUSeP System Receipt%';
UPDATE faqs SET default_answer = REPLACE(default_answer, 'VENUSeP System Receipt', 'VENUSeP Transaction Receipt')
 WHERE default_answer LIKE '%VENUSeP System Receipt%';

-- ---- what customers read on a written-off booking ----
UPDATE payment_statuses SET customer_label = 'Closed by the office'
 WHERE code = 'written_off' AND customer_label = 'Closed';

-- ---- check ----
SELECT IF((SELECT COUNT(*) FROM information_schema.ROUTINES
            WHERE ROUTINE_SCHEMA = DATABASE() AND ROUTINE_NAME = 'sp_approve_booking'
              AND ROUTINE_DEFINITION LIKE '%FLOOR(v_exact)%') = 1
          AND (SELECT COUNT(*) FROM faqs
                WHERE answer = @m19_old_close COLLATE utf8mb4_bin OR default_answer = @m19_old_close COLLATE utf8mb4_bin
                   OR answer LIKE '%VENUSeP System Receipt%' OR default_answer LIKE '%VENUSeP System Receipt%') = 0
          AND (SELECT COUNT(*) FROM payment_statuses WHERE code = 'written_off' AND customer_label = 'Closed') = 0,
          'OK: migration 19 is in: the rounding, the FAQ wording and the written-off label',
          'NOT APPLIED: the rounding, the FAQ wording or the written-off label is still the old one; run this file again') AS m19_check;
