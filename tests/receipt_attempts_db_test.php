<?php
/* =====================================================================
   CLI test for the receipt rules of migration 05 (DB-DECISIONS #6,
   2026-10-26), against the real local `venusep` database, with a
   throwaway customer + booking removed afterwards, even on failure.
     - a REJECTED receipt is recorded but blocks nothing (reference and
       image stay reusable); one that was NOT rejected blocks both forever
     - a rejected receipt needs no payment row (payment_id NULL)
     - a new booking starts with receipt_attempts_allowed = 5
     - a STAFF rejection keeps the receipt blocked, and says what became
       of each upload (booking_receipt_outcome)
   The endpoint flow itself (counting, the 5th going to staff, the 6th
   refused, "Allow more attempts") needs a web session; it is exercised
   in the browser test, not here.
   Run:  php tests/receipt_attempts_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/bookings.php';   /* booking_receipt_attempts() + its outcome wording */

const RAT_EMAIL = 'receipt-attempts-selftest@example.invalid';

$failures = 0;
function rat_check(string $name, $got, $want): void
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
$col = $pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                     AND TABLE_NAME = 'gcash_receipts' AND COLUMN_NAME = 'active_reference'")->fetchColumn();
if ((int) $col === 0) {
    fwrite(STDERR, "gcash_receipts.active_reference is missing: run venusep_migration_05.sql first.\n");
    exit(1);
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id
                          JOIN users u ON u.id = c.user_id WHERE u.email = '" . RAT_EMAIL . "'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
        $pdo->exec('DELETE FROM gcash_receipts WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM payments WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM bookings WHERE id = ' . (int) $bid);   // details, slots, timeline cascade
    }
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = '" . RAT_EMAIL . "'");
    $pdo->exec("DELETE FROM users WHERE email = '" . RAT_EMAIL . "'");
};
$cleanup();

/* one receipt row; returns true when the database accepted it */
$addReceipt = function (int $bookingId, ?int $paymentId, int $no, string $ref, string $sha, string $verdict) use ($pdo) {
    try {
        $pdo->prepare(
            'INSERT INTO gcash_receipts (payment_id, booking_id, attempt_no, file_path, reference_number, sha256_hash, verdict)
             VALUES (:p, :b, :n, :f, :r, :h, :v)'
        )->execute([':p' => $paymentId, ':b' => $bookingId, ':n' => $no, ':f' => 'selftest/none.png',
                    ':r' => $ref, ':h' => $sha, ':v' => $verdict]);
        return true;
    } catch (PDOException $e) {
        return $e->getCode() === '23000' ? false : throw $e;
    }
};

try {
    $pdo->prepare("INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, 'rat_selftest', 'x', 'customer')")
        ->execute([':e' => RAT_EMAIL]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (user_id, full_name) VALUES (:u, 'Receipt Attempts Selftest')")->execute([':u' => $uid]);
    $cid = (int) $pdo->lastInsertId();
    $room = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'event' ORDER BY id LIMIT 1")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                               room_price, total_amount, refunds_allowed)
         VALUES (:c, :r, 'venue', 'approved', 'await_gcash', 'gcash', 1000, 1000, 1)"
    )->execute([':c' => $cid, ':r' => $room]);
    $bid = (int) $pdo->lastInsertId();

    rat_check('1. a new booking allows 5 receipt attempts',
        (int) $pdo->query('SELECT receipt_attempts_allowed FROM bookings WHERE id = ' . $bid)->fetchColumn(), 5);

    $ref = '9' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);   // 13 digits, unused
    $shaA = hash('sha256', 'selftest-image-A-' . $ref);
    $shaB = hash('sha256', 'selftest-image-B-' . $ref);

    rat_check('2. a rejected upload is recorded with no payment', $addReceipt($bid, null, 1, $ref, $shaA, 'rejected'), true);
    rat_check('2. the same reference + image rejected again: still recorded', $addReceipt($bid, null, 2, $ref, $shaA, 'rejected'), true);
    $row = $pdo->query('SELECT active_reference, active_sha256 FROM gcash_receipts WHERE booking_id = ' . $bid . ' AND attempt_no = 1')->fetch();
    rat_check('2. a rejected row holds no active reference', $row['active_reference'], null);
    rat_check('2. a rejected row holds no active image', $row['active_sha256'], null);

    $pdo->prepare("INSERT INTO payments (booking_id, payment_method, amount, payment_record_status) VALUES (:b, 'gcash', 1000, 'under_review')")
        ->execute([':b' => $bid]);
    $pay = (int) $pdo->lastInsertId();
    rat_check('3. after rejections, the SAME receipt can still be accepted', $addReceipt($bid, $pay, 3, $ref, $shaA, 'accepted'), true);
    rat_check('3. now its reference is taken (another image)', $addReceipt($bid, $pay, 4, $ref, $shaB, 'manual_review'), false);
    rat_check('3. now its image is taken (another reference)', $addReceipt($bid, $pay, 5, $ref . '1', $shaA, 'manual_review'), false);
    rat_check('3. ...but it can still be recorded as a rejected duplicate', $addReceipt($bid, null, 6, $ref, $shaA, 'rejected'), true);
    rat_check('3. a fresh reference + image is fine', $addReceipt($bid, $pay, 7, $ref . '2', $shaB, 'manual_review'), true);

    rat_check('4. every upload is counted',
        (int) $pdo->query('SELECT COUNT(*) FROM gcash_receipts WHERE booking_id = ' . $bid)->fetchColumn(), 5);
    rat_check('4. only rejected uploads lack a payment',
        (int) $pdo->query("SELECT COUNT(*) FROM gcash_receipts WHERE booking_id = $bid AND payment_id IS NULL AND verdict <> 'rejected'")->fetchColumn(), 0);

    /* 5. A staff rejection (admin/booking-action.php reject_payment) records the
          decision on the receipt but keeps the checker's verdict — so the
          receipt stays blocked, and the customer is told what became of it. */
    $pdo->prepare(
        "UPDATE gcash_receipts
            SET reviewed_at = NOW(), override_note = 'Not found in GCash (selftest).',
                flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'rejected_by_staff')
          WHERE booking_id = :b AND attempt_no = 3"
    )->execute([':b' => $bid]);
    $row = $pdo->query('SELECT verdict, active_reference, active_sha256 FROM gcash_receipts WHERE booking_id = ' . $bid . ' AND attempt_no = 3')->fetch();
    rat_check('5. a staff-rejected receipt keeps the checker verdict', $row['verdict'], 'accepted');
    rat_check('5. ...and its reference stays taken', $row['active_reference'], $ref);
    rat_check('5. ...and its image stays taken', $row['active_sha256'], $shaA);
    rat_check('5. the same receipt cannot come back (same image)', $addReceipt($bid, $pay, 8, $ref . '3', $shaA, 'manual_review'), false);
    $byNo = [];
    foreach (booking_receipt_attempts($bid) as $a) { $byNo[(int) $a['attempt_no']] = $a['outcome']; }
    rat_check('5. outcome of a staff rejection', $byNo[3], 'rejected by staff — "Not found in GCash (selftest)."');
    rat_check('5. outcome of a checker rejection', $byNo[1], 'rejected by the receipt check');
    rat_check('5. outcome of a receipt with staff', $byNo[7], 'sent to staff for review');
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
