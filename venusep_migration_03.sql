-- ============================================================================
--  VENUSeP — MIGRATION 03: email outbox, System Receipts, walk-in contact email
--  Run against an EXISTING venusep database. Safe to re-run (idempotent).
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep < venusep_migration_03.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  venusep_schema.sql carries the same columns and tables, so a FRESH build
--  needs nothing from this file. This exists only to bring an already-created
--  database up to date without rebuilding it.
--
--  WHY EACH CHANGE — the reasoning lives in venusep_schema.sql next to the
--  column/table itself and in DB-DECISIONS.md; this file only applies it.
-- ============================================================================

-- ---------------------------------------------------------------------------
-- 1. customers.contact_email — walk-ins only: the address the guest gave at
--    the counter for their booking confirmation and System Receipt.
-- ---------------------------------------------------------------------------
SET @sql = IF(
  (SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers' AND COLUMN_NAME = 'contact_email') = 0,
  'ALTER TABLE customers ADD COLUMN contact_email VARCHAR(190) NULL AFTER phone',
  'SELECT ''customers.contact_email already exists'' AS note'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------------
-- 2. system_receipts — one VENUSeP System Receipt per confirmed payment, with
--    its contents frozen in snapshot_json.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS system_receipts (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id          BIGINT UNSIGNED NOT NULL,
    payment_id          BIGINT UNSIGNED NOT NULL,
    snapshot_json       JSON NOT NULL,
    issued_at           DATETIME NOT NULL,
    issued_by_user_id   BIGINT UNSIGNED NULL,
    CONSTRAINT uq_system_receipts_payment UNIQUE (payment_id),
    KEY ix_system_receipts_booking (booking_id),
    CONSTRAINT fk_system_receipts_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_system_receipts_payment
        FOREIGN KEY (payment_id) REFERENCES payments(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_system_receipts_issuer
        FOREIGN KEY (issued_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- 3. email_outbox — one row per email; queued inside the staff action's
--    transaction, sent after commit, resent from the admin panel on failure.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_outbox (
    id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind                ENUM('walkin_booking','walkin_receipt','receipt_copy') NOT NULL,
    booking_id          BIGINT UNSIGNED NULL,
    receipt_id          BIGINT UNSIGNED NULL,
    requested_by_user_id BIGINT UNSIGNED NULL,
    to_email            VARCHAR(190) NOT NULL,
    subject             VARCHAR(255) NOT NULL,
    body_html           MEDIUMTEXT NOT NULL,
    body_text           MEDIUMTEXT NOT NULL,
    status              ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error          VARCHAR(500) NULL,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at             DATETIME NULL,
    KEY ix_outbox_booking (booking_id),
    KEY ix_outbox_receipt (receipt_id, created_at),
    CONSTRAINT fk_outbox_booking
        FOREIGN KEY (booking_id) REFERENCES bookings(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_outbox_receipt
        FOREIGN KEY (receipt_id) REFERENCES system_receipts(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_outbox_requester
        FOREIGN KEY (requested_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

SELECT 'migration 03 complete' AS status;

-- ROLLBACK (manual, only if this migration must be undone):
--   DROP TABLE IF EXISTS email_outbox;
--   DROP TABLE IF EXISTS system_receipts;
--   ALTER TABLE customers DROP COLUMN contact_email;
