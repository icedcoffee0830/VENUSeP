-- ============================================================================
--  VENUSeP — MIGRATION 01: the database phase
--  Run against an EXISTING venusep database. Safe to re-run (idempotent).
--
--      mysql -u root venusep < venusep_migration_01.sql
--
--  venusep_schema.sql carries the same columns, so a FRESH build needs
--  nothing from this file. This exists only to bring an already-created
--  database up to date without rebuilding it.
--
--  WHY EACH COLUMN — the reasoning lives in venusep_schema.sql next to the
--  column itself; this file only applies the change.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. rooms.amenities — which amenities THIS room has.
--    The amenity vocabulary (labels + icons) stays PHP-coded (DB-DECISIONS #9);
--    only the per-room on/off state is stored, as a list of KEYS. Keys, not
--    labels, so rewording a label in PHP updates every room at once.
--    NOTE: on MariaDB `JSON` is an alias for LONGTEXT, so the CHECK is what
--    actually enforces well-formed JSON.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND COLUMN_NAME = 'amenities') = 0,
  'ALTER TABLE rooms ADD COLUMN amenities JSON NULL COMMENT ''amenity keys; vocabulary is PHP-coded'' AFTER description',
  'SELECT ''rooms.amenities already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rooms' AND CONSTRAINT_NAME = 'chk_rooms_amenities_json') = 0,
  'ALTER TABLE rooms ADD CONSTRAINT chk_rooms_amenities_json CHECK (amenities IS NULL OR JSON_VALID(amenities))',
  'SELECT ''chk_rooms_amenities_json already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. customers.photo_path / staff.photo_path — PROFILE PICTURES ONLY.
--    Never an ID, a receipt or any other document: those are booking_documents
--    rows, stored outside the web root and served through a permission check.
--    A profile picture is served straight out of assets/ like any other image,
--    which is exactly why nothing sensitive may ever be put here.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'photo_path') = 0,
  'ALTER TABLE customers ADD COLUMN photo_path VARCHAR(500) NULL COMMENT ''profile picture only — never an ID or document'' AFTER university_id_no',
  'SELECT ''customers.photo_path already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'photo_path') = 0,
  'ALTER TABLE staff ADD COLUMN photo_path VARCHAR(500) NULL COMMENT ''profile picture only — never an ID or document'' AFTER phone',
  'SELECT ''staff.photo_path already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. staff.venue_id — one current venue assignment per staff profile.
--    NULL preserves every existing row and is valid for unassigned staff/admins.
--    Deleting a venue clears the assignment; it never deletes the account.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND COLUMN_NAME = 'venue_id') = 0,
  'ALTER TABLE staff ADD COLUMN venue_id BIGINT UNSIGNED NULL AFTER user_id',
  'SELECT ''staff.venue_id already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'staff' AND CONSTRAINT_NAME = 'fk_staff_venue') = 0,
  'ALTER TABLE staff ADD CONSTRAINT fk_staff_venue FOREIGN KEY (venue_id) REFERENCES venues(id) ON UPDATE CASCADE ON DELETE SET NULL',
  'SELECT ''fk_staff_venue already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. system_settings rows.
--    demo_mode — the showcase switch. A ROW, not a column, so removing demo
--    mode later is one DELETE and leaves no trace in the schema.
--    discount_percent — already seeded by venusep_schema.sql; the INSERT below
--    is a no-op there and a repair on any database that lost it.
-- ---------------------------------------------------------------------------
INSERT INTO system_settings (setting_key, setting_value, value_type, description)
VALUES ('demo_mode', '0', 'boolean',
        'Showcase mode: booking/payment/refund writes go to the session instead of the database, so a demo changes nothing. Setup (venues, rooms, settings) still writes for real. Admin-only.')
ON DUPLICATE KEY UPDATE description = VALUES(description);

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
VALUES ('discount_percent', '20', 'integer', 'USeP affiliate discount, percent. Snapshotted onto each booking at booking time.')
ON DUPLICATE KEY UPDATE setting_key = setting_key;

SELECT 'migration 01 complete' AS status;
