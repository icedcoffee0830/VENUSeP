<?php
/* =====================================================================
   CLI test for includes/mailer.php — settings handling, no database and
   no network.
   Run:  php tests/mailer_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/mailer.php';

$failures = 0;

function mt_check(string $name, $got, $want): void
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

/* --- no settings file: nothing may leave the machine --- */
$missing = mail_config_from(__DIR__ . '/does-not-exist-' . bin2hex(random_bytes(4)) . '.php');
mt_check('missing settings file -> log transport', $missing['transport'], 'log');
mt_check('missing settings file -> no password', $missing['password'], '');
mt_check('test switch is off by default', $missing['redirect_all_to'], '');

/* --- a settings file is merged over the defaults --- */
$tmp = tempnam(sys_get_temp_dir(), 'vmc');
file_put_contents($tmp, "<?php return ['transport' => 'smtp', 'port' => 2525];");
$merged = mail_config_from($tmp);
mt_check('settings file overrides transport', $merged['transport'], 'smtp');
mt_check('settings file overrides port', $merged['port'], 2525);
mt_check('defaults fill the rest (from_name)', $merged['from_name'], 'VENUSeP');

/* --- a file that does not return an array is ignored, not trusted --- */
file_put_contents($tmp, "<?php return 'oops';");
mt_check('non-array settings -> log transport', mail_config_from($tmp)['transport'], 'log');
unlink($tmp);

/* --- links in emails never end in a slash --- */
mt_check('mail_base_url has no trailing slash', substr(mail_base_url(), -1) !== '/', true);

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
