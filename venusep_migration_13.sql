-- ============================================================================
--  VENUSeP — MIGRATION 13: the team's new staff and admin accounts
--  (added 2026-10-28). Run against an EXISTING venusep database, after 12.
--  Safe to re-run: an account whose email already exists is left alone.
--
--      Windows (cmd, or PowerShell via cmd /c):
--          C:\xampp2\mysql\bin\mysql.exe -u root venusep < venusep_migration_13.sql
--      Mac / phpMyAdmin: Import this file into the venusep database.
--
--  DATA, not structure: the accounts created on Francis's machine, so every
--  copy of the system has them. admin@gmail.com and staff@gmail.com are not
--  here — the schema and the demo seed already create them.
--    jakemerson@gmail.com       staff (USeP Hostel)
--    macocase@gmail.com         staff (Bahay Alumni)
--    francishubero@gmail.com    admin (every venue)
--
--  - Same passwords as on Francis's machine (the Argon2id hashes are copied).
--  - NO two-step verification secret is copied: an admin sets up their own
--    authenticator at their first sign-in, like any new admin.
--  - Accounts are matched by EMAIL and venues by venue_code, because the
--    numeric ids differ between databases. A staff member whose venue does
--    not exist here goes to the first active venue (move them in Staff
--    Management). A taken username gets "_2"; a taken employee number is
--    left empty.
-- ============================================================================

SET NAMES utf8mb4;

-- jakemerson@gmail.com
INSERT INTO users (email, username, password_hash, account_type, is_active, created_at)
SELECT 'jakemerson@gmail.com',
       IF(EXISTS (SELECT 1 FROM users WHERE username = 'jakemerson'), CONCAT('jakemerson', '_2'), 'jakemerson'),
       '$argon2id$v=19$m=19456,t=2,p=1$LmhMeElFTGZCcmRXQkFGOA$OAdSgIp0BOQhrWNgqwyDHZToGGq6iRB0MGfYA8Ef0ZU',
       'staff', 1, '2026-10-28 18:43:00'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'jakemerson@gmail.com');
INSERT INTO staff (user_id, venue_id, full_name, employee_no, position_role, phone)
SELECT u.id, COALESCE((SELECT id FROM venues WHERE venue_code = 'USEP-HOSTEL'),
                (SELECT id FROM venues WHERE is_active = 1 ORDER BY id LIMIT 1)),
       'Jake Emerson',
       NULL,
       NULL, '09122334376'
  FROM users u WHERE u.email = 'jakemerson@gmail.com' AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.id);

-- macocase@gmail.com
INSERT INTO users (email, username, password_hash, account_type, is_active, created_at)
SELECT 'macocase@gmail.com',
       IF(EXISTS (SELECT 1 FROM users WHERE username = 'macocase'), CONCAT('macocase', '_2'), 'macocase'),
       '$argon2id$v=19$m=19456,t=2,p=1$eEI0NkNCSS5idmJjZnpOag$ZnVWf2fndbbgSJ12fIt8Hs0aSnzzUhxsxRERTPSIZdM',
       'staff', 1, '2026-10-28 18:44:01'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'macocase@gmail.com');
INSERT INTO staff (user_id, venue_id, full_name, employee_no, position_role, phone)
SELECT u.id, COALESCE((SELECT id FROM venues WHERE venue_code = 'BAHAY-ALUMNI'),
                (SELECT id FROM venues WHERE is_active = 1 ORDER BY id LIMIT 1)),
       'Maco Case',
       NULL,
       NULL, '09123671237'
  FROM users u WHERE u.email = 'macocase@gmail.com' AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.id);

-- francishubero@gmail.com
INSERT INTO users (email, username, password_hash, account_type, is_active, created_at)
SELECT 'francishubero@gmail.com',
       IF(EXISTS (SELECT 1 FROM users WHERE username = 'francishubero'), CONCAT('francishubero', '_2'), 'francishubero'),
       '$argon2id$v=19$m=19456,t=2,p=1$elRVbFBrdU9MS1RMei9QaA$vPHn6vEJF5+ZUMczHSWPB0MJ5s6gRxyC8g7bFVcdmcI',
       'admin', 1, '2026-10-28 18:45:18'
  FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM users WHERE email = 'francishubero@gmail.com');
INSERT INTO staff (user_id, venue_id, full_name, employee_no, position_role, phone)
SELECT u.id, NULL,
       'Francis Hubero',
       NULL,
       NULL, '09914302358'
  FROM users u WHERE u.email = 'francishubero@gmail.com' AND NOT EXISTS (SELECT 1 FROM staff s WHERE s.user_id = u.id);

-- who is here now (all of them, whether added just now or already present)
SELECT u.email AS account, u.account_type AS role, COALESCE(v.name, 'every venue') AS venue
  FROM users u LEFT JOIN staff s ON s.user_id = u.id LEFT JOIN venues v ON v.id = s.venue_id
 WHERE u.email IN ('jakemerson@gmail.com', 'macocase@gmail.com', 'francishubero@gmail.com')
 ORDER BY u.account_type, u.email;
