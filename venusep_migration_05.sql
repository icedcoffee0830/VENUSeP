-- ============================================================================
--  VENUSeP — MIGRATION 05: every GCash receipt verdict recorded, and the
--  5-attempt rule (DB-DECISIONS #6, updated 2026-10-26)
--  Run against an EXISTING venusep database. Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_05.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  venusep_schema.sql carries the same columns, so a FRESH build needs
--  nothing from this file. This exists only to bring an already-created
--  database up to date without rebuilding it.
--
--  WHY EACH CHANGE — the reasoning lives in venusep_schema.sql next to the
--  column itself and in DB-DECISIONS.md; this file only applies it.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. gcash_receipts.payment_id may be NULL — an upload the checker REJECTED
--    is kept as evidence, but it is not a payment: no money is claimed.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND COLUMN_NAME = 'payment_id') = 'NO',
  'ALTER TABLE gcash_receipts MODIFY COLUMN payment_id BIGINT UNSIGNED NULL',
  'SELECT ''gcash_receipts.payment_id already allows NULL'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. The anti-reuse rules apply only to receipts that were NOT rejected.
--    A rejected upload stays on record but blocks nothing — otherwise one
--    misread of a genuine receipt would burn its reference number forever.
--    Generated columns (NULL for a rejected row) carry the UNIQUE indexes;
--    the old indexes on the raw columns go once the new ones exist.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND COLUMN_NAME = 'active_reference') = 0,
  'ALTER TABLE gcash_receipts ADD COLUMN active_reference VARCHAR(100)
     GENERATED ALWAYS AS (IF(verdict = ''rejected'', NULL, reference_number)) STORED AFTER reference_number',
  'SELECT ''gcash_receipts.active_reference already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND COLUMN_NAME = 'active_sha256') = 0,
  'ALTER TABLE gcash_receipts ADD COLUMN active_sha256 CHAR(64)
     GENERATED ALWAYS AS (IF(verdict = ''rejected'', NULL, sha256_hash)) STORED AFTER sha256_hash',
  'SELECT ''gcash_receipts.active_sha256 already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND INDEX_NAME = 'uq_gcash_active_reference') = 0,
  'ALTER TABLE gcash_receipts ADD CONSTRAINT uq_gcash_active_reference UNIQUE (active_reference)',
  'SELECT ''uq_gcash_active_reference already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND INDEX_NAME = 'uq_gcash_active_file') = 0,
  'ALTER TABLE gcash_receipts ADD CONSTRAINT uq_gcash_active_file UNIQUE (active_sha256)',
  'SELECT ''uq_gcash_active_file already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND INDEX_NAME = 'uq_gcash_reference') > 0,
  'ALTER TABLE gcash_receipts DROP INDEX uq_gcash_reference',
  'SELECT ''uq_gcash_reference already gone'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'gcash_receipts' AND INDEX_NAME = 'uq_gcash_file_hash') > 0,
  'ALTER TABLE gcash_receipts DROP INDEX uq_gcash_file_hash',
  'SELECT ''uq_gcash_file_hash already gone'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. bookings.receipt_attempts_allowed — the 5-attempt rule. Every GCash
--    upload counts (rejected ones too); staff can raise this for one booking.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'receipt_attempts_allowed') = 0,
  'ALTER TABLE bookings ADD COLUMN receipt_attempts_allowed SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER current_deadline_at',
  'SELECT ''bookings.receipt_attempts_allowed already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT 'migration 05 complete' AS status;

-- ROLLBACK (manual, only if this migration must be undone; rejected receipts
-- with no payment must be deleted first, and duplicates among them resolved):
--   ALTER TABLE bookings DROP COLUMN receipt_attempts_allowed;
--   ALTER TABLE gcash_receipts ADD CONSTRAINT uq_gcash_reference UNIQUE (reference_number);
--   ALTER TABLE gcash_receipts ADD CONSTRAINT uq_gcash_file_hash UNIQUE (sha256_hash);
--   ALTER TABLE gcash_receipts DROP INDEX uq_gcash_active_reference, DROP INDEX uq_gcash_active_file;
--   ALTER TABLE gcash_receipts DROP COLUMN active_reference, DROP COLUMN active_sha256;
--   ALTER TABLE gcash_receipts MODIFY COLUMN payment_id BIGINT UNSIGNED NOT NULL;
