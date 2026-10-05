<?php
/* =====================================================================
   CLI test for includes/password-reset.php (DB-DECISIONS #23) against
   the real local `venusep` database, with throwaway accounts removed
   afterwards, even on failure. Emails use the LOG transport (a .eml file
   outside the web root), so nothing is ever sent.
   Needs migration 04 (all of it).
   Run:  php tests/password_reset_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/password-reset.php';

const PRT_CUSTOMER = 'reset-selftest@example.invalid';
const PRT_GOOGLE   = 'reset-selftest-google@example.invalid';
const PRT_STAFF    = 'reset-selftest-staff@example.invalid';
const PRT_UNKNOWN  = 'reset-selftest-nobody@example.invalid';

$failures = 0;
function prt_check(string $name, $got, $want): void
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
$logMail = mail_config_from(__DIR__ . '/does-not-exist.php');   // the defaults: transport 'log'
prt_check('0. tests send nothing (log transport)', $logMail['transport'], 'log');

$emails = [PRT_CUSTOMER, PRT_GOOGLE, PRT_STAFF, PRT_UNKNOWN];
$in = "'" . implode("','", $emails) . "'";
$cleanup = function () use ($pdo, $in) {
    foreach ($pdo->query("SELECT id FROM email_outbox WHERE to_email IN ({$in})")->fetchAll(PDO::FETCH_COLUMN) as $oid) {
        $f = doc_ensure_dir('mail-log') . '/' . (int) $oid . '.eml';
        if (is_file($f)) { @unlink($f); }
    }
    $pdo->exec("DELETE FROM email_outbox WHERE to_email IN ({$in})");
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email IN ({$in})");
    $pdo->exec("DELETE FROM users WHERE email IN ({$in})");   // password_resets go with them (ON DELETE CASCADE)
};
$cleanup();

/* the newest reset email for an address: [outbox row, token read from the .eml] */
$lastMail = function (string $to) use ($pdo) {
    $row = $pdo->query("SELECT * FROM email_outbox WHERE to_email = " . $pdo->quote($to) . " ORDER BY id DESC LIMIT 1")->fetch();
    if (!$row) {
        return [null, null];
    }
    $eml = (string) @file_get_contents(doc_ensure_dir('mail-log') . '/' . (int) $row['id'] . '.eml');
    $eml = quoted_printable_decode(str_replace("=\r\n", '', $eml));
    preg_match('/reset-password\.php\?token=([A-Za-z0-9_-]{43})/', $eml, $m);
    return [$row, $m[1] ?? null];
};
$resets = function (int $uid) use ($pdo) {
    return $pdo->query('SELECT * FROM password_resets WHERE user_id = ' . $uid . ' ORDER BY id')->fetchAll();
};

try {
    $ins = $pdo->prepare('INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, :u, :p, :t)');
    $prof = $pdo->prepare('INSERT INTO customers (user_id, full_name) VALUES (:u, :n)');
    $ins->execute([':e' => PRT_CUSTOMER, ':u' => 'reset_selftest', ':p' => password_hash('oldpass123', PASSWORD_BCRYPT), ':t' => 'customer']);
    $uid = (int) $pdo->lastInsertId();
    $prof->execute([':u' => $uid, ':n' => 'Reset Selftest']);
    $ins->execute([':e' => PRT_GOOGLE, ':u' => 'reset_selftest_g', ':p' => null, ':t' => 'customer']);
    $gid = (int) $pdo->lastInsertId();
    $prof->execute([':u' => $gid, ':n' => 'Reset Google']);
    $ins->execute([':e' => PRT_STAFF, ':u' => 'reset_selftest_s', ':p' => 'x', ':t' => 'staff']);
    $sid = (int) $pdo->lastInsertId();

    /* ---- 1. nobody / staff: nothing happens ---- */
    pr_request($pdo, PRT_UNKNOWN, '127.0.0.1', $logMail);
    prt_check('1. unknown email: no email queued', $lastMail(PRT_UNKNOWN)[0], null);
    pr_request($pdo, PRT_STAFF, '127.0.0.1', $logMail);
    prt_check('1. staff email: no link made', count($resets($sid)), 0);
    prt_check('1. staff email: no email queued', $lastMail(PRT_STAFF)[0], null);

    /* ---- 2. a customer: one link, emailed, then scrubbed from the outbox ---- */
    pr_request($pdo, PRT_CUSTOMER, '127.0.0.1', $logMail);
    $rows = $resets($uid);
    [$mail, $token] = $lastMail(PRT_CUSTOMER);
    prt_check('2. one link made', count($rows), 1);
    prt_check('2. email sent', $mail['status'] ?? null, 'sent');
    prt_check('2. email kind', $mail['kind'] ?? null, 'password_reset');
    prt_check('2. the email carried a 43-character token', is_string($token), true);
    prt_check('2. only the token\'s hash is stored', $rows[0]['token_hash'], hash('sha256', (string) $token));
    prt_check('2. the token itself is nowhere in the table', strpos(json_encode($rows), (string) $token), false);
    prt_check('2. stored HTML body scrubbed', strpos($mail['body_html'], (string) $token) === false && strpos($mail['body_html'], '[link removed after sending]') !== false, true);
    prt_check('2. stored text body scrubbed', strpos($mail['body_text'], (string) $token) === false && strpos($mail['body_text'], '[link removed after sending]') !== false, true);
    $mins = (int) $pdo->query('SELECT TIMESTAMPDIFF(MINUTE, NOW(), expires_at) FROM password_resets WHERE id = ' . (int) $rows[0]['id'])->fetchColumn();
    prt_check('2. expires in ~30 minutes', $mins >= 29 && $mins <= 30, true);
    prt_check('2. the link opens its reset', pr_find($pdo, (string) $token)['user_id'] ?? null, $uid);

    /* ---- 3. throttle, then a newer link cancels the old one ---- */
    pr_request($pdo, PRT_CUSTOMER, '127.0.0.1', $logMail);
    prt_check('3. a second request within 5 minutes makes no new link', count($resets($uid)), 1);
    $pdo->exec('UPDATE password_resets SET created_at = NOW() - INTERVAL 6 MINUTE WHERE user_id = ' . $uid);
    pr_request($pdo, PRT_CUSTOMER, '127.0.0.1', $logMail);
    [, $token2] = $lastMail(PRT_CUSTOMER);
    prt_check('3. after 5 minutes a new link is made', count($resets($uid)), 2);
    prt_check('3. the old link is cancelled', pr_find($pdo, (string) $token), null);
    prt_check('3. the new link works', pr_find($pdo, (string) $token2)['user_id'] ?? null, $uid);

    /* ---- 4. junk tokens ---- */
    prt_check('4. empty token', pr_find($pdo, ''), null);
    prt_check('4. wrong token', pr_find($pdo, str_repeat('A', 43)), null);
    prt_check('4. bad characters', pr_find($pdo, "x' OR '1'='1"), null);
    prt_check('4. too long', pr_find($pdo, str_repeat('A', 500)), null);

    /* ---- 5. using it ---- */
    $r = pr_find($pdo, (string) $token2);
    prt_check('5. find by id (the form\'s second request)', pr_find_id($pdo, $r['id'], $uid)['id'] ?? null, $r['id']);
    prt_check('5. find by id refuses another user', pr_find_id($pdo, $r['id'], $gid), null);
    prt_check('5. complete', pr_complete($pdo, $r['id'], $uid, 'brandnew123'), true);
    $u = $pdo->query('SELECT password_hash, password_changed_at FROM users WHERE id = ' . $uid)->fetch();
    prt_check('5. new password works', password_verify('brandnew123', $u['password_hash']), true);
    prt_check('5. old password does not', password_verify('oldpass123', $u['password_hash']), false);
    prt_check('5. stored as Argon2id', strpos($u['password_hash'], '$argon2id$') === 0, true);
    prt_check('5. password_changed_at set (ends older sessions)', $u['password_changed_at'] !== null, true);
    prt_check('5. the link is spent', pr_find($pdo, (string) $token2), null);
    prt_check('5. using it twice fails', pr_complete($pdo, $r['id'], $uid, 'another123'), false);
    prt_check('5. ...and changes nothing', password_verify('brandnew123', $pdo->query('SELECT password_hash FROM users WHERE id = ' . $uid)->fetchColumn()), true);

    /* ---- 6. expired, and suspended ---- */
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :h, NOW() - INTERVAL 1 MINUTE)')
        ->execute([':u' => $uid, ':h' => hash('sha256', 'expired-token-000000000000000000000000000000')]);
    prt_check('6. expired link refused', pr_find($pdo, 'expired-token-000000000000000000000000000000'), null);
    $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :h, NOW() + INTERVAL 10 MINUTE)')
        ->execute([':u' => $uid, ':h' => hash('sha256', 'suspend-token-000000000000000000000000000000')]);
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = ' . $uid);
    prt_check('6. suspended account: link refused', pr_find($pdo, 'suspend-token-000000000000000000000000000000'), null);
    $pdo->exec('UPDATE password_resets SET created_at = NOW() - INTERVAL 6 MINUTE WHERE user_id = ' . $uid);
    $before = count($resets($uid));
    pr_request($pdo, PRT_CUSTOMER, '127.0.0.1', $logMail);
    prt_check('6. suspended account: no new link', count($resets($uid)), $before);
    $pdo->exec('UPDATE users SET is_active = 1 WHERE id = ' . $uid);

    /* ---- 7. an account made through Google (no password) can get one this way ---- */
    pr_request($pdo, PRT_GOOGLE, '127.0.0.1', $logMail);
    [, $gtoken] = $lastMail(PRT_GOOGLE);
    $g = pr_find($pdo, (string) $gtoken);
    prt_check('7. Google-only account gets a link', $g['user_id'] ?? null, $gid);
    prt_check('7. ...and it sets a first password', pr_complete($pdo, (int) $g['id'], $gid, 'firstpass123')
        && password_verify('firstpass123', (string) $pdo->query('SELECT password_hash FROM users WHERE id = ' . $gid)->fetchColumn()), true);
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
