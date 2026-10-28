<?php
/* =====================================================================
   CLI test for the deadline rules of migration 07 (DB-DECISIONS #18,
   2026-10-27), against the real local `venusep` database, with a
   throwaway customer + bookings removed afterwards, even on failure.
     - a request nobody approved expires when its booking begins: a venue
       at its first slot's start, a hostel stay at the END of check-in day,
       under BOTH refund policies
     - a pre-pay request is no longer expired a day early by its payment
       deadline
     - an approved pre-pay booking still WAITING for payment is released
       at its deadline; a PAID or under-review one never is
     - booking_shape() explains why ("Expired" + a reason)
   Calls sp_expire_due_bookings() directly, so it also expires any REAL
   booking that is due — which the next page load would do anyway.
   Run:  php tests/request_expiry_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/bookings.php';

const REX_EMAIL = 'request-expiry-selftest@example.invalid';

$failures = 0;
function rex_check(string $name, $got, $want): void
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
$fn = $pdo->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()
                    AND ROUTINE_NAME = 'fn_request_expiry'")->fetchColumn();
if ((int) $fn === 0) {
    fwrite(STDERR, "fn_request_expiry is missing: run venusep_migration_07.sql first.\n");
    exit(1);
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id
                          JOIN users u ON u.id = c.user_id WHERE u.email = '" . REX_EMAIL . "'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
        $pdo->exec('DELETE FROM payments WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM bookings WHERE id = ' . (int) $bid);   // details, slots, timeline cascade
    }
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = '" . REX_EMAIL . "'");
    $pdo->exec("DELETE FROM users WHERE email = '" . REX_EMAIL . "'");
};
$cleanup();

$today     = date('Y-m-d');
$yesterday = date('Y-m-d', strtotime('-1 day'));
$tomorrow  = date('Y-m-d', strtotime('+1 day'));

try {
    $pdo->prepare("INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, 'rex_selftest', 'x', 'customer')")
        ->execute([':e' => REX_EMAIL]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (user_id, full_name) VALUES (:u, 'Request Expiry Selftest')")->execute([':u' => $uid]);
    $cid = (int) $pdo->lastInsertId();
    $venueRoom  = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'event' ORDER BY id LIMIT 1")->fetchColumn();
    $hostelRoom = (int) $pdo->query("SELECT id FROM rooms WHERE room_type = 'hostel' ORDER BY id LIMIT 1")->fetchColumn();

    /* A venue booking held as ONE slot, inserted directly (the procedure that
       normally adds slots refuses past times — exactly what these need). */
    $venue = function (string $res, string $pay, int $prepay, string $day, string $start, ?string $approvedAt = null) use ($pdo, $cid, $venueRoom) {
        $pdo->prepare(
            "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                                   room_price, total_amount, refunds_allowed, approved_at)
             VALUES (:c, :r, 'venue', :res, :pay, 'gcash', 1000, 1000, :ra, :ap)"
        )->execute([':c' => $cid, ':r' => $venueRoom, ':res' => $res, ':pay' => $pay, ':ra' => $prepay, ':ap' => $approvedAt]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
                       VALUES (:b, 'Expiry selftest', :d, :d2, 10)")->execute([':b' => $id, ':d' => $day, ':d2' => $day]);
        $pdo->prepare("INSERT INTO venue_booking_slots (booking_id, room_id, slot_date, start_time, end_time)
                       VALUES (:b, :r, :d, :s, ADDTIME(:s2, '00:01:00'))")
            ->execute([':b' => $id, ':r' => $venueRoom, ':d' => $day, ':s' => $start, ':s2' => $start]);
        $pdo->exec("UPDATE bookings SET current_deadline_at = fn_payment_deadline($id) WHERE id = $id");
        return $id;
    };
    $hostel = function (string $res, string $pay, int $prepay, string $checkIn, string $checkOut) use ($pdo, $cid, $hostelRoom) {
        $pdo->prepare(
            "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                                   room_price, total_amount, refunds_allowed)
             VALUES (:c, :r, 'hostel', :res, :pay, 'cash', 400, 400, :ra)"
        )->execute([':c' => $cid, ':r' => $hostelRoom, ':res' => $res, ':pay' => $pay, ':ra' => $prepay]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in)
                       VALUES (:b, :i, :o, 0)")->execute([':b' => $id, ':i' => $checkIn, ':o' => $checkOut]);
        $pdo->exec("UPDATE bookings SET current_deadline_at = fn_payment_deadline($id) WHERE id = $id");
        return $id;
    };
    $state = function (int $id) use ($pdo) {
        return $pdo->query("SELECT CONCAT(reservation_status, '/', payment_status) FROM bookings WHERE id = $id")->fetchColumn();
    };

    $vPastPost   = $venue('pending', 'locked', 0, $yesterday, '10:00:00');
    $vPastPre    = $venue('pending', 'locked', 1, $yesterday, '10:00:00');
    $vLaterToday = $venue('pending', 'locked', 1, $today, '23:58:00');      // day-before deadline already passed
    $vTomorrow   = $venue('pending', 'locked', 0, $tomorrow, '09:00:00');
    $hToday      = $hostel('pending', 'locked', 1, $today, $tomorrow);
    $hYesterday  = $hostel('pending', 'locked', 0, $yesterday, $today);
    $paidPre     = $venue('approved', 'confirmed',    1, $today, '23:58:00', date('Y-m-d H:i:s', strtotime('-3 days')));
    $reviewPre   = $venue('approved', 'under_review', 1, $today, '23:58:00', date('Y-m-d H:i:s', strtotime('-3 days')));
    $unpaidPre   = $venue('approved', 'await_gcash',  1, $yesterday, '10:00:00', date('Y-m-d H:i:s', strtotime('-3 days')));
    /* the paid/under-review ones need a deadline that has PASSED, like a real
       pre-pay booking on its event day */
    $pdo->exec("UPDATE bookings SET current_deadline_at = NOW() - INTERVAL 1 HOUR WHERE id IN ($paidPre, $reviewPre, $unpaidPre)");

    $pdo->exec('CALL sp_expire_due_bookings()');

    rex_check('1. post-pay request, start passed -> expired', $state($vPastPost), 'released/expired');
    rex_check('1. pre-pay request, start passed -> expired', $state($vPastPre), 'released/expired');
    rex_check('2. pre-pay request starting later today stays pending (no day-early expiry)', $state($vLaterToday), 'pending/locked');
    rex_check('2. request for tomorrow stays pending', $state($vTomorrow), 'pending/locked');
    rex_check('3. hostel request checking in today stays pending until the day ends', $state($hToday), 'pending/locked');
    rex_check('3. hostel request whose check-in day is over -> expired', $state($hYesterday), 'released/expired');
    rex_check('4. PAID pre-pay booking past its deadline is NOT released', $state($paidPre), 'approved/confirmed');
    rex_check('4. under-review pre-pay booking past its deadline is NOT released', $state($reviewPre), 'approved/under_review');
    rex_check('4. unpaid pre-pay booking past its deadline is released', $state($unpaidPre), 'released/expired');

    $note = $pdo->query("SELECT note FROM booking_timeline WHERE booking_id = $vPastPost ORDER BY id DESC LIMIT 1")->fetchColumn();
    rex_check('5. the timeline says why', $note, 'Not approved before the booking date; the request expired.');
    $slots = (int) $pdo->query("SELECT COUNT(*) FROM venue_booking_slots WHERE booking_id = $vPastPost AND released_at IS NULL")->fetchColumn();
    rex_check('5. its slot is released', $slots, 0);

    $b = booking_by_reference(booking_reference($vPastPost, $pdo->query("SELECT submitted_at FROM bookings WHERE id = $vPastPost")->fetchColumn()));
    rex_check('6. customers read "Expired"', $b['bookingStatus'], 'Expired');
    rex_check('6. with the reason', $b['expiryNote'], 'Not approved before your event date, so the request expired. Nothing was charged.');
    rex_check('6. staff queue line', booking_queue_action($b), 'Never approved · request expired');
    $u = booking_by_reference(booking_reference($unpaidPre, $pdo->query("SELECT submitted_at FROM bookings WHERE id = $unpaidPre")->fetchColumn()));
    rex_check('6. an unpaid hold keeps its own reason', $u['expiryNote'], 'Not paid by the deadline, so the date was released.');
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
