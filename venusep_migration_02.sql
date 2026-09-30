-- ============================================================================
--  VENUSeP — MIGRATION 02: two-step verification (DB-DECISIONS #20)
--  Run against an EXISTING venusep database. Safe to re-run (idempotent).
--
--      mysql -u root venusep < venusep_migration_02.sql
--
--  venusep_schema.sql carries the same columns, so a FRESH build needs
--  nothing from this file. This exists only to bring an already-created
--  database up to date without rebuilding it.
--
--  WHY EACH COLUMN — the reasoning lives in venusep_schema.sql next to the
--  column itself; this file only applies the change.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. users.totp_secret — base32 TOTP secret; NULL = two-step verification off.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_secret') = 0,
  'ALTER TABLE users ADD COLUMN totp_secret VARCHAR(64) NULL AFTER reauth_locked_until',
  'SELECT ''users.totp_secret already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. users.totp_enabled_at — when two-step verification was turned on.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_enabled_at') = 0,
  'ALTER TABLE users ADD COLUMN totp_enabled_at DATETIME NULL AFTER totp_secret',
  'SELECT ''users.totp_enabled_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. users.totp_last_step — the last 30-second step accepted, so a code
--    works once.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_last_step') = 0,
  'ALTER TABLE users ADD COLUMN totp_last_step BIGINT UNSIGNED NULL AFTER totp_enabled_at',
  'SELECT ''users.totp_last_step already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. users.totp_failed_attempts — wrong codes since the last lock/success.
--    Own ladder, twin of the reauth columns, so a sign-in lockout never
--    blocks the refund switch.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_failed_attempts') = 0,
  'ALTER TABLE users ADD COLUMN totp_failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_last_step',
  'SELECT ''users.totp_failed_attempts already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 5. users.totp_lock_level — how many locks so far (picks the duration).
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_lock_level') = 0,
  'ALTER TABLE users ADD COLUMN totp_lock_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_failed_attempts',
  'SELECT ''users.totp_lock_level already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. users.totp_locked_until — NULL or past = not locked.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_locked_until') = 0,
  'ALTER TABLE users ADD COLUMN totp_locked_until DATETIME NULL AFTER totp_lock_level',
  'SELECT ''users.totp_locked_until already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 7. user_recovery_codes — single-use recovery codes for two-step
--    verification. Stored as SHA-256 of the normalised code.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_recovery_codes (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,
    code_hash           CHAR(64) NOT NULL,
    used_at             DATETIME NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_recovery_codes_user_hash UNIQUE (user_id, code_hash),
    CONSTRAINT fk_recovery_codes_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 8. sp_reset_2fa — the ONLY reset path for two-step verification. Used by
--    the demo seed and by the lost-phone runbook (DB-DECISIONS #20).
-- ---------------------------------------------------------------------------
DROP PROCEDURE IF EXISTS sp_reset_2fa;
DELIMITER $$
CREATE PROCEDURE sp_reset_2fa (IN p_user_id BIGINT UNSIGNED)
BEGIN
    UPDATE users
       SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = NULL,
           totp_failed_attempts = 0, totp_lock_level = 0, totp_locked_until = NULL
     WHERE id = p_user_id;
    DELETE FROM user_recovery_codes WHERE user_id = p_user_id;
END$$
DELIMITER ;

SELECT 'migration 02 complete' AS status;

-- ROLLBACK (manual, only if this migration must be undone):
--   DROP PROCEDURE IF EXISTS sp_reset_2fa;
--   DROP TABLE IF EXISTS user_recovery_codes;
--   ALTER TABLE users DROP COLUMN totp_locked_until, DROP COLUMN totp_lock_level,
--     DROP COLUMN totp_failed_attempts, DROP COLUMN totp_last_step,
--     DROP COLUMN totp_enabled_at, DROP COLUMN totp_secret;
