<?php
/* =====================================================================
   CLI test for migration 11 — the hostel is PAY TO USE (DB-DECISIONS #26,
   2026-10-27), against the real local `venusep` database, with a throwaway
   customer + bookings removed afterwards, even on failure.
     - every new hostel booking is pay_to_use; a venue booking never is
     - it is due by the END of its check-in day, under either refund policy
     - approval opens payment at once (no POS wait, no "after the stay")
     - unpaid at the end of the check-in day = released; a paid or
       under-review stay is never touched, and none ever turns "overdue"
     - a hostel stay booked before migration 11 keeps its old route
     - payment_confirm(..., asCash) makes it a paid cash booking with a
       payment row and a System Receipt
     - booking_shape() reports the policy and the pay-by day
   Calls sp_expire_due_bookings() directly, so it also expires any REAL
   booking that is due — which the next page load would do anyway.
   Run:  php tests/hostel_pay_to_use_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/bookings.php';
require __DIR__ . '/../includes/system-receipt.php';

const PTU_EMAIL = 'pay-to-use-selftest@example.invalid';

$failures = 0;
function ptu_check(string $name, $got, $want): void
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
                     AND TABLE_NAME = 'bookings' AND COLUMN_NAME = 'pay_to_use'")->fetchColumn();
if ((int) $col === 0) {
    fwrite(STDERR, "bookings.pay_to_use is missing: run venusep_migration_11.sql first.\n");
    exit(1);
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id
                          JOIN users u ON u.id = c.user_id WHERE u.email = '" . PTU_EMAIL . "'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
        $pdo->exec('DELETE FROM system_receipts WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM payments WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM bookings WHERE id = ' . (int) $bid);   // details, timeline cascade
    }
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = '" . PTU_EMAIL . "'");
    $pdo->exec("DELETE FROM users WHERE email = '" . PTU_EMAIL . "'");
};
$cleanup();

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));
$in5       = date('Y-m-d', strtotime('+5 days'));
$in7       = date('Y-m-d', strtotime('+7 days'));

try {
    $pdo->prepare("INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, 'ptu_selftest', 'x', 'customer')")
        ->execute([':e' => PTU_EMAIL]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (user_id, full_name) VALUES (:u, 'Pay To Use Selftest')")->execute([':u' => $uid]);
    $cid = (int) $pdo->lastInsertId();
    $venueRoom  = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'event' ORDER BY id LIMIT 1")->fetchColumn();
    $hostelRoom = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'hostel' ORDER BY id LIMIT 1")->fetchColumn();

    /* $method null = an online stay that chose no method (booking-submit.php) */
    $hostel = function (string $res, string $pay, int $refunds, string $checkIn, string $checkOut, ?string $method = null) use ($pdo, $cid, $hostelRoom) {
        $pdo->prepare(
            "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                                   room_price, total_amount, refunds_allowed)
             VALUES (:c, :r, 'hostel', :res, :pay, :m, 400, 400, :ra)"
        )->execute([':c' => $cid, ':r' => $hostelRoom, ':res' => $res, ':pay' => $pay, ':m' => $method, ':ra' => $refunds]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in)
                       VALUES (:b, :i, :o, 0)")->execute([':b' => $id, ':i' => $checkIn, ':o' => $checkOut]);
        $pdo->exec("UPDATE bookings SET current_deadline_at = fn_payment_deadline($id) WHERE id = $id");
        return $id;
    };
    $one = function (string $sql) use ($pdo) { return $pdo->query($sql)->fetchColumn(); };
    $state = function (int $id) use ($one) {
        return $one("SELECT CONCAT(reservation_status, '/', payment_status) FROM bookings WHERE id = $id");
    };
    $approve = function (int $id) use ($pdo) {
        $s = $pdo->prepare('CALL sp_approve_booking(:b, NULL)');
        $s->execute([':b' => $id]);
        $s->closeCursor();
    };

    /* 1. the snapshot */
    $h = $hostel('pending', 'locked', 0, $in5, $in7);
    ptu_check('1. a new hostel booking is pay to use', (int) $one("SELECT pay_to_use FROM bookings WHERE id = $h"), 1);
    $pdo->prepare("INSERT INTO bookings (customer_id, room_id, booking_type, payment_method, room_price, total_amount)
                   VALUES (:c, :r, 'venue', 'gcash', 1000, 1000)")->execute([':c' => $cid, ':r' => $venueRoom]);
    $v = (int) $pdo->lastInsertId();
    ptu_check('1. a venue booking is not', (int) $one("SELECT pay_to_use FROM bookings WHERE id = $v"), 0);
    ptu_check('1. the summary view names the policy', $one("SELECT payment_policy FROM v_booking_summary WHERE booking_id = $h"), 'pay_to_use');

    /* 2. due by the end of the check-in day, whatever the refund switch */
    ptu_check('2. post-pay snapshot: due end of check-in day', $one("SELECT current_deadline_at FROM bookings WHERE id = $h"), $in5 . ' 23:59:59');
    $hPre = $hostel('pending', 'locked', 1, $in5, $in7);
    ptu_check('2. pre-pay snapshot: due end of check-in day too (not the day before)', $one("SELECT current_deadline_at FROM bookings WHERE id = $hPre"), $in5 . ' 23:59:59');

    /* 3. approval opens payment at once */
    $approve($h);
    ptu_check('3. approved with refunds OFF: payment open now (not after the stay)', $state($h), 'approved/await_gcash');
    $approve($hPre);
    ptu_check('3. approved with refunds ON: no POS wait', $state($hPre), 'approved/await_gcash');
    $hCash = $hostel('pending', 'locked', 0, $in5, $in7, 'cash');
    $approve($hCash);
    ptu_check('3. a stay that chose cash waits for cash', $state($hCash), 'approved/await_cash');
    ptu_check('3. the timeline says pay before check-in', $one("SELECT note FROM booking_timeline WHERE booking_id = $h ORDER BY id DESC LIMIT 1"),
              'Approved; pay before check-in (GCash online, or cash at the front desk).');

    /* 4. a stay booked before migration 11 keeps its old route */
    $old = $hostel('pending', 'locked', 0, $in5, $in7, 'cash');
    $pdo->exec("UPDATE bookings SET pay_to_use = 0 WHERE id = $old");
    $pdo->exec("UPDATE bookings SET current_deadline_at = fn_payment_deadline($old) WHERE id = $old");
    $approve($old);
    ptu_check('4. an older post-pay stay still pays after the stay', $state($old), 'approved/await_event');

    /* 5. the end of the check-in day */
    $missed   = $hostel('approved', 'await_gcash',  0, $yesterday, $tomorrow);
    $paid     = $hostel('approved', 'paid_cash',    0, $yesterday, $tomorrow, 'cash');
    $review   = $hostel('approved', 'under_review', 0, $yesterday, $tomorrow);
    $tonight  = $hostel('approved', 'await_cash',   0, $today, $tomorrow, 'cash');
    $pdo->exec('CALL sp_expire_due_bookings()');
    ptu_check('5. unpaid when its check-in day ended -> released', $state($missed), 'released/expired');
    ptu_check('5. its beds are given back (no live nights)',
              (int) $one("SELECT COUNT(*) FROM bed_reservation_nights WHERE booking_id = $missed AND released_at IS NULL"), 0);
    ptu_check('5. a paid stay is never touched', $state($paid), 'approved/paid_cash');
    ptu_check('5. a receipt under review is never touched', $state($review), 'approved/under_review');
    ptu_check('5. checking in today: still open until the day ends', $state($tonight), 'approved/await_cash');
    ptu_check('5. no pay-to-use stay is ever "overdue"',
              (int) $one("SELECT COUNT(*) FROM bookings b JOIN customers c ON c.id = b.customer_id JOIN users u ON u.id = c.user_id
                           WHERE u.email = '" . PTU_EMAIL . "' AND b.pay_to_use = 1 AND b.payment_status = 'overdue'"), 0);

    /* 6. cash received at the front desk */
    $pdo->beginTransaction();
    $rid = payment_confirm($pdo, $h, $uid, 'Cash received at the front desk.', true);
    $pdo->commit();
    ptu_check('6. cash received: paid in cash', $one("SELECT CONCAT(payment_status, '/', payment_method) FROM bookings WHERE id = $h"), 'paid_cash/cash');
    ptu_check('6. one confirmed cash payment row',
              $one("SELECT CONCAT(COUNT(*), ':', MAX(payment_method), ':', MAX(payment_record_status)) FROM payments WHERE booking_id = $h"), '1:cash:confirmed');
    ptu_check('6. a System Receipt is issued', $rid !== null && (int) $one("SELECT COUNT(*) FROM system_receipts WHERE booking_id = $h") === 1, true);

    /* 7. what the pages read */
    $b = booking_by_reference(booking_reference($hPre, $one("SELECT submitted_at FROM bookings WHERE id = $hPre")));
    ptu_check('7. booking_shape: payToUse', $b['payToUse'], true);
    ptu_check('7. booking_shape: policy', $b['payment']['policy'], 'pay_to_use');
    ptu_check('7. booking_shape: pay by the check-in day', $b['payment']['payByLabel'], date('M j, Y', strtotime($in5)));
    ptu_check('7. booking_shape: no method chosen yet', $b['methodCode'], null);
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
