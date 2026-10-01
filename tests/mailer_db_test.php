<?php
/* =====================================================================
   CLI test for includes/mailer.php against the real local `venusep`
   database: queueing, the 'log' transport, idempotent re-sends, and a
   failed SMTP send. Every row and .eml file it makes is removed
   afterwards, even on failure. Never talks to a real mail server: the
   "failing" case points at a closed local port.
   Run:  php tests/mailer_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/mailer.php';

$failures = 0;

function md_check(string $name, $got, $want): void
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

function md_row(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT status, attempts, last_error, sent_at FROM email_outbox WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: [];
}

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database (venusep_db() returned null).\n");
    exit(1);
}

$logConfig  = array_merge(mail_config_from(''), ['transport' => 'log']);
$deadConfig = array_merge(mail_config_from(''), [
    'transport' => 'smtp', 'host' => '127.0.0.1', 'port' => 9,    // nothing listens on port 9
    'username' => 'selftest', 'password' => 'selftest-secret-9f31',
]);
$message = [
    'kind'      => 'walkin_booking',
    'to_email'  => 'mailer-selftest@example.invalid',
    'subject'   => 'VENUSeP mailer self-test ' . bin2hex(random_bytes(3)),
    'body_html' => '<p>Self-test.</p>',
    'body_text' => 'Self-test.',
];
$made = [];

try {
    /* --- queueing rejects junk --- */
    $threw = false;
    try {
        mail_enqueue($pdo, array_merge($message, ['to_email' => 'not an address']));
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    md_check('enqueue rejects a bad address', $threw, true);

    $threw = false;
    try {
        mail_enqueue($pdo, array_merge($message, ['kind' => 'newsletter']));
    } catch (InvalidArgumentException $e) {
        $threw = true;
    }
    md_check('enqueue rejects an unknown kind', $threw, true);

    /* --- queue, then send through the log transport --- */
    $id = mail_enqueue($pdo, $message);
    $made[] = $id;
    md_check('enqueue returns an id', $id > 0, true);
    md_check('queued row is pending', md_row($pdo, $id)['status'] ?? null, 'pending');

    md_check('log send returns true', mail_send($pdo, $id, $logConfig), true);
    $row = md_row($pdo, $id);
    md_check('row becomes sent', $row['status'] ?? null, 'sent');
    md_check('one attempt recorded', (int) ($row['attempts'] ?? -1), 1);
    md_check('sent_at is set', !empty($row['sent_at']), true);

    $eml = doc_root() . '/mail-log/' . $id . '.eml';
    md_check('.eml file written', is_file($eml), true);
    md_check('.eml carries the subject', strpos((string) @file_get_contents($eml), $message['subject']) !== false, true);

    /* --- an already-sent row is not sent twice --- */
    md_check('re-send returns true', mail_send($pdo, $id, $logConfig), true);
    md_check('re-send does not count another attempt', (int) (md_row($pdo, $id)['attempts'] ?? -1), 1);

    /* --- a mail server that is down: false, failed, no password in the error --- */
    $bad = mail_enqueue($pdo, $message);
    $made[] = $bad;
    md_check('dead server send returns false', mail_send($pdo, $bad, $deadConfig), false);
    $row = md_row($pdo, $bad);
    md_check('row becomes failed', $row['status'] ?? null, 'failed');
    md_check('error recorded', trim((string) ($row['last_error'] ?? '')) !== '', true);
    md_check('error never holds the password', strpos((string) ($row['last_error'] ?? ''), 'selftest-secret-9f31'), false);

    /* --- a failed row can be sent later (what Resend does) --- */
    md_check('resend after failure returns true', mail_send($pdo, $bad, $logConfig), true);
    $row = md_row($pdo, $bad);
    md_check('resent row becomes sent', $row['status'] ?? null, 'sent');
    md_check('resend clears the old error', array_key_exists('last_error', $row) ? $row['last_error'] : 'missing', null);
    md_check('both attempts counted', (int) ($row['attempts'] ?? -1), 2);

    /* --- an id that does not exist --- */
    md_check('unknown id returns false', mail_send($pdo, 2147483000, $logConfig), false);
} finally {
    foreach ($made as $id) {
        $pdo->prepare('DELETE FROM email_outbox WHERE id = :id')->execute([':id' => $id]);
        @unlink(doc_root() . '/mail-log/' . $id . '.eml');
    }
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
