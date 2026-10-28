-- ============================================================================
--  VENUSeP — MIGRATION 08: every staff member handles exactly one venue
--  (decided 2026-10-27). Run against an EXISTING venusep database, after 07.
--  Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_08.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  Staff act only on their own venue's bookings (staff_may_handle_venue() in
--  includes/auth.php), so a staff account without a venue could handle
--  nothing. These triggers refuse one. Admin rows may still have no venue.
--
--  NOTHING TO PREPARE: a staff account that has no venue yet — or no staff
--  profile row at all — is placed in the FIRST active venue (lowest id) before
--  the triggers go in. The file prints who it placed; an admin moves them to
--  the right venue in Staff Management if that guess is wrong. Accounts that
--  already have a venue are not touched.
-- ============================================================================

SET @m08_venue = (SELECT id FROM venues WHERE is_active = 1 ORDER BY id LIMIT 1);

CREATE TEMPORARY TABLE IF NOT EXISTS tmp_m08_placed (user_id BIGINT UNSIGNED PRIMARY KEY);
DELETE FROM tmp_m08_placed;
INSERT IGNORE INTO tmp_m08_placed (user_id)
    SELECT u.id FROM users u LEFT JOIN staff s ON s.user_id = u.id
     WHERE u.account_type = 'staff' AND s.venue_id IS NULL AND @m08_venue IS NOT NULL;

-- a staff login with no staff profile row gets one
INSERT INTO staff (user_id, venue_id, full_name)
    SELECT u.id, @m08_venue, COALESCE(NULLIF(u.username, ''), u.email)
      FROM users u
     WHERE u.account_type = 'staff' AND @m08_venue IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.id);

-- a staff profile with no venue gets the default one
UPDATE staff s JOIN users u ON u.id = s.user_id
   SET s.venue_id = @m08_venue
 WHERE u.account_type = 'staff' AND s.venue_id IS NULL AND @m08_venue IS NOT NULL;

-- who was placed (prints nothing when everyone already had a venue)
SELECT u.email AS staff_placed, v.name AS in_venue, 'move them in Staff Management if wrong' AS note
  FROM tmp_m08_placed t
  JOIN users u  ON u.id = t.user_id
  JOIN staff s  ON s.user_id = u.id
  JOIN venues v ON v.id = s.venue_id;
DROP TEMPORARY TABLE tmp_m08_placed;

DELIMITER $$

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

DROP TRIGGER IF EXISTS trg_staff_venue_required_upd$$
CREATE TRIGGER trg_staff_venue_required_upd BEFORE UPDATE ON staff
FOR EACH ROW
BEGIN
    IF NEW.venue_id IS NULL
       AND (SELECT account_type FROM users WHERE id = NEW.user_id) = 'staff' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Every staff member must handle a venue.';
    END IF;
END$$

DELIMITER ;
