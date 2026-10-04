<?php
/* =====================================================================
   CLI test for includes/passwords.php — Argon2id hashing, bcrypt
   compatibility, rehash detection, and the login-time upgrade against the
   real local `venusep` database (one throwaway, inactive account, removed
   afterwards even on failure).
   Run:  php tests/password_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/passwords.php';

const PW_TEST_EMAIL = 'pw-selftest@example.invalid';
const PW_PLAIN      = 'Correct-Horse-9';
const PW_PREFIX     = '$argon2id$v=19$m=19456,t=2,p=1$';

$failures = 0;

function pw_check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        $gotStr = var_export($got, true);
        $wantStr = var_export($want, true);
        echo "FAIL {$name}: got {$gotStr}, want {$wantStr}\n";
    }
}

/* Argon2id is REQUIRED (DB-DECISIONS #21): without it there is nothing to test. */
if (!venusep_password_ready()) {
    echo "FAIL argon2id available: this PHP cannot make Argon2id hashes\n";
    exit(1);
}

/* --- hashing --- */
pw_check('venusep_password_ready()', venusep_password_ready(), true);
$h = venusep_password_hash(PW_PLAIN);
pw_check('hash uses Argon2id with the OWASP settings', strpos($h, PW_PREFIX), 0);
pw_check('right password verifies', password_verify(PW_PLAIN, $h), true);
pw_check('wrong password fails', password_verify('wrong', $h), false);
pw_check('same password, different hash (random salt)', venusep_password_hash(PW_PLAIN) !== $h, true);

/* --- old hashes and settings changes --- */
$bcrypt = password_hash(PW_PLAIN, PASSWORD_BCRYPT);
pw_check('an old bcrypt hash still verifies', password_verify(PW_PLAIN, $bcrypt), true);
pw_check('bcrypt needs rehash', password_needs_rehash($bcrypt, PASSWORD_ARGON2ID, VENUSEP_PASSWORD_OPTIONS), true);
pw_check('current Argon2id does not need rehash', password_needs_rehash($h, PASSWORD_ARGON2ID, VENUSEP_PASSWORD_OPTIONS), false);
pw_check('Argon2id with other settings needs rehash',
    password_needs_rehash(password_hash(PW_PLAIN, PASSWORD_ARGON2ID), PASSWORD_ARGON2ID, VENUSEP_PASSWORD_OPTIONS), true);

/* --- the login-time upgrade, against the database --- */
$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database (venusep_db() returned null).\n");
    exit(1);
}
function pw_stored(PDO $pdo, int $id): string
{
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :u');
    $stmt->execute([':u' => $id]);
    return (string) $stmt->fetchColumn();
}

$pdo->prepare('DELETE FROM users WHERE email = :e')->execute([':e' => PW_TEST_EMAIL]);   // leftovers from an aborted run
$id = 0;
try {
    $pdo->prepare("INSERT INTO users (email, username, password_hash, account_type, is_active) VALUES (:e, :u, :p, 'customer', 0)")
        ->execute([':e' => PW_TEST_EMAIL, ':u' => 'pw-selftest', ':p' => $bcrypt]);
    $id = (int) $pdo->lastInsertId();

    venusep_password_upgrade($pdo, $id, PW_PLAIN, $bcrypt);
    $upgraded = pw_stored($pdo, $id);
    pw_check('bcrypt upgraded to Argon2id at login', strpos($upgraded, PW_PREFIX), 0);
    pw_check('upgraded hash still verifies', password_verify(PW_PLAIN, $upgraded), true);

    venusep_password_upgrade($pdo, $id, PW_PLAIN, $upgraded);
    pw_check('a current hash is left alone', pw_stored($pdo, $id), $upgraded);

    venusep_password_upgrade($pdo, $id, PW_PLAIN, $bcrypt);
    pw_check('a stale hash never overwrites the stored one', pw_stored($pdo, $id), $upgraded);
} finally {
    $pdo->prepare('DELETE FROM users WHERE email = :e')->execute([':e' => PW_TEST_EMAIL]);
}
$stmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e');
$stmt->execute([':e' => PW_TEST_EMAIL]);
pw_check('throwaway account removed', (int) $stmt->fetchColumn(), 0);

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
