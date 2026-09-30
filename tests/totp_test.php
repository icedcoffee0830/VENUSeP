<?php
/* =====================================================================
   CLI test for includes/totp.php — pure maths, no database.
   Run:  php tests/totp_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/totp.php';

$failures = 0;

function tt_check(string $name, $got, $want): void
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

/* --- base32 encode/decode --- */
tt_check(
    'base32_encode(12345678901234567890)',
    totp_base32_encode('12345678901234567890'),
    'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'
);
tt_check(
    'base32_decode round-trip',
    totp_base32_decode('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
    '12345678901234567890'
);
tt_check('base32_decode invalid (ABC1)', totp_base32_decode('ABC1'), null);

/* --- RFC 6238 Appendix B test vectors (SHA-1, 8 digits) --- */
$rfcSecret = totp_base32_encode('12345678901234567890');
$vectors = [
    59          => '94287082',
    1111111109  => '07081804',
    1111111111  => '14050471',
    1234567890  => '89005924',
    2000000000  => '69279037',
    20000000000 => '65353130',
];
foreach ($vectors as $t => $expected) {
    $step = intdiv($t, 30);
    tt_check("RFC6238 T={$t}", totp_code($rfcSecret, $step, 8), $expected);
}

/* --- totp_match_step --- */
$secret = totp_new_secret();
$now = 1234567890;
$step = intdiv($now, 30);

tt_check(
    'match_step current step',
    totp_match_step($secret, totp_code($secret, $step), $now, null),
    $step
);
tt_check(
    'match_step previous step (drift)',
    totp_match_step($secret, totp_code($secret, $step - 1), $now, null),
    $step - 1
);
tt_check(
    'match_step next step (drift)',
    totp_match_step($secret, totp_code($secret, $step + 1), $now, null),
    $step + 1
);
tt_check(
    'match_step two steps back rejected',
    totp_match_step($secret, totp_code($secret, $step - 2), $now, null),
    null
);
tt_check(
    'match_step replay rejected (lastStep = step)',
    totp_match_step($secret, totp_code($secret, $step), $now, $step),
    null
);

$codeWithSpace = substr(totp_code($secret, $step), 0, 3) . ' ' . substr(totp_code($secret, $step), 3);
tt_check(
    'match_step tolerates space in code',
    totp_match_step($secret, $codeWithSpace, $now, null),
    $step
);

tt_check('is_used: a used step is recognised', totp_is_used($secret, totp_code($secret, $step), $now, $step), true);
tt_check('is_used: an unused step is not', totp_is_used($secret, totp_code($secret, $step), $now, $step - 1), false);
tt_check('is_used: a wrong code is not', totp_is_used($secret, '000000' === totp_code($secret, $step) ? '111111' : '000000', $now, $step + 1), false);
tt_check('is_used: nothing used yet', totp_is_used($secret, totp_code($secret, $step), $now, null), false);
tt_check('match_step rejects "abcdef"', totp_match_step($secret, 'abcdef', $now, null), null);
tt_check('match_step rejects "12345"', totp_match_step($secret, '12345', $now, null), null);
tt_check('match_step rejects "1234567"', totp_match_step($secret, '1234567', $now, null), null);

/* --- totp_new_secret --- */
$newSecret = totp_new_secret();
tt_check('new_secret length', strlen($newSecret), 32);
tt_check('new_secret matches base32 alphabet', (bool) preg_match('/^[A-Z2-7]{32}$/', $newSecret), true);
$decoded = totp_base32_decode($newSecret);
tt_check('new_secret decodes to 20 bytes', strlen((string) $decoded), 20);

/* --- totp_uri --- */
$uri = totp_uri('ABC', 'a b@x.com');
tt_check(
    'uri starts correctly',
    str_starts_with($uri, 'otpauth://totp/VENUSeP:a%20b%40x.com?secret=ABC&issuer=VENUSeP'),
    true
);

if ($failures === 0) {
    echo "ALL PASS\n";
    exit(0);
}
echo "{$failures} FAILED\n";
exit(1);
