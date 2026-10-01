<?php
/* =====================================================================
   CLI test of the receipt flow against the real local `venusep`
   database: issuing a System Receipt, the walk-in emails, the "Email me"
   throttle, and the PDF attachment. Everything runs inside ONE
   transaction that is rolled back at the end, so the database is left
   exactly as it was — even on failure. Mail goes through the 'log'
   transport only (a .eml file, removed afterwards); nothing is sent.
   Run:  php tests/receipt_flow_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/receipt-emails.php';

$failures = 0;

function rf_check(string $name, $got, $want): void
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

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Cannot reach the venusep database (venusep_db() returned null).\n");
    exit(1);
}

$logConfig = array_merge(mail_config_from(''), ['transport' => 'log']);
$emlFiles = [];

/* A walk-in venue booking, paid in cash, built the way booking-create.php builds one. */
function rf_booking(PDO $pdo, ?string $contactEmail): int
{
    $pdo->prepare('INSERT INTO customers (user_id, full_name, phone, contact_email) VALUES (NULL, :n, :p, :e)')
        ->execute([':n' => 'Receipt Selftest', ':p' => '09170000000', ':e' => $contactEmail]);
    $customerId = (int) $pdo->lastInsertId();
    $roomId = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'event' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                               room_price, discount_percent, discount_amount, total_amount, refunds_allowed)
         VALUES (:c, :r, 'venue', 'approved', 'paid_cash', 'cash', 5000, 0, 0, 5000, 0)"
    )->execute([':c' => $customerId, ':r' => $roomId]);
    $bookingId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
         VALUES (:b, 'Receipt self-test', CURDATE() + INTERVAL 30 DAY, CURDATE() + INTERVAL 30 DAY, 10)"
    )->execute([':b' => $bookingId]);
    return $bookingId;
}

function rf_pay(PDO $pdo, int $bookingId): void
{
    $pdo->prepare(
        "INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at, confirmed_at)
         VALUES (:b, 'cash', 5000, 'confirmed', NOW(), NOW())"
    )->execute([':b' => $bookingId]);
}

$pdo->beginTransaction();
try {
    /* --- issuing --- */
    $withEmail = rf_booking($pdo, 'receipt-selftest@example.invalid');
    rf_check('no confirmed payment -> no receipt', receipt_issue($pdo, $withEmail, 0), null);
    rf_pay($pdo, $withEmail);
    $rid = receipt_issue($pdo, $withEmail, 0);
    rf_check('confirmed payment -> receipt issued', is_int($rid) && $rid > 0, true);
    rf_check('issuing again returns the same receipt', receipt_issue($pdo, $withEmail, 0), $rid);

    $r = receipt_load($pdo, $rid);
    rf_check('receipt number shape', (bool) preg_match('/^VSR-\d{4}-\d{6}$/', $r['number']), true);
    rf_check('snapshot freezes the amount paid', (float) $r['snapshot']['amount_paid'], 5000.0);
    rf_check('snapshot carries the walk-in email', $r['snapshot']['customer_email'], 'receipt-selftest@example.invalid');
    rf_check('receipt_for_booking finds it', (int) receipt_for_booking($pdo, $withEmail)['id'], $rid);

    /* --- walk-in emails --- */
    $bookingMail = queue_walkin_booking($pdo, $withEmail, 0);
    rf_check('walk-in confirmation queued', is_int($bookingMail), true);
    $receiptMail = queue_walkin_receipt($pdo, $withEmail, $rid, 0);
    rf_check('walk-in receipt queued', is_int($receiptMail), true);
    $stmt = $pdo->prepare('SELECT kind, receipt_id, to_email FROM email_outbox WHERE id = :id');
    $stmt->execute([':id' => $receiptMail]);
    $row = $stmt->fetch();
    rf_check('receipt email kind', $row['kind'], 'walkin_receipt');
    rf_check('receipt email attaches this receipt', (int) $row['receipt_id'], $rid);
    rf_check('receipt email goes to the counter address', $row['to_email'], 'receipt-selftest@example.invalid');

    /* --- no email given -> nothing queued, receipt still issued --- */
    $noEmail = rf_booking($pdo, null);
    rf_pay($pdo, $noEmail);
    $rid2 = receipt_issue($pdo, $noEmail, 0);
    rf_check('walk-in without email still gets a receipt', is_int($rid2), true);
    rf_check('walk-in without email: no confirmation', queue_walkin_booking($pdo, $noEmail, 0), null);
    rf_check('walk-in without email: no receipt email', queue_walkin_receipt($pdo, $noEmail, $rid2, 0), null);

    /* --- "Email me this receipt" throttle --- */
    rf_check('no copy yet -> not throttled', receipt_copy_sent_recently($pdo, $rid), false);
    $anyUser = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    $copy = queue_receipt_copy($pdo, $rid, $anyUser, 'copy-selftest@example.invalid', 'Copy Selftest');
    rf_check('copy queued', is_int($copy), true);
    rf_check('copy just queued -> throttled', receipt_copy_sent_recently($pdo, $rid), true);

    /* --- sending attaches the PDF --- */
    rf_check('log send of the receipt email', mail_send($pdo, $receiptMail, $logConfig), true);
    $eml = doc_root() . '/mail-log/' . $receiptMail . '.eml';
    $emlFiles[] = $eml;
    $raw = (string) @file_get_contents($eml);
    rf_check('.eml carries a PDF attachment', strpos($raw, 'application/pdf') !== false, true);
    rf_check('.eml names the PDF after the receipt', strpos($raw, $r['number'] . '.pdf') !== false, true);
    rf_check('.eml embeds the logo', strpos($raw, 'venusep-logo') !== false, true);
} finally {
    $pdo->rollBack();
    foreach ($emlFiles as $f) {
        @unlink($f);
    }
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
