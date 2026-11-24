-- ============================================================================
--  VENUSeP — MIGRATION 21: the activity log records everything; customer accounts can be
--  revoked; the FAQ gets the built-in question "What do I need to book?" (all decided
--  2026-11-24).
--  Run against the EXISTING database your site uses, after 20. Safe to re-run
--  (idempotent): it only adds what is missing. No existing row changes; one FAQ row is added.
--
--  THE DATABASE NAME in these commands must be the one your site uses: DB_DATABASE in
--  laravel\.env. Aron's site uses venusep_laravel, not venusep; a build from SETUP.md
--  uses venusep. XAMPP installed somewhere else means another path to mysql.exe.
--
--      Windows (cmd, not PowerShell), from the folder that holds this file:
--          C:\xamp5\mysql\bin\mysqldump.exe -u root --routines --triggers --events --single-transaction venusep_laravel > %USERPROFILE%\venusep_laravel_before_m21.sql
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_21.sql
--
--  Then the same for the test database, so the tests that need this stop being skipped:
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel_test < venusep_migration_21.sql
--
--  It ends with one line that must read "OK: migration 21 is in: the activity log, revoked accounts and the FAQ question".
--
--  WHAT CHANGES
--    activity_log: one row per action the other tables do not already keep: sign-ins
--    (and failed ones), sign-outs, password resets and changes, suspensions and
--    reactivations, the super admin handover, two-step verification turned on or off,
--    backups made, deleted and restored, and a booking submitted. Who (user_id, NULL when
--    nobody is known, e.g. a wrong email), their role, what (action, subject, detail) and
--    from where (ip). Never a password. The Activity page (super admin) reads it next to
--    booking History, office settings, ID views, blocks, deleted rooms, covers and moves.
--
--    Revoked customer accounts (users.revoked_at, users.revoked_by_user_id): the super admin
--    closes a customer account for good. It is suspended too (is_active = 0) and can never be
--    reactivated: chk_users_revoked keeps a revoked row inactive, and only a customer can be
--    revoked. Its pending requests are cancelled by the app, its data is kept, and its email
--    can never register again (the row keeps the address; sign-in, Google and registration
--    say so). Suspension stays users.is_active, as before.
--
--    FAQ "What do I need to book?" (faqs): a built-in question, first in "Booking a venue",
--    always shown (policy 'any'). Like every built-in, FAQ Management can edit, hide and move
--    it, never delete it, and "Restore original" goes back to default_question /
--    default_answer. Its answer fills {discount} in live; the 183-day window and the 5 unpaid
--    bookings are fixed text (fixed in config/venusep.php, no token). The FAQ page adds each
--    office's notice and refund rule under it by itself (FaqController), since those are set
--    per office.
--
--    The new code works before this is run: it skips the log and the page shows the rest;
--    Revoke answers "run migration 21" and changes nothing; the FAQ simply has no
--    "What do I need to book?" yet.
--
--  ROLLBACK (nothing else reads these; a revoked account then reads as plainly suspended):
--      DELETE FROM faqs WHERE is_builtin = 1 AND default_question = 'What do I need to book?';
--      ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_revoked;
--      ALTER TABLE users DROP FOREIGN KEY fk_users_revoked_by;
--      ALTER TABLE users DROP COLUMN revoked_by_user_id, DROP COLUMN revoked_at;
--      DROP TABLE IF EXISTS activity_log;
--  Or restore the backup taken before running this, into an emptied database:
--      C:\xamp5\mysql\bin\mysql.exe -u root -e "DROP DATABASE venusep_laravel; CREATE DATABASE venusep_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--      C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < %USERPROFILE%\venusep_laravel_before_m21.sql
-- ============================================================================

SET NAMES utf8mb4;

-- ---- the activity log ----
CREATE TABLE IF NOT EXISTS activity_log (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    user_id     BIGINT UNSIGNED NULL,                        -- who; NULL = nobody known (a wrong email, the nightly task)
    role        ENUM('customer','staff','admin') NULL,       -- their account type at the time
    action      VARCHAR(60)  NOT NULL,                       -- a code: sign_in, sign_in_failed, password_reset, ...
    subject     VARCHAR(190) NOT NULL,                       -- what it was done to, in words ("Account: x@y", "Booking #12")
    detail      VARCHAR(500) NULL,                           -- what happened, in words
    ip          VARCHAR(45)  NULL,
    KEY ix_activity_log_at (occurred_at),
    CONSTRAINT fk_activity_log_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- made by an earlier run under the server's default collation: the Activity page's UNION needs this one
ALTER TABLE activity_log CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- ---- revoked customer accounts ----
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS revoked_at         DATETIME NULL,          -- set = closed for good (a customer only)
    ADD COLUMN IF NOT EXISTS revoked_by_user_id BIGINT UNSIGNED NULL;   -- the super admin who did it
ALTER TABLE users
    ADD CONSTRAINT fk_users_revoked_by FOREIGN KEY IF NOT EXISTS (revoked_by_user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL;

-- a revoked account is a customer's, and stays inactive: nobody can reactivate it
ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_revoked;
ALTER TABLE users ADD CONSTRAINT chk_users_revoked
    CHECK (revoked_at IS NULL OR (account_type = 'customer' AND is_active = 0));

-- ---- FAQ: "What do I need to book?", a built-in row (its wording kept for "Restore original") ----
-- first in "Booking a venue" (the others start at 1); added once, even if an admin edited it since
SET @m21_need = CONCAT_WS(CHAR(10),
    'Check these before you submit:',
    '- **A valid ID**, for every booking: your USeP ID or any government-issued ID. To get the USeP discount ({discount} off), it must be your **USeP ID**.',
    '- **Enough notice:** a venue booking must start far enough ahead for its office. Each office''s notice is listed below. A hostel stay can start today.',
    '- **Dates inside the booking window:** you can book up to **183 days** ahead. Your last day, or your check-out day, must fall inside it.',
    '- **No more than 5 unpaid bookings per office.** Pay or cancel one before booking with that office again.',
    '- **Hostel stays are paid by the end of the check-in day**, or the stay is released.',
    '- **The no-refund agreement:** where an office does not take refund requests (listed below), you tick a box confirming the booking is non-refundable.');
INSERT INTO faqs (section_key, sort_order, is_builtin, policy, question, answer, default_question, default_answer)
SELECT 'booking', 0, TRUE, 'any', 'What do I need to book?', @m21_need, 'What do I need to book?', @m21_need
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM faqs WHERE is_builtin = 1 AND default_question = 'What do I need to book?');

-- ---- check ----
SELECT IF((SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_log') = 1
          AND (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
                  AND COLUMN_NAME IN ('revoked_at', 'revoked_by_user_id')) = 2
          AND (SELECT COUNT(*) FROM faqs
                WHERE is_builtin = 1 AND default_question = 'What do I need to book?') = 1,
          'OK: migration 21 is in: the activity log, revoked accounts and the FAQ question',
          'NOT APPLIED: something is missing; run this file again') AS m21_check;
