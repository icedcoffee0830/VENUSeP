<?php
/* =====================================================================
   PASSWORDS — the one place a password hash is made (DB-DECISIONS #21).

   Argon2id with OWASP's recommended minimum settings: 19 MiB of memory,
   2 passes, 1 thread. Deliberately not heavier while the login pages have
   no password-attempt limit: every attempt costs this much server time and
   memory. To strengthen it later, change VENUSEP_PASSWORD_OPTIONS; every
   stored hash upgrades on its owner's next login (venusep_password_upgrade()).

   ARGON2ID IS REQUIRED for making a hash: on a PHP build without it,
   venusep_password_hash() throws and the caller shows
   VENUSEP_PASSWORD_SETUP_ERROR. Logging in still works there, because
   password_verify() reads both bcrypt and Argon2id hashes.
   ===================================================================== */

const VENUSEP_PASSWORD_OPTIONS = ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1];

const VENUSEP_PASSWORD_SETUP_ERROR = 'This server cannot store passwords securely yet (its PHP is missing Argon2id), so nothing was saved. Please tell the system administrator.';

/* Can this PHP make Argon2id hashes? */
function venusep_password_ready(): bool
{
    return defined('PASSWORD_ARGON2ID') && in_array('argon2id', password_algos(), true);
}

/* The only way to make a password hash. Throws RuntimeException without Argon2id. */
function venusep_password_hash(string $plain): string
{
    if (!venusep_password_ready()) {
        throw new RuntimeException('Argon2id is not available in this PHP build.');
    }
    return password_hash($plain, PASSWORD_ARGON2ID, VENUSEP_PASSWORD_OPTIONS);
}

/* Call right after a CORRECT password: re-hashes a bcrypt hash (or an Argon2id
   hash made with older settings) to the current ones. Never blocks the login —
   if it cannot upgrade, the old hash simply keeps working. The UPDATE only
   replaces the exact hash that was checked, so a password changed meanwhile
   in another window is never overwritten. */
function venusep_password_upgrade(PDO $pdo, int $userId, string $plain, string $hash): void
{
    if (!venusep_password_ready() || !password_needs_rehash($hash, PASSWORD_ARGON2ID, VENUSEP_PASSWORD_OPTIONS)) {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :u AND password_hash = :old')
            ->execute([':h' => venusep_password_hash($plain), ':u' => $userId, ':old' => $hash]);
    } catch (PDOException $e) {
        error_log('VENUSeP password upgrade: ' . $e->getMessage());
    }
}
