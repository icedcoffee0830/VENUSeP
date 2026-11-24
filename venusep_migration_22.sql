-- ============================================================================
--  VENUSeP — MIGRATION 22: the database says which migration it is at (server transfer).
--  Run against the EXISTING database your site uses, after 21. Safe to re-run.
--
--  THE DATABASE NAME in these commands must be the one your site uses: DB_DATABASE in
--  laravel\.env (Aron's site: venusep_laravel).
--
--      Windows (cmd, not PowerShell), from the folder that holds this file:
--          C:\xamp5\mysql\bin\mysqldump.exe -u root --routines --triggers --events --single-transaction venusep_laravel > %USERPROFILE%\venusep_laravel_before_m22.sql
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel < venusep_migration_22.sql
--
--  Then the same for the test database:
--          C:\xamp5\mysql\bin\mysql.exe -u root venusep_laravel_test < venusep_migration_22.sql
--
--  It ends with one line that must read "OK: migration 22 is in: the database knows its migration level".
--
--  WHAT CHANGES
--    system_settings gets one row, schema_version = 22. The Server page's Test reads it on
--    another laptop's database and refuses to switch to one at a different level than this
--    code (backup_schema_version(), the highest migration file). No existing row changes.
--
--  RULE FROM NOW ON: every later migration sets schema_version to its own number, the same
--  way this one does (the last statement before its check).
-- ============================================================================

INSERT INTO system_settings (setting_key, setting_value, value_type, description)
VALUES ('schema_version', '22', 'integer', 'The last migration run on this database. Every migration sets it.')
ON DUPLICATE KEY UPDATE setting_value = '22';

-- ---- check ----
SELECT IF((SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version') = '22',
          'OK: migration 22 is in: the database knows its migration level',
          'NOT APPLIED: something is missing; run this file again') AS m22_check;
