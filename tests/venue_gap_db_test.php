<?php
/* =====================================================================
   CLI test for migration 09 (DB-DECISIONS #8, 2026-10-27): a venue room
   takes several bookings on ONE date, as long as each keeps the cleanup
   gap (system_settings.venue_cleanup_gap_minutes, 60) from the others.
   Runs sp_add_venue_slot() for real against the local `venusep`
   database, on a far-future date no real booking uses, with a throwaway
   customer + bookings removed afterwards, even on failure.
   Run:  php tests/venue_gap_db_test.php
   Prints one "ok <name>" / "FAIL <name>: got ..., want ..." line per
   check, then "ALL PASS" (exit 0) or "N FAILED" (exit 1).
   ===================================================================== */

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/bookings.php';

const VGT_EMAIL = 'venue-gap-selftest@example.invalid';

$failures = 0;
function vgt_check(string $name, $got, $want): void
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
$gapRow = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes'")->fetchColumn();
if ($gapRow === false) {
    fwrite(STDERR, "venue_cleanup_gap_minutes is missing: run venusep_migration_09.sql first.\n");
    exit(1);
}

$cleanup = function () use ($pdo) {
    $ids = $pdo->query("SELECT b.id FROM bookings b JOIN customers c ON c.id = b.customer_id
                          JOIN users u ON u.id = c.user_id WHERE u.email = '" . VGT_EMAIL . "'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $bid) {
        $pdo->exec('DELETE FROM payments WHERE booking_id = ' . (int) $bid);
        $pdo->exec('DELETE FROM bookings WHERE id = ' . (int) $bid);   // details, slots, timeline cascade
    }
    $pdo->exec("DELETE c FROM customers c JOIN users u ON u.id = c.user_id WHERE u.email = '" . VGT_EMAIL . "'");
    $pdo->exec("DELETE FROM users WHERE email = '" . VGT_EMAIL . "'");
};
$cleanup();

try {
    $pdo->prepare("INSERT INTO users (email, username, password_hash, account_type) VALUES (:e, 'vgt_selftest', 'x', 'customer')")
        ->execute([':e' => VGT_EMAIL]);
    $uid = (int) $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (user_id, full_name) VALUES (:u, 'Venue Gap Selftest')")->execute([':u' => $uid]);
    $cid = (int) $pdo->lastInsertId();
    $rooms = $pdo->query("SELECT id FROM rooms WHERE room_type = 'event' AND is_active = 1 ORDER BY id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
    [$roomA, $roomB] = array_map('intval', $rooms);
    /* a date nobody has booked: 400 days out, moved on until it is clear */
    $day = date('Y-m-d', strtotime('+400 days'));
    $busy = $pdo->prepare('SELECT COUNT(*) FROM venue_booking_slots WHERE slot_date = :d AND room_id IN (:a, :b)');
    for ($i = 0; $i < 30; $i++) {
        $busy->execute([':d' => $day, ':a' => $roomA, ':b' => $roomB]);
        if ((int) $busy->fetchColumn() === 0) { break; }
        $day = date('Y-m-d', strtotime($day . ' +1 day'));
    }

    /* A booking holding nothing yet; then try to add ONE slot through the
       real procedure. Returns true (held) or the refusal text. */
    $try = function (int $room, string $start, string $end, string $res = 'pending', string $pay = 'locked') use ($pdo, $cid, $day) {
        $pdo->prepare(
            "INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status, payment_method,
                                   room_price, total_amount, refunds_allowed)
             VALUES (:c, :r, 'venue', :res, :pay, 'gcash', 1000, 1000, 1)"
        )->execute([':c' => $cid, ':r' => $room, ':res' => $res, ':pay' => $pay]);
        $id = (int) $pdo->lastInsertId();
        $pdo->prepare("INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
                       VALUES (:b, 'Gap selftest', :d, :d2, 10)")->execute([':b' => $id, ':d' => $day, ':d2' => $day]);
        try {
            $st = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
            $st->execute([':b' => $id, ':d' => $day, ':s' => $start, ':e' => $end]);
            $st->closeCursor();
            return $id;
        } catch (PDOException $e) {
            $pdo->exec("DELETE FROM bookings WHERE id = $id");
            return $e->getCode() === '45000' ? 'refused' : 'error: ' . $e->getMessage();
        }
    };

    $first = $try($roomA, '07:00:00', '09:00:00');
    vgt_check('1. 7:00-9:00 is held', is_int($first), true);
    vgt_check('2. 9:30-10:30 is refused (inside the 1-hour gap after)', $try($roomA, '09:30:00', '10:30:00'), 'refused');
    vgt_check('2. 9:59-11:00 is refused', $try($roomA, '09:59:00', '11:00:00'), 'refused');
    vgt_check('2. 6:30-6:45 is refused (inside the gap before)', $try($roomA, '06:30:00', '06:45:00'), 'refused');
    vgt_check('2. 8:00-8:30 is refused (overlaps)', $try($roomA, '08:00:00', '08:30:00'), 'refused');
    vgt_check('3. 10:00-12:00 is held: exactly the gap after', is_int($try($roomA, '10:00:00', '12:00:00')), true);
    vgt_check('3. 5:00-6:00 is held: exactly the gap before', is_int($try($roomA, '05:00:00', '06:00:00')), true);
    vgt_check('4. another room at 7:00-9:00 is fine', is_int($try($roomB, '07:00:00', '09:00:00')), true);

    /* a released hold frees its time */
    $pdo->exec("UPDATE bookings SET reservation_status = 'released', payment_status = 'expired' WHERE id = $first");
    vgt_check('5. once released, 7:00-9:00 can be booked again', is_int($try($roomA, '07:00:00', '09:00:00')), true);

    /* a PAID pre-pay booking whose day-before deadline has passed still holds its time */
    $paid = $try($roomB, '13:00:00', '15:00:00', 'approved', 'confirmed');
    $pdo->exec("UPDATE bookings SET current_deadline_at = NOW() - INTERVAL 1 HOUR WHERE id = $paid");
    vgt_check('6. a paid booking past its pay-by deadline still blocks its time', $try($roomB, '13:30:00', '14:00:00'), 'refused');

    /* the message a customer reads */
    try {
        throw new PDOException('SQLSTATE[45000]: <<Unknown error>>: 1644 That time is too close to another booking: bookings need a cleanup gap before and after them.');
    } catch (PDOException $e) {
        vgt_check('7. the customer is told about the gap', venue_slot_refusal_message($e),
            'That time is too close to another booking on the same day. Bookings need 1 hour free before and after them for cleanup. Please pick another time.');
    }
} finally {
    $cleanup();
}

echo $failures === 0 ? "ALL PASS\n" : "{$failures} FAILED\n";
exit($failures === 0 ? 0 : 1);
