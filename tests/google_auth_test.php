<?php
/* =====================================================================
   CLI test for includes/google-auth.php (DB-DECISIONS #23).
     - google_check_claims(): every way an ID token must be refused
     - google_begin() / google_take_flow(): state, nonce, PKCE, one-time use
     - google_resolve() + google_create_customer(): against the real local
       `venusep` database, with throwaway accounts removed afterwards, even
       on failure
   Needs migration 04. Never talks to Google.
   Run:  php tests/google_auth_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/google-auth.php';

const GA_CLIENT   = 'test-client.apps.googleusercontent.com';
const GA_NONCE    = 'nonce-123';
const GA_NEW      = 'google-selftest-new@example.invalid';
const GA_PASSWORD = 'google-selftest-password@example.invalid';
const GA_STAFF    = 'google-selftest-staff@example.invalid';
const GA_SUB      = 'selftest-sub-000000001';

$failures = 0;

function ga_check(string $name, $got, $want): void
{
    global $failures;
    if ($got === $want) {
        echo "ok   {$name}\n";
    } else {
        $failures++;
        echo "FAIL {$name}: got " . var_export($got, true) . ', want ' . var_export($want, true) . "\n";
    }
}

/* ---- 1. claims ---- */
$now  = 1_800_000_000;
$good = ['iss' => 'https://accounts.google.com', 'aud' => GA_CLIENT, 'exp' => $now + 3000, 'iat' => $now - 10,
         'nonce' => GA_NONCE, 'sub' => '1234567890', 'email' => 'juan@gmail.com', 'email_verified' => true];

ga_check('1. good token accepted', google_check_claims($good, GA_CLIENT, GA_NONCE, $now), null);
ga_check('1. issuer without scheme accepted', google_check_claims(['iss' => 'accounts.google.com'] + $good, GA_CLIENT, GA_NONCE, $now), null);
ga_check('1. wrong issuer', google_check_claims(['iss' => 'https://evil.example'] + $good, GA_CLIENT, GA_NONCE, $now), 'wrong issuer');
ga_check('1. other client', google_check_claims(['aud' => 'other.apps.googleusercontent.com'] + $good, GA_CLIENT, GA_NONCE, $now), 'token was not issued to this client');
ga_check('1. empty client id never matches', google_check_claims(['aud' => ''] + $good, '', GA_NONCE, $now), 'token was not issued to this client');
ga_check('1. aud list needs azp', google_check_claims(['aud' => [GA_CLIENT, 'x']] + $good, GA_CLIENT, GA_NONCE, $now), 'token was not issued to this client');
ga_check('1. aud list with azp ok', google_check_claims(['aud' => [GA_CLIENT, 'x'], 'azp' => GA_CLIENT] + $good, GA_CLIENT, GA_NONCE, $now), null);
ga_check('1. expired', google_check_claims(['exp' => $now - 600] + $good, GA_CLIENT, GA_NONCE, $now), 'token expired');
ga_check('1. expiry within skew ok', google_check_claims(['exp' => $now - 60] + $good, GA_CLIENT, GA_NONCE, $now), null);
ga_check('1. issued in the future', google_check_claims(['iat' => $now + 600] + $good, GA_CLIENT, GA_NONCE, $now), 'token issued in the future');
ga_check('1. wrong nonce', google_check_claims(['nonce' => 'other'] + $good, GA_CLIENT, GA_NONCE, $now), 'nonce mismatch');
ga_check('1. missing nonce', google_check_claims(array_diff_key($good, ['nonce' => 1]), GA_CLIENT, GA_NONCE, $now), 'nonce mismatch');
ga_check('1. empty expected nonce', google_check_claims(['nonce' => ''] + $good, GA_CLIENT, '', $now), 'nonce mismatch');
ga_check('1. no subject', google_check_claims(['sub' => ''] + $good, GA_CLIENT, GA_NONCE, $now), 'no usable subject');
ga_check('1. bad email', google_check_claims(['email' => 'not-an-email'] + $good, GA_CLIENT, GA_NONCE, $now), 'no usable email');
ga_check('1. unverified email', google_check_claims(['email_verified' => false] + $good, GA_CLIENT, GA_NONCE, $now), 'email not verified by Google');
ga_check('1. "true" string accepted', google_check_claims(['email_verified' => 'true'] + $good, GA_CLIENT, GA_NONCE, $now), null);

/* ---- 2. decoding ---- */
$jwt = 'eyJhbGciOiJub25lIn0.' . google_b64url(json_encode(['sub' => 'abc', 'n' => 'ü'])) . '.sig';
ga_check('2. decodes payload', google_decode_id_token($jwt), ['sub' => 'abc', 'n' => 'ü']);
ga_check('2. two parts refused', google_decode_id_token('a.b'), null);
ga_check('2. junk payload refused', google_decode_id_token('a.@@@.c'), null);
ga_check('2. b64url round trip', google_b64url_decode(google_b64url("\xff\xfe\x00?")), "\xff\xfe\x00?");

/* ---- 3. begin / take flow ---- */
$_SESSION = [];
$url = google_begin();
$flow = $_SESSION['google_flow'];
parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
ga_check('3. goes to Google', strpos($url, GOOGLE_AUTH_ENDPOINT . '?') === 0, true);
ga_check('3. state sent', $q['state'] ?? null, $flow['state']);
ga_check('3. nonce sent', $q['nonce'] ?? null, $flow['nonce']);
ga_check('3. verifier never sent', strpos($url, $flow['verifier']) === false, true);
ga_check('3. S256 challenge', $q['code_challenge'] ?? null, google_b64url(hash('sha256', $flow['verifier'], true)));
ga_check('3. scope', $q['scope'] ?? null, 'openid email profile');
ga_check('3. wrong state refused', google_take_flow('nope'), null);
ga_check('3. a refused attempt is spent too', isset($_SESSION['google_flow']), false);
google_begin();
$state = $_SESSION['google_flow']['state'];
ga_check('3. right state accepted', is_array(google_take_flow($state)), true);
ga_check('3. second use refused', google_take_flow($state), null);
google_begin();
$_SESSION['google_flow']['at'] = time() - GOOGLE_FLOW_TTL - 1;
ga_check('3. expired flow refused', google_take_flow($_SESSION['google_flow']['state']), null);
google_begin();
ga_check('3. plain begin is a sign-in', [$_SESSION['google_flow']['purpose'], $_SESSION['google_flow']['user_id']], ['signin', 0]);
google_begin('link', 42);
ga_check('3. link remembers who started it', [$_SESSION['google_flow']['purpose'], $_SESSION['google_flow']['user_id']], ['link', 42]);
google_begin('anything-else', 42);
ga_check('3. unknown purpose falls back to sign-in', $_SESSION['google_flow']['purpose'], 'signin');

/* ---- 3b. short-lived proofs ---- */
google_proof_set('google_reauth', 7);
ga_check('3b. proof valid for its owner', google_proof_valid('google_reauth', 7), true);
ga_check('3b. proof not valid for another user', google_proof_valid('google_reauth', 8), false);
ga_check('3b. proof is per kind', google_proof_valid('google_link', 7), false);
ga_check('3b. user 0 never valid', (google_proof_set('google_link', 0) || true) && google_proof_valid('google_link', 0), false);
$_SESSION['google_reauth']['at'] = time() - GOOGLE_PROOF_TTL - 1;
ga_check('3b. proof expires', google_proof_valid('google_reauth', 7), false);
google_proof_set('google_reauth', 7);
google_proof_clear('google_reauth');
ga_check('3b. cleared proof gone', google_proof_valid('google_reauth', 7), false);

/* ---- 4. resolve + create, against the database ---- */
$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database (venusep_db() returned null).\n");
    exit(1);
}
$hasColumn = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_sub'")->fetchColumn();
if ((int) $hasColumn === 0) {
    fwrite(STDERR, "users.google_sub is missing: run venusep_migration_04.sql first.\n");
    exit(1);
}

/* customers first: their FK is ON DELETE SET NULL, so deleting only the user
   would leave a profile behind that looks exactly like a walk-in. */
$cleanup = function () use ($pdo) {
    $who = "u.email IN (:a, :b, :c) OR u.google_sub = :s OR u.google_sub LIKE 'selftest-sub-%'";
    $args = [':a' => GA_NEW, ':b' => GA_PASSWORD, ':c' => GA_STAFF, ':s' => GA_SUB];
    $pdo->prepare("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE {$who}")->execute($args);
    $pdo->prepare("DELETE u FROM users u WHERE {$who}")->execute($args);
};
$cleanup();

try {
    $ins = $pdo->prepare('INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, :u, :p, :t)');
    $ins->execute([':e' => GA_PASSWORD, ':u' => 'google_selftest_pw', ':p' => 'not-a-hash', ':t' => 'customer']);
    $pdo->prepare('INSERT INTO customers (user_id, full_name) VALUES (:u, :n)')
        ->execute([':u' => (int) $pdo->lastInsertId(), ':n' => 'Google Selftest Password']);
    $ins->execute([':e' => GA_STAFF, ':u' => 'google_selftest_staff', ':p' => 'not-a-hash', ':t' => 'staff']);

    ga_check('4. unknown id + unknown email = new', google_resolve($pdo, GA_SUB, GA_NEW), ['action' => 'new']);
    ga_check('4. email registered with a password = taken (never linked)', google_resolve($pdo, GA_SUB, GA_PASSWORD), ['action' => 'taken']);
    ga_check('4. staff email = refused', google_resolve($pdo, GA_SUB, GA_STAFF), ['action' => 'refused']);

    $uid = google_create_customer($pdo, GA_SUB, GA_NEW, 'Google Selftest', null);
    $row = $pdo->query('SELECT u.password_hash, u.google_sub, u.account_type, u.email_verified_at IS NOT NULL AS verified,
                               c.full_name, c.phone
                          FROM users u JOIN customers c ON c.user_id = u.id WHERE u.id = ' . (int) $uid)->fetch();
    ga_check('4. created: no password', $row['password_hash'], null);
    ga_check('4. created: google id linked', $row['google_sub'], GA_SUB);
    ga_check('4. created: a customer', $row['account_type'], 'customer');
    ga_check('4. created: email marked verified', (int) $row['verified'], 1);
    ga_check('4. created: customer profile', [$row['full_name'], $row['phone']], ['Google Selftest', null]);

    ga_check('4. same id again = signin', google_resolve($pdo, GA_SUB, GA_NEW),
        ['action' => 'signin', 'user_id' => $uid, 'email' => GA_NEW]);
    ga_check('4. same id, email changed in Google = still that account', google_resolve($pdo, GA_SUB, 'changed@example.invalid')['user_id'] ?? null, $uid);
    ga_check('4. another Google id with this email = taken', google_resolve($pdo, 'someone-else', GA_NEW), ['action' => 'taken']);

    $dupe = null;
    try {
        google_create_customer($pdo, 'another-sub', GA_NEW, 'Dupe', null);
    } catch (PDOException $e) {
        $dupe = $e->getCode();
    }
    ga_check('4. duplicate email refused by the database', $dupe, '23000');
    ga_check('4. ...and left no half-made account', (int) $pdo->query("SELECT COUNT(*) FROM users WHERE google_sub = 'another-sub'")->fetchColumn(), 0);

    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = :u')->execute([':u' => $uid]);
    ga_check('4. suspended = refused', google_resolve($pdo, GA_SUB, GA_NEW), ['action' => 'refused']);

    $pdo->prepare("UPDATE users SET is_active = 1, account_type = 'staff' WHERE id = :u")->execute([':u' => $uid]);
    ga_check('4. a linked id on a staff account = refused', google_resolve($pdo, GA_SUB, GA_NEW), ['action' => 'refused']);
    $pdo->prepare("UPDATE users SET account_type = 'customer' WHERE id = :u")->execute([':u' => $uid]);

    /* ---- 5. connecting Google to a signed-in password account ---- */
    $pwId = (int) $pdo->query("SELECT id FROM users WHERE email = '" . GA_PASSWORD . "'")->fetchColumn();
    $staffId = (int) $pdo->query("SELECT id FROM users WHERE email = '" . GA_STAFF . "'")->fetchColumn();
    $subOf = function (int $id) use ($pdo) {
        return $pdo->query('SELECT google_sub FROM users WHERE id = ' . $id)->fetchColumn();
    };
    ga_check('5. a Google id owned by another account is refused', google_link($pdo, $pwId, GA_SUB),
        'That Google account is already connected to another VENUSeP account.');
    ga_check('5. ...and nothing changed', $subOf($pwId), null);
    ga_check('5. a free Google id links', google_link($pdo, $pwId, 'selftest-sub-link'), null);
    ga_check('5. ...and is stored', $subOf($pwId), 'selftest-sub-link');
    ga_check('5. linking the same id again is fine', google_link($pdo, $pwId, 'selftest-sub-link'), null);
    ga_check('5. a second, different Google id is refused', google_link($pdo, $pwId, 'selftest-sub-other'),
        'This account is already connected to a different Google account. Disconnect it first.');
    ga_check('5. ...and the first link stays', $subOf($pwId), 'selftest-sub-link');
    ga_check('5. a staff account can never be linked', google_link($pdo, $staffId, 'selftest-sub-staff') !== null, true);
    ga_check('5. ...and nothing changed', $subOf($staffId), null);
    ga_check('5. the linked id now signs that account in', google_resolve($pdo, 'selftest-sub-link', 'whatever@example.invalid')['user_id'] ?? null, $pwId);
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
