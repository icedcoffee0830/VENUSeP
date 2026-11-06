-- ============================================================================
--  VENUSeP — MIGRATION 20: an email that never left is not "sent" (decided 2026-11-06).
--  Run against the EXISTING database your site uses, after 19. Safe to re-run (idempotent):
--  it only widens one list. No row changes.
--
--  THE DATABASE NAME in these commands must be the one your site uses: DB_DATABASE in
--  laravel\.env. Aron's site uses venusep_laravel, not venusep; a build from SETUP.md
--  uses venusep. XAMPP installed somewhere else means another path to mysql.exe.
--
--      Windows (cmd, not PowerShell), from the folder that holds this file:
--          C:\xamp5\mysql\bin\mysqldump.exe -u root --routines --triggers --events --single-transaction venusep_laravel > %USERPROFILE%\venusep_laravel_before_m20.sql
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_20.sql
--
--  It ends with one line that must read "OK: migration 20 is in: email_outbox can say not_sent".
--
--  WHAT CHANGES
--    email_outbox.status gains 'not_sent': this laptop has no mail-config.php, so the email was
--    only written to mail-log and nothing left the machine. Staff read "Not sent: email isn't set
--    up on this laptop" and can press Resend once the settings exist.
--
--    Older rows are left alone. An email the 'log' transport handled before this still says
--    'sent', because the table cannot show which ones those were.
--
--    Run this BEFORE the new code. Until then, the new code's 'not_sent' is refused and the row
--    says 'failed' with the database's message; the old code works the same on the new list.
--
--  ROLLBACK: the list cannot lose a value still in use, so move those rows first (Resend still
--  works on 'failed'):
--      UPDATE email_outbox SET status = 'failed' WHERE status = 'not_sent';
--      ALTER TABLE email_outbox MODIFY status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending';
--  Or restore the backup taken before running this:
--      C:\xamp5\mysql\bin\mysql.exe -u root -e "DROP DATABASE venusep_laravel; CREATE DATABASE venusep_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < %USERPROFILE%\venusep_laravel_before_m20.sql
-- ============================================================================

SET NAMES utf8mb4;

ALTER TABLE email_outbox
    MODIFY status ENUM('pending','sent','failed','not_sent') NOT NULL DEFAULT 'pending';

-- ---- check ----
SELECT IF((SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_outbox' AND COLUMN_NAME = 'status') LIKE '%not_sent%',
          'OK: migration 20 is in: email_outbox can say not_sent',
          'NOT APPLIED: email_outbox cannot say not_sent yet; run this file again') AS m20_check;
