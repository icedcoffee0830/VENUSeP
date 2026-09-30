<?php
/* =====================================================================
   TOTP (RFC 6238) — the maths behind Google Authenticator codes.

   The app and this file each compute HMAC-SHA1(secret, floor(unixtime/30))
   and cut it down to 6 digits; if the two numbers match, the person has the
   phone that scanned the secret. No Google account or API is involved — any
   TOTP app works. Pure functions: no database, no session, so
   tests/totp_test.php can check them against the RFC's own test vectors.
   The account side (lockout, recovery codes) is includes/two-factor.php.
   ===================================================================== */

const TOTP_PERIOD   = 30;
const TOTP_DIGITS   = 6;
const TOTP_ISSUER   = 'VENUSeP';
const TOTP_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';   // RFC 4648 base32

function totp_base32_encode(string $bytes): string
{
    $bits = '';
    for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
        $bits .= str_pad(decbin(ord($bytes[$i])), 8, '0', STR_PAD_LEFT);
    }
    if ($bits === '') {
        return '';
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= TOTP_ALPHABET[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
    }
    return $out;
}

/* null = not valid base32. Spaces, dashes and '=' padding are ignored. */
function totp_base32_decode(string $b32): ?string
{
    $b32 = strtoupper((string) preg_replace('/[\s=-]+/', '', $b32));
    $bits = '';
    for ($i = 0, $n = strlen($b32); $i < $n; $i++) {
        $v = strpos(TOTP_ALPHABET, $b32[$i]);
        if ($v === false) {
            return null;
        }
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    for ($i = 0; $i + 8 <= strlen($bits); $i += 8) {
        $out .= chr(bindec(substr($bits, $i, 8)));
    }
    return $out;
}

/* 160 random bits, the size RFC 4226 recommends. 32 base32 chars, no padding. */
function totp_new_secret(): string
{
    return totp_base32_encode(random_bytes(20));
}

/* The code for one 30-second step (RFC 4226 HOTP with counter = step). */
function totp_code(string $secretB32, int $step, int $digits = TOTP_DIGITS): string
{
    $key = (string) totp_base32_decode($secretB32);
    $hash = hash_hmac('sha1', pack('J', $step), $key, true);   // J = 64-bit big-endian
    $offset = ord($hash[19]) & 0x0f;
    $value = ((ord($hash[$offset]) & 0x7f) << 24)
           | (ord($hash[$offset + 1]) << 16)
           | (ord($hash[$offset + 2]) << 8)
           | ord($hash[$offset + 3]);
    return str_pad((string) ($value % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
}

/* The step $code belongs to, or null. Accepts the previous, current and next
   step (phone clocks drift). A step at or before $lastStep is refused, so a
   code that already worked cannot be used again. */
function totp_match_step(string $secretB32, string $code, int $now, ?int $lastStep): ?int
{
    $code = (string) preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return null;
    }
    $current = intdiv($now, TOTP_PERIOD);
    foreach ([$current - 1, $current, $current + 1] as $step) {
        if ($lastStep !== null && $step <= $lastStep) {
            continue;
        }
        if (hash_equals(totp_code($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

/* True when $code is a genuine code whose step was already used (at or before
   $lastStep): the person typed a code twice, which is not the same as a wrong one. */
function totp_is_used(string $secretB32, string $code, int $now, ?int $lastStep): bool
{
    $code = (string) preg_replace('/\s+/', '', $code);
    if ($lastStep === null || !preg_match('/^\d{' . TOTP_DIGITS . '}$/', $code)) {
        return false;
    }
    $current = intdiv($now, TOTP_PERIOD);
    foreach ([$current - 1, $current, $current + 1] as $step) {
        if ($step <= $lastStep && hash_equals(totp_code($secretB32, $step), $code)) {
            return true;
        }
    }
    return false;
}

/* What the QR code holds (Google's "Key Uri Format"). */
function totp_uri(string $secretB32, string $account): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_ISSUER) . ':' . rawurlencode($account)
        . '?secret=' . $secretB32
        . '&issuer=' . rawurlencode(TOTP_ISSUER)
        . '&algorithm=SHA1&digits=' . TOTP_DIGITS . '&period=' . TOTP_PERIOD;
}
