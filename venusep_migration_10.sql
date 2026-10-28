-- ============================================================================
--  VENUSeP — MIGRATION 10: every room detail editable (decided 2026-10-27).
--  Run against an EXISTING venusep database, after 09. Safe to re-run.
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_10.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  The room form now saves everything it shows: capacity, rate, description,
--  maintenance window, beds and check-in/out times (admin/room-save.php).
--  The "at a glance" lines (best for / catering / accessibility) were
--  hard-coded per room in includes/amenities.php; they get columns here so
--  the form can edit them. NULL keeps the old hard-coded text, so nothing a
--  customer sees changes until someone edits it.
-- ============================================================================

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'best_for') = 0,
  'ALTER TABLE rooms ADD COLUMN best_for VARCHAR(255) NULL AFTER amenities,
                     ADD COLUMN catering VARCHAR(255) NULL AFTER best_for,
                     ADD COLUMN accessibility VARCHAR(255) NULL AFTER catering',
  'SELECT ''rooms.best_for / catering / accessibility already exist'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
