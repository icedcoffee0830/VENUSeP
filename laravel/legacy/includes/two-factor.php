<?php
/* =====================================================================
   Two-step verification — account logic (DB-DECISIONS #20).

   Settled decisions this file implements exactly:
   1. Admin: required. Customers: optional. Staff: not part of it yet — there
      is no staff side (it will be a restricted copy of the admin side); when
      it exists, tfa_required_for() is the one line that brings staff in.
   2. Lost phone -> recovery codes only. 10 single-use codes, shown once at
      set-up, regenerable while signed in. No in-app reset, not even by an
      admin — the only fallback is the stored procedure sp_reset_2fa(user_id),
      run by someone with direct database access.
   3. The demo seed turns 2FA off for its whole cast (sp_reset_2fa), because
      those logins are shared by everyone who demos the system.
   4. Lockout on the account, not the session: 5 wrong codes = a lock of
      10s -> 30s -> 1m -> 5m -> 15m -> 1h -> 4h (max). The refund-switch ladder
      plus two longer steps, in its own totp_* columns so a sign-in lockout
      never blocks an admin action. Recovery-code attempts count toward it.
   5. A code works once: the last accepted 30-second step is stored; a code
      at or before it is rejected. One step of clock drift either side is
      accepted.
   6. Secret stored as plain text (base32). Not encrypted: a lost encryption
      key would lock out every account, and decision 2 means there is no way
      back from that.
   7. The walk-in counter identity check (admin/customer-verify.php) stays
      password-only — not touched.

   The maths (TOTP itself) is includes/totp.php. This file is the account
   side: lockout, recovery codes, and the pending-2FA session state that
   plan 002 wires into the login/profile pages.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/totp.php';
/* includes/auth.php loads THIS file (for tfa_required_for()); the dependency runs one
   way only. tfa_login_step() uses auth.php's csrf_valid(), so its callers — the two
   sign-in pages — load auth.php, as every page already does. */

const TFA_ATTEMPTS_PER_LOCK   = 5;
/* The refund switch's ladder, then two longer steps (decided 2026-09-30, #20): a real
   person never gets past the first five (any right code resets it), while someone who
   has the password drops from ~480 guesses a day to ~30. Locks end on their own. */
const TFA_LOCK_SECONDS        = [10, 30, 60, 300, 900, 3600, 14400];
const TFA_PENDING_TTL         = 600;                      // seconds between password and code
const TFA_RECOVERY_CODE_COUNT = 10;
const TFA_RECOVERY_ALPHABET   = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
const TFA_CODES_LOW           = 3;                        // profile warns at or below this

/* Who must use it (decision 1). Admins only for now; add 'staff' here once the
   staff side exists. Customers are never required — they may turn it on. */
function tfa_required_for(string $accountType): bool
{
    return $accountType === 'admin';
}

/* Who may have it at all: required accounts, and customers who choose it.
   Staff are left out entirely until their side is built. */
function tfa_available_for(string $accountType): bool
{
    return tfa_required_for($accountType) || $accountType === 'customer';
}

/* Whether 2FA is on for this account, and how many recovery codes remain. */
function tfa_status(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        'SELECT u.totp_secret IS NOT NULL AS enabled, u.totp_enabled_at,
                (SELECT COUNT(*) FROM user_recovery_codes r
                  WHERE r.user_id = u.id AND r.used_at IS NULL) AS codes_left
           FROM users u WHERE u.id = :u'
    );
    $stmt->execute([':u' => $userId]);
    $row = $stmt->fetch();
    $stmt->closeCursor();

    if ($row === false) {
        return ['enabled' => false, 'enabled_at' => null, 'codes_left' => 0];
    }
    return [
        'enabled'    => (bool) $row['enabled'],
        'enabled_at' => $row['totp_enabled_at'],
        'codes_left' => (int) $row['codes_left'],
    ];
}

/* Upper-cases and strips everything but letters/digits, so "abcde-fghij",
   "ABCDE FGHIJ" and "abcdefghij" all normalise the same way. */
function tfa_normalise_recovery(string $input): string
{
    return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
}

/* Deletes the user's existing recovery codes and generates 10 new ones.
   Caller holds the transaction. Returns the 10 display forms (shown once —
   only the SHA-256 hash is stored). */
function tfa_replace_recovery_codes(PDO $pdo, int $userId): array
{
    $del = $pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :u');
    $del->execute([':u' => $userId]);

    $alphabetLen = strlen(TFA_RECOVERY_ALPHABET);
    $insert = $pdo->prepare(
        'INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:u, :h)'
    );

    $codes = [];
    while (count($codes) < TFA_RECOVERY_CODE_COUNT) {
        $raw = '';
        for ($i = 0; $i < 10; $i++) {
            $raw .= TFA_RECOVERY_ALPHABET[random_int(0, $alphabetLen - 1)];
        }
        if (in_array($raw, $codes, true)) {
            continue;   // extremely unlikely, but keep the 10 distinct
        }
        $codes[] = $raw;
        $insert->execute([':u' => $userId, ':h' => hash('sha256', $raw)]);
    }

    return array_map(
        static fn (string $raw) => substr($raw, 0, 5) . '-' . substr($raw, 5, 5),
        $codes
    );
}

/* Turns 2FA on (or replaces the secret, e.g. moving to a new phone) and
   issues a fresh set of recovery codes. $confirmedStep is the step of the
   code typed to confirm set-up, stored as totp_last_step so it cannot be
   replayed as a sign-in code.
   $expectedEnabledAt is the account's totp_enabled_at when this set-up began
   (null = 2FA was off). If it has changed since — 2FA set up or moved in
   another browser — nothing is written and null comes back: a slower second
   set-up must never overwrite a phone that was just registered. Checked in the
   UPDATE itself, so two set-ups racing cannot both win. */
function tfa_enable(PDO $pdo, int $userId, string $secret, int $confirmedStep, ?string $expectedEnabledAt): ?array
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE users
                SET totp_secret = :s, totp_enabled_at = NOW(), totp_last_step = :st,
                    totp_failed_attempts = 0, totp_lock_level = 0, totp_locked_until = NULL
              WHERE id = :u AND totp_enabled_at <=> :prev'
        );
        $stmt->execute([':s' => $secret, ':st' => $confirmedStep, ':u' => $userId, ':prev' => $expectedEnabledAt]);
        if ($stmt->rowCount() !== 1) {
            $pdo->rollBack();
            return null;
        }

        $codes = tfa_replace_recovery_codes($pdo, $userId);

        $pdo->commit();
        return $codes;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* Regenerates the 10 recovery codes for an account that already has 2FA on. */
function tfa_regenerate_codes(PDO $pdo, int $userId): array
{
    $pdo->beginTransaction();
    try {
        $codes = tfa_replace_recovery_codes($pdo, $userId);
        $pdo->commit();
        return $codes;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* Turns 2FA off and forgets its recovery codes via the stored procedure —
   the only reset path (decision 2). Never duplicate the procedure's column
   list here. */
function tfa_reset(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('CALL sp_reset_2fa(:u)');
    $stmt->execute([':u' => $userId]);
    $stmt->closeCursor();
}

/* The only way a 2FA code (TOTP or recovery) is checked. Mirrors the
   refund-switch lockout ladder (admin/refund-switch.php:72-125), but in the
   totp_* columns so a sign-in lockout never blocks an admin action. */
function tfa_verify(PDO $pdo, int $userId, string $input): array
{
    $trimmed = trim($input);
    if ($trimmed === '') {
        return ['ok' => false, 'error' => 'empty'];
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT totp_secret, totp_last_step, totp_failed_attempts, totp_lock_level,
                    CASE WHEN totp_locked_until > NOW()
                         THEN TIMESTAMPDIFF(SECOND, NOW(), totp_locked_until)
                         ELSE 0 END AS lock_seconds
               FROM users WHERE id = :u FOR UPDATE'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        $stmt->closeCursor();

        if ($row === false || $row['totp_secret'] === null) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'not_enabled'];
        }

        $lockSeconds = (int) $row['lock_seconds'];
        if ($lockSeconds > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'locked', 'seconds' => $lockSeconds];
        }

        $secret = (string) $row['totp_secret'];
        $lastStep = $row['totp_last_step'] !== null ? (int) $row['totp_last_step'] : null;

        $matchedStep = totp_match_step($secret, $trimmed, time(), $lastStep);
        if ($matchedStep === null && totp_is_used($secret, $trimmed, time(), $lastStep)) {
            /* The right code, typed a second time (e.g. signed in, then straight into
               the profile): refused, but not a wrong guess — no attempt is spent. */
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'used'];
        }
        $usedAs = null;

        if ($matchedStep !== null) {
            $upd = $pdo->prepare('UPDATE users SET totp_last_step = :step WHERE id = :u');
            $upd->execute([':step' => $matchedStep, ':u' => $userId]);
            $usedAs = 'totp';
        } else {
            $normalised = tfa_normalise_recovery($trimmed);
            if (strlen($normalised) === 10) {
                $hash = hash('sha256', $normalised);
                $burn = $pdo->prepare(
                    'UPDATE user_recovery_codes SET used_at = NOW()
                      WHERE user_id = :u AND code_hash = :h AND used_at IS NULL'
                );
                $burn->execute([':u' => $userId, ':h' => $hash]);
                if ($burn->rowCount() === 1) {
                    $usedAs = 'recovery';
                }
            }
        }

        if ($usedAs !== null) {
            $reset = $pdo->prepare(
                'UPDATE users SET totp_failed_attempts = 0, totp_lock_level = 0, totp_locked_until = NULL
                  WHERE id = :u'
            );
            $reset->execute([':u' => $userId]);
            $pdo->commit();
            return ['ok' => true, 'used' => $usedAs];
        }

        // Wrong code / wrong recovery code.
        $attempts = (int) $row['totp_failed_attempts'] + 1;

        if ($attempts >= TFA_ATTEMPTS_PER_LOCK) {
            $level = min((int) $row['totp_lock_level'] + 1, 255);
            $idx = min($level, count(TFA_LOCK_SECONDS)) - 1;
            $seconds = TFA_LOCK_SECONDS[$idx];

            $lock = $pdo->prepare(
                'UPDATE users
                    SET totp_failed_attempts = 0, totp_lock_level = :l,
                        totp_locked_until = DATE_ADD(NOW(), INTERVAL :sec SECOND)
                  WHERE id = :u'
            );
            $lock->execute([':l' => $level, ':sec' => $seconds, ':u' => $userId]);
            $pdo->commit();
            return ['ok' => false, 'error' => 'locked', 'seconds' => $seconds];
        }

        $store = $pdo->prepare('UPDATE users SET totp_failed_attempts = :a WHERE id = :u');
        $store->execute([':a' => $attempts, ':u' => $userId]);
        $pdo->commit();
        return ['ok' => false, 'error' => 'wrong', 'attemptsLeft' => TFA_ATTEMPTS_PER_LOCK - $attempts];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ---------------------------------------------------------------------
   Session helpers for the "password correct, code pending" state.
   Plan 002 uses these; the caller has already started the session.
   The pending state deliberately does NOT set $_SESSION['user_id'], so
   admin_require_login() / customer_logged_in() treat it as logged out.
   --------------------------------------------------------------------- */

function tfa_pending_start(int $userId, string $side, string $mode, array $extra = []): void
{
    $setup = $_SESSION['tfa_setup'] ?? null;   // a scanned-but-unconfirmed key must survive a fresh sign-in
    session_regenerate_id(true);
    $_SESSION = [];
    if ($setup !== null) {
        $_SESSION['tfa_setup'] = $setup;
    }
    $_SESSION['tfa_pending'] = ['user_id' => $userId, 'side' => $side, 'mode' => $mode, 'at' => time()] + $extra;
}

/* ---------------------------------------------------------------------
   THE KEY BEING SET UP. One per browser, tied to one account, kept for
   TFA_SETUP_REUSE seconds until a code from it is confirmed. Showing the
   SAME QR after a reload, a second "Turn on" or a re-entered password is
   the point: the entry the person already scanned keeps working, instead
   of silently going dead. Used by the staff sign-in and both profiles.
   --------------------------------------------------------------------- */
const TFA_SETUP_REUSE = 1800;

/* The waiting key for this account, or null (none, another account's, too old,
   or made before the account's 2FA last changed). $enabledAt is the account's
   current users.totp_enabled_at (null while off): a key waiting since before
   2FA was turned on or moved ELSEWHERE must never be able to replace that phone. */
function tfa_setup_current(int $userId, ?string $enabledAt = null): ?string
{
    $s = $_SESSION['tfa_setup'] ?? null;
    if (!is_array($s) || (int) ($s['user_id'] ?? 0) !== $userId
        || ($s['state'] ?? null) !== $enabledAt
        || time() - (int) ($s['at'] ?? 0) > TFA_SETUP_REUSE) {
        return null;
    }
    return (string) $s['secret'];
}

/* The waiting key for this account, making one if there is none. */
function tfa_setup_secret(int $userId, ?string $enabledAt = null): string
{
    $secret = tfa_setup_current($userId, $enabledAt);
    if ($secret === null) {
        $secret = totp_new_secret();
        $_SESSION['tfa_setup'] = ['user_id' => $userId, 'state' => $enabledAt, 'secret' => $secret, 'at' => time()];
    }
    return $secret;
}

function tfa_setup_clear(): void
{
    unset($_SESSION['tfa_setup']);
}

/* How the account appears in the authenticator app (totp_uri() labels it this way).
   Named in every prompt, because one phone often holds several VENUSeP entries
   (a staff login and a customer login) and only this account's entry works here. */
function tfa_entry_name(string $account): string
{
    return TOTP_ISSUER . ' (' . $account . ')';
}

/* Set-up refused: the typed code is not from the entry for this key. */
function tfa_mismatch_message(string $account): string
{
    return 'That code does not match. Use the newest code from the ' . tfa_entry_name($account) . ' entry in your app.';
}

function tfa_pending(string $side): ?array
{
    $pending = $_SESSION['tfa_pending'] ?? null;
    if (!is_array($pending)) {
        return null;
    }
    if (($pending['side'] ?? null) !== $side) {
        return null;   // belongs to the other side — leave it alone
    }
    if (time() - (int) ($pending['at'] ?? 0) > TFA_PENDING_TTL) {
        unset($_SESSION['tfa_pending']);
        return null;
    }
    return $pending;
}

function tfa_pending_update(array $changes): void
{
    $_SESSION['tfa_pending'] = $changes + ($_SESSION['tfa_pending'] ?? []);
}

function tfa_pending_clear(): void
{
    unset($_SESSION['tfa_pending']);
}

/* ---------------------------------------------------------------------
   THE SIGN-IN FLOW, shared by admin/admin-login.php and
   customer/customer-login.php. Each page supplies only $finish: the
   function that creates its real session and never returns. Both pages
   then run the exact same steps, so a fix to one is a fix to both.
   --------------------------------------------------------------------- */

/* After a correct password. Policy lives in tfa_required_for(): an admin always
   goes on to a code (or to setting up a phone); a customer only if they turned
   it on; anyone else signs straight in. Never returns. */
function tfa_after_password(PDO $pdo, int $userId, string $side, string $accountType, string $email,
                            array $extra, string $self, callable $finish): void
{
    if (!tfa_available_for($accountType)) {
        $finish();   // staff, for now: password only, even if an old test key is still on the account
    }
    $tfa = tfa_status($pdo, $userId);
    if (!$tfa['enabled'] && !tfa_required_for($accountType)) {
        $finish();
    }
    tfa_pending_start($userId, $side, $tfa['enabled'] ? 'verify' : 'enroll', $extra + ['email' => $email]);
    header('Location: ' . $self);   // PRG: the code step is a fresh GET; set-up takes its key from tfa_setup_secret()
    exit;
}

/* One POSTed step (tfa_step = code | enroll_confirm | codes_done | cancel).
   Returns [loginError, tfaError] for the page; success calls $finish($pending). */
function tfa_login_step(PDO $pdo, string $side, string $self, callable $finish): array
{
    $pending = tfa_pending($side);
    if ($pending === null) {
        return ['Your sign-in timed out. Enter your password again.', ''];
    }
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        return ['', 'This page expired. Try again.'];
    }
    $uid  = (int) $pending['user_id'];
    $step = (string) ($_POST['tfa_step'] ?? '');
    $code = (string) ($_POST['code'] ?? '');

    if ($step === 'cancel' && $pending['mode'] !== 'codes') {   // no way out once 2FA is on
        tfa_pending_clear();
        header('Location: ' . $self);
        exit;
    }
    if ($step === 'code' && $pending['mode'] === 'verify') {
        $result = tfa_verify($pdo, $uid, $code);
        if ($result['ok']) {
            $finish($pending);
        }
        return ['', tfa_error_message($result)];
    }
    if ($step === 'enroll_confirm' && $pending['mode'] === 'enroll') {
        $secret = tfa_setup_current($uid);
        if ($secret === null) {   // kept too long: the page now shows a fresh key
            return ['', 'That set-up expired. Scan the new QR code below, then enter its code.'];
        }
        $matched = totp_match_step($secret, $code, time(), null);
        if ($matched === null) {
            return ['', tfa_mismatch_message((string) $pending['email'])];
        }
        $codes = tfa_enable($pdo, $uid, $secret, $matched, null);   // sign-in set-up: only while 2FA is still off
        if ($codes === null) {
            tfa_setup_clear();
            tfa_pending_clear();
            return ['Two-step verification was just set up for this account somewhere else. Sign in again and use a code from that phone.', ''];
        }
        tfa_setup_clear();
        /* 'at' restarts: saving the codes gets its own TFA_PENDING_TTL, rather than
           whatever was left after installing the app and scanning. */
        tfa_pending_update(['mode' => 'codes', 'codes' => $codes, 'at' => time()]);
        header('Location: ' . $self);   // a refresh must not re-submit the code
        exit;
    }
    if ($step === 'codes_done' && $pending['mode'] === 'codes') {
        if (empty($_POST['saved'])) {
            return ['', 'Tick the box to confirm you saved your recovery codes.'];
        }
        $finish($pending);
    }
    return ['', ''];
}

/* Seconds left on a running code lock (0 = not locked), so a page can show the
   countdown again after a reload instead of hiding a lock that is still running. */
function tfa_lock_seconds(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare(
        'SELECT CASE WHEN totp_locked_until > NOW()
                     THEN TIMESTAMPDIFF(SECOND, NOW(), totp_locked_until) ELSE 0 END
           FROM users WHERE id = :u'
    );
    $stmt->execute([':u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/* A lock's remaining time in words: "45 seconds", "14 min 59 s", "3 h 59 min".
   Mirrored by waitText() in assets/js/two-factor.js, which counts it down. */
function tfa_wait_text(int $s): string
{
    if ($s >= 3600) {
        return intdiv($s, 3600) . ' h ' . intdiv($s % 3600, 60) . ' min';
    }
    if ($s >= 60) {
        return intdiv($s, 60) . ' min ' . ($s % 60) . ' s';
    }
    return $s . ($s === 1 ? ' second' : ' seconds');
}

/* The one wording for a failed tfa_verify(), shared by both sign-in pages and the profile. */
function tfa_error_message(array $r): string
{
    switch ($r['error'] ?? '') {
        case 'empty':
            return 'Enter the 6-digit code from your authenticator app.';
        case 'used':
            return 'That code was already used. Wait for the next code in your app, then try again.';
        case 'locked':
            return 'Too many wrong codes. Try again in ' . tfa_wait_text((int) $r['seconds']) . '.';
        case 'wrong':
            $left = (int) $r['attemptsLeft'];
            return 'That code is not right. ' . $left . ($left === 1 ? ' more try' : ' more tries') . ' before a short lock.';
        default:
            return 'Two-step verification is not set up for this account.';
    }
}
