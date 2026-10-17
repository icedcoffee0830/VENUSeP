-- ============================================================================
--  VENUSeP — MIGRATION 04: customer sign-in with Google, forgot password,
--  remember me (DB-DECISIONS #23)
--  Run against an EXISTING venusep database. Safe to re-run (idempotent) —
--  and run it AGAIN if you ran an earlier copy: later rounds added sections.
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_04.sql
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
-- 1. users.password_hash may be NULL — an account created through Google has
--    no password until its owner sets one. Google never gives us theirs.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT IS_NULLABLE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_hash') = 'NO',
  'ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NULL',
  'SELECT ''users.password_hash already allows NULL'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. users.google_sub — the Google account's permanent id; NULL = not linked.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_sub') = 0,
  'ALTER TABLE users ADD COLUMN google_sub VARCHAR(255) NULL AFTER password_hash',
  'SELECT ''users.google_sub already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_sub') = 0,
  'ALTER TABLE users ADD CONSTRAINT uq_users_google_sub UNIQUE (google_sub)',
  'SELECT ''uq_users_google_sub already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 3. users.password_changed_at — sessions signed in before it are ended.
--    (Added with "forgot password", round 3; re-running this file adds it.)
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_changed_at') = 0,
  'ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER last_login_at',
  'SELECT ''users.password_changed_at already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 4. password_resets — "forgot password" links, stored as hashes.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,
    token_hash          CHAR(64) NOT NULL,
    expires_at          DATETIME NOT NULL,
    used_at             DATETIME NULL,
    requested_ip        VARCHAR(45) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_password_resets_token UNIQUE (token_hash),
    KEY ix_password_resets_user (user_id, created_at),
    CONSTRAINT fk_password_resets_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 5. email_outbox.kind gains 'password_reset'.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COLUMN_TYPE FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_outbox' AND COLUMN_NAME = 'kind') NOT LIKE '%password_reset%',
  'ALTER TABLE email_outbox MODIFY COLUMN kind ENUM(''walkin_booking'',''walkin_receipt'',''receipt_copy'',''password_reset'') NOT NULL',
  'SELECT ''email_outbox.kind already has password_reset'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 6. remember_tokens — "remember me" devices, validators stored as hashes.
--    (Added with "remember me", round 4; re-running this file adds it.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS remember_tokens (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id             BIGINT UNSIGNED NOT NULL,
    selector            CHAR(24) NOT NULL,
    validator_hash      CHAR(64) NOT NULL,
    prev_validator_hash CHAR(64) NULL,
    rotated_at          DATETIME NULL,
    expires_at          DATETIME NOT NULL,
    last_used_at        DATETIME NULL,
    user_agent          VARCHAR(255) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_remember_tokens_selector UNIQUE (selector),
    KEY ix_remember_tokens_user (user_id),
    CONSTRAINT fk_remember_tokens_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

SELECT 'migration 04 complete' AS status;

-- ROLLBACK (manual, only if this migration must be undone; every account
-- without a password must be given one or deleted first):
--   DROP TABLE IF EXISTS remember_tokens;
--   DELETE FROM email_outbox WHERE kind = 'password_reset';
--   ALTER TABLE email_outbox MODIFY COLUMN kind ENUM('walkin_booking','walkin_receipt','receipt_copy') NOT NULL;
--   DROP TABLE IF EXISTS password_resets;
--   ALTER TABLE users DROP COLUMN password_changed_at;
--   ALTER TABLE users DROP INDEX uq_users_google_sub;
--   ALTER TABLE users DROP COLUMN google_sub;
--   ALTER TABLE users MODIFY COLUMN password_hash VARCHAR(255) NOT NULL;
