<?php
/* =====================================================================
   REMEMBER ME — customers only (DB-DECISIONS #23).

   Ticking "Remember me" (password or Google) keeps THAT device signed in
   for REMEMBER_DAYS. When the session runs out, customer_logged_in()
   finds the cookie and remember_restore() opens a new session from it.

   THE COOKIE is "selector:validator".
     selector   24 hex characters, the row's public name
     validator  32 random bytes; only its SHA-256 is stored, so a copy of
                the remember_tokens table cannot sign anyone in
   HttpOnly (no script can read it), SameSite=Lax, Secure on HTTPS.

   EVERY USE REPLACES THE VALIDATOR. If an OLD validator ever comes back
   for a selector, the cookie was copied and someone is replaying it:
   every remembered device of that account is forgotten at once. Two
   requests that raced with the same cookie (a second tab loading in the
   same instant) are let through for REMEMBER_RACE_SECONDS instead.

   15 DAYS FROM SIGN-IN, not from the last visit: using the site does not
   extend it, so a stolen-then-quiet cookie cannot live forever.

   FORGOTTEN WHEN
     Log Out                                this device (customer/logout.php)
     password changed, reset, or first set  every device (profile-save.php,
                                            password-reset.php)
     two-step verification on or off        every device (profile-save.php)
     account suspended, or not a customer   refused at restore, row deleted
     password changed after it was made     refused at restore, row deleted

   It deliberately SKIPS the two-step code: a token is only ever issued
   after the full sign-in, code included (decided 2026-10-05). Sensitive
   actions still re-ask for the password or code in the profile.
   ===================================================================== */

require_once __DIR__ . '/db.php';

const REMEMBER_COOKIE       = 'venusep_remember';
const REMEMBER_DAYS         = 15;
const REMEMBER_RACE_SECONDS = 60;

function remember_b64url(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function remember_hash(string $validator): string
{
    return hash('sha256', $validator);
}

/* A new remembered device for this account. Returns [cookie value, expiry timestamp]. */
function remember_create(PDO $pdo, int $userId, ?string $userAgent): array
{
    $selector  = bin2hex(random_bytes(12));
    $validator = remember_b64url(random_bytes(32));
    $pdo->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at, user_agent)
         VALUES (:u, :s, :h, NOW() + INTERVAL ' . REMEMBER_DAYS . ' DAY, :a)'
    )->execute([':u' => $userId, ':s' => $selector, ':h' => remember_hash($validator),
                ':a' => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null]);
    $expires = (int) $pdo->query('SELECT UNIX_TIMESTAMP(expires_at) FROM remember_tokens WHERE selector = ' . $pdo->quote($selector))->fetchColumn();
    return [$selector . ':' . $validator, $expires];
}

/* Checks a cookie value; does no cookie or session work itself, so the tests
   can drive it. Returns ['status' => ..., ...]:
     ok        valid: 'user_id', the replacement 'cookie' and its 'expires'
     race      the validator was replaced moments ago by a parallel request
               with the same cookie: 'user_id', no new cookie
     none      no such selector (forgotten, or never existed)
     malformed not a cookie this code made
     expired / refused (account suspended, not a customer, or password
               changed since) — the row is deleted
     theft     an old validator: EVERY row of that account is deleted */
function remember_check(PDO $pdo, string $cookie): array
{
    if (!preg_match('/^([0-9a-f]{24}):([A-Za-z0-9_-]{43})$/', $cookie, $m)) {
        return ['status' => 'malformed'];
    }
    [, $selector, $validator] = $m;
    $stmt = $pdo->prepare(
        "SELECT r.id, r.user_id, r.validator_hash, r.prev_validator_hash,
                UNIX_TIMESTAMP(r.expires_at) AS expires,
                r.expires_at <= NOW() AS expired,
                r.rotated_at > NOW() - INTERVAL " . REMEMBER_RACE_SECONDS . " SECOND AS recent,
                (u.is_active = 1 AND u.account_type = 'customer') AS allowed,
                (u.password_changed_at IS NOT NULL AND u.password_changed_at > r.created_at) AS stale
           FROM remember_tokens r JOIN users u ON u.id = r.user_id
          WHERE r.selector = :s LIMIT 1"
    );
    $stmt->execute([':s' => $selector]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['status' => 'none'];
    }
    $id = (int) $row['id'];
    $userId = (int) $row['user_id'];
    $delete = function () use ($pdo, $id) {
        $pdo->prepare('DELETE FROM remember_tokens WHERE id = :i')->execute([':i' => $id]);
    };
    if ((int) $row['expired'] === 1) {
        $delete();
        return ['status' => 'expired'];
    }
    if ((int) $row['allowed'] !== 1 || (int) $row['stale'] === 1) {
        $delete();
        return ['status' => 'refused'];
    }

    $given = remember_hash($validator);
    if (hash_equals((string) $row['validator_hash'], $given)) {
        $next = remember_b64url(random_bytes(32));
        $upd = $pdo->prepare(
            'UPDATE remember_tokens
                SET prev_validator_hash = validator_hash, validator_hash = :n,
                    rotated_at = NOW(), last_used_at = NOW()
              WHERE id = :i AND validator_hash = :old'
        );
        $upd->execute([':n' => remember_hash($next), ':i' => $id, ':old' => $given]);
        if ($upd->rowCount() !== 1) {
            return ['status' => 'race', 'user_id' => $userId];   // a parallel request rotated it first
        }
        return ['status' => 'ok', 'user_id' => $userId, 'cookie' => $selector . ':' . $next, 'expires' => (int) $row['expires']];
    }
    if ($row['prev_validator_hash'] !== null && (int) $row['recent'] === 1
        && hash_equals((string) $row['prev_validator_hash'], $given)) {
        return ['status' => 'race', 'user_id' => $userId];
    }

    /* A validator this row no longer holds: the cookie was copied. */
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :u')->execute([':u' => $userId]);
    error_log('VENUSeP remember me: a replaced cookie was used again for user ' . $userId . '; every remembered device of that account was forgotten.');
    return ['status' => 'theft'];
}

function remember_cookie_set(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE[REMEMBER_COOKIE] = $value;
}

function remember_cookie_clear(): void
{
    if (!headers_sent() && isset($_COOKIE[REMEMBER_COOKIE])) {
        setcookie(REMEMBER_COOKIE, '', ['expires' => time() - 42000, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    }
    unset($_COOKIE[REMEMBER_COOKIE]);
}

/* "Remember me" was ticked: remember this device. Called after the session is open. */
function remember_issue(PDO $pdo, int $userId): void
{
    [$value, $expires] = remember_create($pdo, $userId, $_SERVER['HTTP_USER_AGENT'] ?? null);
    remember_cookie_set($value, $expires);
}

/* No session, but a remember cookie: try to open a session from it. True when
   the customer is signed in again. Tried once per request. */
function remember_restore(PDO $pdo): bool
{
    static $tried = false;
    if ($tried) {
        return false;
    }
    $tried = true;
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (!is_string($cookie) || $cookie === '') {
        return false;
    }
    require_once __DIR__ . '/accounts.php';
    try {
        $r = remember_check($pdo, $cookie);
        if ($r['status'] === 'ok' || $r['status'] === 'race') {
            if (customer_session_open($pdo, (int) $r['user_id'])) {
                $_SESSION['remembered'] = true;   // signed in by the cookie, not by a password this time
                if ($r['status'] === 'ok') {
                    remember_cookie_set($r['cookie'], $r['expires']);
                }
                return true;
            }
        }
    } catch (PDOException $e) {
        error_log('VENUSeP remember me: ' . $e->getMessage());
        return false;   // the database is the problem: keep the cookie for next time
    }
    remember_cookie_clear();
    return false;
}

/* Log Out: forget THIS device. */
function remember_forget_current(PDO $pdo): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? null;
    if (is_string($cookie) && preg_match('/^([0-9a-f]{24}):/', $cookie, $m)) {
        try {
            $pdo->prepare('DELETE FROM remember_tokens WHERE selector = :s')->execute([':s' => $m[1]]);
        } catch (PDOException $e) {
            error_log('VENUSeP remember me (log out): ' . $e->getMessage());
        }
    }
    remember_cookie_clear();
}

/* A credential changed: forget EVERY device of this account. */
function remember_forget_all(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = :u')->execute([':u' => $userId]);
}
