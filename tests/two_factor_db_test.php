<?php
/* =====================================================================
   CLI test for includes/two-factor.php — exercises the account logic
   (lockout ladder, recovery codes, reset) against the real local `venusep`
   database, using one throwaway account that is removed afterwards, even
   on failure.
   Run:  php tests/two_factor_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/two-factor.php';

const TFA_TEST_EMAIL = 'tfa-selftest@example.invalid';

$failures = 0;

function ft_check(string $name, $got, $want): void
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

function ft_unlock(PDO $pdo, int $id): void
{
    $stmt = $pdo->prepare('UPDATE users SET totp_locked_until = NOW() - INTERVAL 1 SECOND WHERE id = :u');
    $stmt->execute([':u' => $id]);
}

function ft_column(PDO $pdo, int $id, string $col)
{
    $stmt = $pdo->prepare("SELECT {$col} FROM users WHERE id = :u");
    $stmt->execute([':u' => $id]);
    $val = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $val;
}

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database (venusep_db() returned null).\n");
    exit(1);
}

$del = $pdo->prepare('DELETE FROM users WHERE email = :e');
$del->execute([':e' => TFA_TEST_EMAIL]);

$id = null;

try {
    $ins = $pdo->prepare(
        'INSERT INTO users (email, username, password_hash, account_type, is_active)
         VALUES (:e, :u, :p, :a, 0)'
    );
    $ins->execute([
        ':e' => TFA_TEST_EMAIL,
        ':u' => 'tfa_selftest',
        ':p' => 'not-a-hash',
        ':a' => 'staff',
    ]);
    $id = (int) $pdo->lastInsertId();

    $recoveryPattern = '/^[A-HJ-NP-Z2-9]{5}-[A-HJ-NP-Z2-9]{5}$/';

    // 1. Fresh account: 2FA off.
    $status = tfa_status($pdo, $id);
    ft_check('1. status before enable: enabled', $status['enabled'], false);
    ft_check('1. status before enable: codes_left', $status['codes_left'], 0);

    // 2. Enable.
    $secret = totp_new_secret();
    $step = intdiv(time(), 30);
    $codes = tfa_enable($pdo, $id, $secret, $step - 2);

    ft_check('2. tfa_enable returns 10 codes', count($codes), 10);
    $allMatch = true;
    foreach ($codes as $c) {
        if (!preg_match($recoveryPattern, $c)) {
            $allMatch = false;
            break;
        }
    }
    ft_check('2. all codes match recovery pattern', $allMatch, true);

    $status = tfa_status($pdo, $id);
    ft_check('2. status after enable: enabled', $status['enabled'], true);
    ft_check('2. status after enable: codes_left', $status['codes_left'], 10);

    // 3. Verify with a fresh TOTP code.
    $code = totp_code($secret, intdiv(time(), 30));
    $result = tfa_verify($pdo, $id, $code);
    ft_check('3. totp verify ok', $result['ok'], true);
    ft_check('3. totp verify used', $result['used'] ?? null, 'totp');

    // 4. Same code again -> replay refused. Reuses the exact $code from check 3
    // (not a freshly computed one) so a step rollover between checks can't
    // hand this a NEW, valid code and make the test pass for the wrong reason.
    $result = tfa_verify($pdo, $id, $code);
    ft_check('4. replay ok', $result['ok'], false);
    ft_check('4. replay error', $result['error'] ?? null, 'wrong');
    ft_check('4. replay attemptsLeft', $result['attemptsLeft'] ?? null, 4);

    // 5. Recovery code.
    $result = tfa_verify($pdo, $id, $codes[0]);
    ft_check('5. recovery ok', $result['ok'], true);
    ft_check('5. recovery used', $result['used'] ?? null, 'recovery');
    $status = tfa_status($pdo, $id);
    ft_check('5. codes_left after burn', $status['codes_left'], 9);

    // 6. Same recovery code again -> wrong.
    $result = tfa_verify($pdo, $id, $codes[0]);
    ft_check('6. reused recovery ok', $result['ok'], false);
    ft_check('6. reused recovery error', $result['error'] ?? null, 'wrong');
    ft_check('6. reused recovery attemptsLeft', $result['attemptsLeft'] ?? null, 4);

    // 7. Blank input -> not counted as an attempt.
    $result = tfa_verify($pdo, $id, '   ');
    ft_check('7. blank ok', $result['ok'], false);
    ft_check('7. blank error', $result['error'] ?? null, 'empty');
    ft_check('7. blank does not count', (int) ft_column($pdo, $id, 'totp_failed_attempts'), 1);

    // 8. Four wrong codes -> the 4th locks (level 1, 10s).
    $seen = [];
    for ($i = 0; $i < 4; $i++) {
        $seen[] = tfa_verify($pdo, $id, 'ZZZZZ-ZZZZZ');
    }
    $fourth = $seen[3];
    ft_check('8. 4th wrong locks: ok', $fourth['ok'], false);
    ft_check('8. 4th wrong locks: error', $fourth['error'] ?? null, 'locked');
    ft_check('8. 4th wrong locks: seconds', $fourth['seconds'] ?? null, 10);
    ft_check('8. DB lock_level after 1st lock', (int) ft_column($pdo, $id, 'totp_lock_level'), 1);

    // 9. While locked, a valid recovery code is refused and nothing is burned.
    $result = tfa_verify($pdo, $id, $codes[1]);
    ft_check('9. locked refuses valid recovery: ok', $result['ok'], false);
    ft_check('9. locked refuses valid recovery: error', $result['error'] ?? null, 'locked');
    $status = tfa_status($pdo, $id);
    ft_check('9. codes_left unchanged while locked', $status['codes_left'], 9);

    // 10. Unlock; five wrong codes -> the 5th locks again, escalated to 30s.
    ft_unlock($pdo, $id);
    $seen = [];
    for ($i = 0; $i < 5; $i++) {
        $seen[] = tfa_verify($pdo, $id, 'ZZZZZ-ZZZZZ');
    }
    $fifth = $seen[4];
    ft_check('10. 5th wrong locks: ok', $fifth['ok'], false);
    ft_check('10. 5th wrong locks: error', $fifth['error'] ?? null, 'locked');
    ft_check('10. 5th wrong locks: seconds (escalated)', $fifth['seconds'] ?? null, 30);
    ft_check('10. DB lock_level after 2nd lock', (int) ft_column($pdo, $id, 'totp_lock_level'), 2);

    // 11. Unlock; lowercase-with-spaces recovery code succeeds and resets the ladder.
    ft_unlock($pdo, $id);
    $lowerSpaced = strtolower(str_replace('-', ' ', $codes[1]));
    $result = tfa_verify($pdo, $id, $lowerSpaced);
    ft_check('11. lowercase-spaced recovery ok', $result['ok'], true);
    ft_check('11. lowercase-spaced recovery used', $result['used'] ?? null, 'recovery');
    ft_check('11. lock_level reset', (int) ft_column($pdo, $id, 'totp_lock_level'), 0);
    ft_check('11. failed_attempts reset', (int) ft_column($pdo, $id, 'totp_failed_attempts'), 0);

    // 12. Regenerate codes: old codes stop working, new ones work.
    $new = tfa_regenerate_codes($pdo, $id);
    ft_check('12. regenerate returns 10 codes', count($new), 10);
    $result = tfa_verify($pdo, $id, $codes[2]);
    ft_check('12. old code after regenerate: ok', $result['ok'], false);
    ft_check('12. old code after regenerate: error', $result['error'] ?? null, 'wrong');
    $result = tfa_verify($pdo, $id, $new[0]);
    ft_check('12. new code works', $result['ok'], true);

    // 13. Reset turns 2FA off and forgets recovery codes.
    tfa_reset($pdo, $id);
    $status = tfa_status($pdo, $id);
    ft_check('13. status after reset: enabled', $status['enabled'], false);
    ft_check('13. status after reset: codes_left', $status['codes_left'], 0);
    $result = tfa_verify($pdo, $id, '123456');
    ft_check('13. verify after reset', $result['error'] ?? null, 'not_enabled');
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u');
    $countStmt->execute([':u' => $id]);
    ft_check('13. recovery codes gone from DB', (int) $countStmt->fetchColumn(), 0);
    $countStmt->closeCursor();

    // 14. Cascade: re-enable, then delete the account -> recovery rows cascade away.
    tfa_enable($pdo, $id, totp_new_secret(), intdiv(time(), 30) - 2);
    $deletedId = $id;
    $delUser = $pdo->prepare('DELETE FROM users WHERE id = :u');
    $delUser->execute([':u' => $deletedId]);
    $id = null;   // deleted; the finally block below must not try again
    $cascadeStmt = $pdo->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u');
    $cascadeStmt->execute([':u' => $deletedId]);
    ft_check('14. no orphaned recovery rows after delete', (int) $cascadeStmt->fetchColumn(), 0);
} finally {
    if ($id !== null) {
        $cleanup = $pdo->prepare('DELETE FROM users WHERE id = :u');
        $cleanup->execute([':u' => $id]);
    }
    // Belt-and-suspenders: remove by email too, in case $id was never set.
    $cleanupByEmail = $pdo->prepare('DELETE FROM users WHERE email = :e');
    $cleanupByEmail->execute([':e' => TFA_TEST_EMAIL]);
}

if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILED\n";
exit(1);
