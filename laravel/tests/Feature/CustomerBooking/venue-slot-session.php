<?php
/* The second booking session for VenueSlotRaceTest. It runs in its OWN process
   so it can wait on a lock the test's session holds. It books one venue slot
   the way BookingSubmitController does (BEGIN; INSERT bookings; CALL
   sp_add_venue_slot; COMMIT) and prints the outcome as JSON.
   Argument: base64 JSON {dsn, user, pass, customer, room, day, start, end}. */
$a = json_decode(base64_decode($argv[1] ?? ''), true);
$pdo = new PDO($a['dsn'], $a['user'], $a['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout = 20');
$id = null;
try {
    $pdo->beginTransaction();
    $pdo->prepare("INSERT INTO bookings (customer_id, room_id, booking_type, reservation_status, payment_status,
                     payment_method, is_usep_affiliated, affiliation_verified, room_price, discount_percent,
                     discount_amount, total_amount, refunds_allowed)
                   VALUES (?, ?, 'venue', 'pending', 'locked', 'gcash', 0, 0, 100, 0, 0, 100, 0)")
        ->execute([$a['customer'], $a['room']]);
    $id = (int) $pdo->lastInsertId();
    $call = $pdo->prepare('CALL sp_add_venue_slot(?, ?, ?, ?)');
    $call->execute([$id, $a['day'], $a['start'], $a['end']]);
    $call->closeCursor();
    $pdo->commit();
    echo json_encode(['ok' => true, 'booking' => $id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['ok' => false, 'code' => $e->getCode(), 'message' => $e->getMessage()]);
}
