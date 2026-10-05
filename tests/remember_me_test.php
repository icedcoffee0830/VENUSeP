<?php
/* =====================================================================
   CLI test for includes/remember-me.php (DB-DECISIONS #23) — the cookie
   check, rotation, theft detection and every way a remembered device is
   forgotten — against the real local `venusep` database, with throwaway
   accounts removed afterwards, even on failure. Needs migration 04.
   Run:  php tests/remember_me_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/remember-me.php';
require __DIR__ . '/../includes/password-reset.php';

const RMT_EMAIL = 'remember-selftest@example.invalid';
const RMT_OTHER = 'remember-selftest-other@example.invalid';
const RMT_STAFF = 'remember-selftest-staff@example.invalid';

$failures = 0;
function rmt_check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database.\n");
    exit(1);
}
$in = "'" . implode("','", [RMT_EMAIL, RMT_OTHER, RMT_STAFF]) . "'";
$cleanup = function () use ($pdo, $in) {
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email IN ({$in})");
    $pdo->exec("DELETE FROM users WHERE email IN ({$in})");   // remember_tokens + password_resets cascade
};
$cleanup();
$count = function (int $uid) use ($pdo) {
    return (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = ' . $uid)->fetchColumn();
};
$status = function (array $r) { return $r['status']; };

try {
    $ins = $pdo->prepare('INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, :u, :p, :t)');
    $prof = $pdo->prepare('INSERT INTO customers (user_id, full_name) VALUES (:u, :n)');
    $ins->execute([':e' => RMT_EMAIL, ':u' => 'remember_selftest', ':p' => 'x', ':t' => 'customer']);
    $uid = (int) $pdo->lastInsertId();
    $prof->execute([':u' => $uid, ':n' => 'Remember Selftest']);
    $ins->execute([':e' => RMT_OTHER, ':u' => 'remember_selftest_o', ':p' => 'x', ':t' => 'customer']);
    $oid = (int) $pdo->lastInsertId();
    $prof->execute([':u' => $oid, ':n' => 'Remember Other']);
    $ins->execute([':e' => RMT_STAFF, ':u' => 'remember_selftest_s', ':p' => 'x', ':t' => 'staff']);
    $sid = (int) $pdo->lastInsertId();

    /* ---- 1. issuing ---- */
    [$c1, $exp] = remember_create($pdo, $uid, 'TestBrowser/1.0');
    rmt_check('1. cookie shape selector:validator', (bool) preg_match('/^[0-9a-f]{24}:[A-Za-z0-9_-]{43}$/', $c1), true);
    $row = $pdo->query('SELECT * FROM remember_tokens WHERE user_id = ' . $uid)->fetch();
    [$sel, $val] = explode(':', $c1);
    rmt_check('1. only the validator\'s hash is stored', $row['validator_hash'], hash('sha256', $val));
    rmt_check('1. the validator itself is nowhere in the row', strpos(json_encode($row), $val), false);
    $days = (int) round(($exp - time()) / 86400);
    rmt_check('1. expires in 15 days', $days, 15);

    /* ---- 2. using it rotates the validator, keeps the selector and the expiry ---- */
    $r = remember_check($pdo, $c1);
    rmt_check('2. valid cookie accepted', $status($r), 'ok');
    rmt_check('2. for the right account', $r['user_id'] ?? null, $uid);
    rmt_check('2. a new cookie is handed back', ($r['cookie'] ?? '') !== $c1, true);
    rmt_check('2. same selector', explode(':', $r['cookie'] ?? ':')[0], $sel);
    rmt_check('2. expiry NOT extended', $r['expires'] ?? null, $exp);
    $c2 = $r['cookie'];

    /* ---- 3. a parallel request with the old cookie, moments later: let through, not theft ---- */
    rmt_check('3. old cookie within a minute = race', $status(remember_check($pdo, $c1)), 'race');
    rmt_check('3. ...and nothing was deleted', $count($uid), 1);
    rmt_check('3. the new cookie still works', $status($r3 = remember_check($pdo, $c2)), 'ok');
    $c3 = $r3['cookie'];

    /* ---- 4. an old cookie after the grace period = copied: forget EVERY device ---- */
    [$cOtherDevice] = remember_create($pdo, $uid, 'SecondDevice/1.0');
    [$cOtherUser] = remember_create($pdo, $oid, null);
    $pdo->exec('UPDATE remember_tokens SET rotated_at = NOW() - INTERVAL 2 MINUTE WHERE user_id = ' . $uid);
    rmt_check('4. replayed old cookie = theft', $status(remember_check($pdo, $c2)), 'theft');
    rmt_check('4. every device of that account forgotten', $count($uid), 0);
    rmt_check('4. ...including the current cookie', $status(remember_check($pdo, $c3)), 'none');
    rmt_check('4. another account is untouched', $status(remember_check($pdo, $cOtherUser)), 'ok');

    /* ---- 5. junk ---- */
    rmt_check('5. empty', $status(remember_check($pdo, '')), 'malformed');
    rmt_check('5. no separator', $status(remember_check($pdo, str_repeat('a', 68))), 'malformed');
    rmt_check('5. injection attempt', $status(remember_check($pdo, "' OR 1=1 -- :x")), 'malformed');
    rmt_check('5. unknown selector', $status(remember_check($pdo, str_repeat('0', 24) . ':' . str_repeat('A', 43))), 'none');
    [$cWrong] = remember_create($pdo, $uid, null);
    $wrong = explode(':', $cWrong)[0] . ':' . str_repeat('B', 43);
    rmt_check('5. right selector, made-up validator = theft', $status(remember_check($pdo, $wrong)), 'theft');

    /* ---- 6. refused: expired, suspended, not a customer, password changed since ---- */
    [$c] = remember_create($pdo, $uid, null);
    $pdo->exec('UPDATE remember_tokens SET expires_at = NOW() - INTERVAL 1 SECOND WHERE user_id = ' . $uid);
    rmt_check('6. expired', $status(remember_check($pdo, $c)), 'expired');
    rmt_check('6. ...row deleted', $count($uid), 0);

    [$c] = remember_create($pdo, $uid, null);
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $uid);
    rmt_check('6. suspended account', $status(remember_check($pdo, $c)), 'refused');
    $pdo->exec('UPDATE users SET is_active = 1 WHERE id = ' . $uid);

    [$c] = remember_create($pdo, $sid, null);
    rmt_check('6. staff account', $status(remember_check($pdo, $c)), 'refused');

    [$c] = remember_create($pdo, $uid, null);
    $pdo->exec('UPDATE remember_tokens SET created_at = NOW() - INTERVAL 1 HOUR WHERE user_id = ' . $uid);
    $pdo->exec('UPDATE users SET password_changed_at = NOW() - INTERVAL 1 MINUTE WHERE id = ' . $uid);
    rmt_check('6. password changed after it was made', $status(remember_check($pdo, $c)), 'refused');
    [$c] = remember_create($pdo, $uid, null);
    rmt_check('6. a cookie made AFTER the change works', $status(remember_check($pdo, $c)), 'ok');

    /* ---- 7. forgetting ---- */
    remember_create($pdo, $uid, null);
    remember_forget_all($pdo, $uid);
    rmt_check('7. forget all (password / two-step changed)', $count($uid), 0);

    [$c] = remember_create($pdo, $uid, null);
    [$keep] = remember_create($pdo, $uid, null);
    $_COOKIE[REMEMBER_COOKIE] = $c;
    remember_forget_current($pdo);
    rmt_check('7. Log Out forgets this device', $status(remember_check($pdo, $c)), 'none');
    rmt_check('7. ...but not the others', $status(remember_check($pdo, $keep)), 'ok');
    rmt_check('7. ...and the cookie is gone', isset($_COOKIE[REMEMBER_COOKIE]), false);

    /* a reset forgets every device, in the same transaction as the new password */
    remember_create($pdo, $uid, null);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :h, NOW() + INTERVAL 10 MINUTE)')
        ->execute([':u' => $uid, ':h' => hash('sha256', 'remember-selftest-reset')]);
    $rid = (int) $pdo->lastInsertId();
    pr_complete($pdo, $rid, $uid, 'resetpass123');
    rmt_check('7. a password reset forgets every device', $count($uid), 0);
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
