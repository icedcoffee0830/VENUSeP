<?php
/* =====================================================================
   BOOKING SUBMIT — the ONLY way a customer creates a booking.

   POST (multipart, because the ID comes with it)
     csrf, type=venue|hostel, room=<room_code>, affiliated=0|1,
     method=gcash|cash, agree_no_refund=0|1
     venue:  event_name, date_start, date_end, attendees, times=<json>
     hostel: check_in, check_out, occupants=<json [{name,gender}]>
     FILES:  id_document
   Replies with JSON: { ok, reference, bookingId }

   NOTHING THE BROWSER SENDS ABOUT MONEY OR AVAILABILITY IS TRUSTED.
   The page computes a price so the customer can see one, but this
   recomputes it from the room's own rate, and availability is decided by
   sp_add_venue_slot() / sp_assign_bed() — the same procedures staff and
   the seed go through. A page that could name its own price or claim a
   taken room would be the whole booking system's weak point.

   THE DISCOUNT IS NOT APPLIED HERE. `affiliated` is the customer's CLAIM;
   the discount is earned by a USeP ID that STAFF VERIFIED (DB-DECISIONS
   #3), so a new booking stores the full price with affiliation_verified
   = 0, and approval applies the rate. The booking page is explicit that
   the discount is provisional until then.

   THE POLICY IS SNAPSHOTTED. refunds_allowed copies the live switch at
   the moment of booking and never changes again, which is what decides
   both refundability and whether this booking pre-pays or post-pays
   (DB-DECISIONS #18).
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/bookings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function bs_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    bs_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
if (!customer_logged_in()) {
    bs_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    bs_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$customerId = (int) $_SESSION['customer_id'];
$type       = isset($_POST['type']) ? $_POST['type'] : '';
$roomCode   = isset($_POST['room']) ? (string) $_POST['room'] : '';
$affiliated = !empty($_POST['affiliated']) ? 1 : 0;
$method     = isset($_POST['method']) && $_POST['method'] === 'cash' ? 'cash' : 'gcash';

if (!in_array($type, ['venue', 'hostel'], true)) {
    bs_reply(400, ['ok' => false, 'message' => 'Invalid request.']);
}
if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode)) {
    bs_reply(400, ['ok' => false, 'message' => 'Invalid room.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    bs_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your booking was not submitted.']);
}

/* ---- the room, and the rate THIS server believes in ---- */
$roomStmt = $pdo->prepare(
    "SELECT r.id, r.room_type, r.is_active,
            erd.attendee_capacity, erd.fee_per_day,
            hrd.rate_per_head_per_night
       FROM rooms r
       LEFT JOIN event_room_details erd  ON erd.room_id = r.id
       LEFT JOIN hostel_room_details hrd ON hrd.room_id = r.id
      WHERE r.room_code = :c"
);
$roomStmt->execute([':c' => $roomCode]);
$room = $roomStmt->fetch();
if (!$room || !$room['is_active']) {
    bs_reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
}
$wantType = $type === 'venue' ? 'event' : 'hostel';
if ($room['room_type'] !== $wantType) {
    bs_reply(409, ['ok' => false, 'message' => 'That room does not take this kind of booking.']);
}

/* The ID is required for EVERY booking (PROJECT-HANDOFF 4.7) — checked here
   and not merely in the page, because the page's check is a courtesy. */
if (!isset($_FILES['id_document']) || $_FILES['id_document']['error'] !== UPLOAD_ERR_OK) {
    bs_reply(400, ['ok' => false, 'message' => 'A valid ID is required to submit a booking.']);
}

/* The policy snapshot — read once, stored on the booking, never re-read. */
$refundsAllowed = refunds_enabled() ? 1 : 0;

/* ---------------------------------------------------------------------
   DEMO MODE — everything above still ran: the room is real, the ID was
   required, the policy was read. What changes is that the booking is
   recorded in the SESSION instead of the database, so the flow behaves
   normally and the showcase leaves nothing behind.

   Placed AFTER validation on purpose. A demo that skipped the checks
   would prove the checks work when they might not — the point is to
   rehearse the real thing, minus the write.
   --------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/demo-mode.php';
if (demo_mode_on()) {
    if ($type === 'venue') {
        $startIso  = (string) ($_POST['date_start'] ?? '');
        $endIso    = (string) ($_POST['date_end'] ?? '') ?: $startIso;
        $eventName = trim((string) ($_POST['event_name'] ?? ''));
        $days      = max(1, (int) round((strtotime($endIso) - strtotime($startIso)) / 86400) + 1);
        $price     = (float) $room['fee_per_day'] * $days;
        $first = $startIso; $last = $endIso;
    } else {
        $checkIn  = (string) ($_POST['check_in'] ?? '');
        $checkOut = (string) ($_POST['check_out'] ?? '');
        $occ      = json_decode((string) ($_POST['occupants'] ?? '[]'), true);
        $beds     = is_array($occ) ? count($occ) : 1;
        $nights   = max(1, (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400));
        $price    = (float) $room['rate_per_head_per_night'] * $beds * $nights;
        $eventName = $beds . ' bed' . ($beds == 1 ? '' : 's') . ' · ' . $nights . ' night' . ($nights == 1 ? '' : 's');
        $first = $checkIn; $last = $checkOut;
    }
    /* Priced by the SAME rule the real path uses, from the room's own rate —
       a demo that showed a made-up total would be demonstrating nothing. */
    $policy = payment_policy_for($refundsAllowed, $first, $last);
    $row = demo_add_booking([
        'type' => $type, 'roomCode' => $roomCode, 'roomName' => $room['name'] ?? $roomCode,
        'venue' => $room['name'] ?? $roomCode, 'venueName' => $room['venue_name'] ?? '',
        'customerId' => $customerId, 'customerName' => (string) ($_SESSION['customer_name'] ?? ''),
        'eventName' => $eventName,
        'eventDate' => $first ? date('F j, Y', strtotime($first)) : '—',
        'eventDateIso' => $first, 'endDateIso' => $last,
        'bookingDate' => date('F j, Y'), 'bookingDateIso' => date('Y-m-d'),
        'roomPrice' => $price, 'discountPercent' => 0.0, 'discountAmount' => 0.0,
        'amountValue' => $price, 'amount' => '₱' . number_format($price),
        'reservationCode' => 'pending', 'paymentCode' => 'locked',
        'bookingStatus' => 'Pending', 'paymentStatus' => 'Locked',
        'bookingStatusStaff' => 'Reservation pending review', 'paymentStatusStaff' => 'Payment locked',
        'method' => $method === 'cash' ? 'Cash' : 'GCash',
        'refundsAllowed' => (bool) $refundsAllowed, 'refundable' => false,
        'refundStatus' => null, 'receiptVerdict' => null, 'payment' => $policy,
        'beds' => $type === 'hostel' ? ($beds ?? 0) : 0,
        'nights' => $type === 'hostel' ? ($nights ?? 0) : 0,
        'days' => $type === 'venue' ? ($days ?? 1) : 0,
    ]);
    bs_reply(200, [
        'ok' => true, 'demo' => true,
        'bookingId' => $row['id'], 'reference' => $row['bookingId'], 'idStored' => false,
        'message' => 'Booking submitted (demo mode — nothing was saved).',
    ]);
}

/* While refunds are OFF the customer must acknowledge the booking is final;
   the policy is agreed at booking time, not at payment time. */
if (!$refundsAllowed && empty($_POST['agree_no_refund'])) {
    bs_reply(400, ['ok' => false, 'message' => 'Please confirm you understand this booking is non-refundable.']);
}

$LEAD_HOURS = 12;   // mirrors LEAD_MS in the booking pages

/* ============================ VENUE ============================ */
if ($type === 'venue') {
    $eventName = trim((string) ($_POST['event_name'] ?? ''));
    $startIso  = (string) ($_POST['date_start'] ?? '');
    $endIso    = (string) ($_POST['date_end'] ?? '') ?: $startIso;
    $attendees = (int) ($_POST['attendees'] ?? 0);
    $times     = json_decode((string) ($_POST['times'] ?? '[]'), true);
    if (!is_array($times)) { $times = []; }

    if ($eventName === '' || mb_strlen($eventName) > 190) {
        bs_reply(400, ['ok' => false, 'message' => 'Enter an event name.']);
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endIso) || $endIso < $startIso) {
        bs_reply(400, ['ok' => false, 'message' => 'Pick valid reservation dates.']);
    }
    if ($attendees <= 0 || $attendees > (int) $room['attendee_capacity']) {
        bs_reply(400, ['ok' => false, 'message' => 'Enter an attendee count within the room capacity.']);
    }

    /* Build the per-day slots this booking is asking for. Days under a HARD
       closure are skipped — a venue booking "books around" a blocked day —
       and fn_room_hard_blocked() decides that, so this agrees with
       sp_add_venue_slot() rather than guessing alongside it. */
    $blockStmt = $pdo->prepare('SELECT fn_room_hard_blocked(:r, :d) AS blocked');
    $slots = [];
    for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
        $blockStmt->execute([':r' => $room['id'], ':d' => $d]);
        if ((int) $blockStmt->fetchColumn() === 1) {
            continue;
        }
        $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
        $s = isset($t['start']) ? (string) $t['start'] : '';
        $e = isset($t['end'])   ? (string) $t['end']   : '';
        if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e) || $e <= $s) {
            bs_reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
        }
        $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
    }
    if (!$slots) {
        bs_reply(409, ['ok' => false, 'message' => 'Every day you picked is closed for maintenance.']);
    }
    /* Lead time, measured from the FIRST day actually held. */
    if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
        bs_reply(409, ['ok' => false, 'message' => 'Bookings must start at least ' . $LEAD_HOURS . ' hours from now.']);
    }

    /* Priced per DAY, from the room's own rate — days are INCLUSIVE, and only
       days actually held are charged. */
    $price = (float) $room['fee_per_day'] * count($slots);

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO bookings
                (customer_id, room_id, booking_type, reservation_status, payment_status,
                 payment_method, is_usep_affiliated, affiliation_verified,
                 room_price, discount_percent, discount_amount, total_amount, refunds_allowed)
             VALUES (:c, :r, 'venue', 'pending', 'locked', :m, :a, 0, :p, 0, 0, :p2, :ra)"
        );
        $ins->execute([
            ':c' => $customerId, ':r' => $room['id'], ':m' => $method,
            ':a' => $affiliated, ':p' => $price, ':p2' => $price, ':ra' => $refundsAllowed,
        ]);
        $bookingId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
             VALUES (:b, :n, :s, :e, :a)'
        )->execute([':b' => $bookingId, ':n' => $eventName, ':s' => $startIso, ':e' => $endIso, ':a' => $attendees]);

        /* sp_add_venue_slot takes a room+date lock, re-checks maintenance AND
           overlapping live holds, and SIGNALs if the slot is gone. That is the
           real concurrency guard: two customers submitting the same slot at the
           same moment cannot both win. */
        $slotCall = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
        foreach ($slots as $s) {
            $slotCall->execute([':b' => $bookingId, ':d' => $s['date'], ':s' => $s['start'], ':e' => $s['end']]);
            $slotCall->closeCursor();
        }

        $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')
            ->execute([':b' => $bookingId, ':b2' => $bookingId]);
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        /* 45000 is what the procedures raise for a real conflict — a taken slot
           or a closed day. Anything else is ours to own, not the customer's:
           they get a plain apology, and the detail goes to the SERVER LOG.
           Without this an unexpected failure is invisible — the customer sees
           "something went wrong" and nobody can ever find out what. */
        if ($e->getCode() !== '45000') {
            error_log('VENUSeP booking-submit (venue): ' . $e->getMessage());
        }
        $msg = $e->getCode() === '45000'
            ? 'That slot was taken while you were booking. Please pick another time.'
            : 'Something went wrong, so your booking was not submitted.';
        bs_reply($e->getCode() === '45000' ? 409 : 500, ['ok' => false, 'message' => $msg]);
    }

/* ============================ HOSTEL ============================ */
} else {
    $checkIn  = (string) ($_POST['check_in'] ?? '');
    $checkOut = (string) ($_POST['check_out'] ?? '');
    $occupants = json_decode((string) ($_POST['occupants'] ?? '[]'), true);

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut) || $checkOut <= $checkIn) {
        bs_reply(400, ['ok' => false, 'message' => 'Check-out must be after check-in.']);
    }
    if (!is_array($occupants) || !$occupants) {
        bs_reply(400, ['ok' => false, 'message' => 'Name at least one guest.']);
    }
    $clean = [];
    foreach ($occupants as $o) {
        $n = trim((string) ($o['name'] ?? ''));
        $g = (string) ($o['gender'] ?? '');
        if ($n === '') {
            bs_reply(400, ['ok' => false, 'message' => 'Every bed needs a named guest.']);
        }
        $clean[] = ['name' => mb_substr($n, 0, 190), 'gender' => in_array($g, ['male', 'female', 'other'], true) ? $g : 'other'];
    }
    /* Nights are EXCLUSIVE of check-out: Aug 1 -> Aug 4 is 3 nights. */
    $nights = (int) round((strtotime($checkOut) - strtotime($checkIn)) / 86400);
    if (strtotime($checkIn . ' 14:00') - time() < $LEAD_HOURS * 3600) {
        bs_reply(409, ['ok' => false, 'message' => 'Bookings must start at least ' . $LEAD_HOURS . ' hours from now.']);
    }

    /* Priced per HEAD per NIGHT: beds x rate x nights. */
    $price = (float) $room['rate_per_head_per_night'] * count($clean) * $nights;

    try {
        $pdo->beginTransaction();
        $ins = $pdo->prepare(
            "INSERT INTO bookings
                (customer_id, room_id, booking_type, reservation_status, payment_status,
                 payment_method, is_usep_affiliated, affiliation_verified,
                 room_price, discount_percent, discount_amount, total_amount, refunds_allowed)
             VALUES (:c, :r, 'hostel', 'pending', 'locked', :m, :a, 0, :p, 0, 0, :p2, :ra)"
        );
        $ins->execute([
            ':c' => $customerId, ':r' => $room['id'], ':m' => $method,
            ':a' => $affiliated, ':p' => $price, ':p2' => $price, ':ra' => $refundsAllowed,
        ]);
        $bookingId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in)
             VALUES (:b, :i, :o, 0)'
        )->execute([':b' => $bookingId, ':i' => $checkIn, ':o' => $checkOut]);

        $occIns = $pdo->prepare(
            'INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest)
             VALUES (:b, :n, :g, :p)'
        );
        $occIds = [];
        foreach ($clean as $i => $o) {
            $occIns->execute([':b' => $bookingId, ':n' => $o['name'], ':g' => $o['gender'], ':p' => $i === 0 ? 1 : 0]);
            $occIds[] = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE bookings SET current_deadline_at = fn_payment_deadline(:b) WHERE id = :b2')
            ->execute([':b' => $bookingId, ':b2' => $bookingId]);
        /* Committed BEFORE the beds are assigned, because sp_assign_bed opens
           its own transaction and calling it inside ours would commit ours out
           from under us. A booking with no beds yet holds nothing — occupancy
           is counted from bed_reservation_nights — so the gap is visible to
           nobody, and the catch below removes the booking if a bed is lost. */
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        bs_reply(500, ['ok' => false, 'message' => 'Something went wrong, so your booking was not submitted.']);
    }

    /* One exact bed per guest, for every night of the stay. The bed must be
       free on EVERY night — a guest cannot move rooms mid-stay — and the
       UNIQUE(active_bed_id, night_date) index is the last-bed race guard. */
    try {
        $free = $pdo->prepare(
            'SELECT hb.id FROM hostel_beds hb
              WHERE hb.room_id = :r AND hb.is_active = 1
                AND NOT EXISTS (SELECT 1 FROM bed_reservation_nights n
                                 WHERE n.bed_id = hb.id AND n.released_at IS NULL
                                   AND n.night_date >= :i AND n.night_date < :o)
              ORDER BY hb.display_order, hb.id LIMIT 1'
        );
        $assign = $pdo->prepare('CALL sp_assign_bed(:b, :o, :bed)');
        foreach ($occIds as $occId) {
            $free->execute([':r' => $room['id'], ':i' => $checkIn, ':o' => $checkOut]);
            $bedId = $free->fetchColumn();
            if ($bedId === false) {
                throw new RuntimeException('no_bed');
            }
            $assign->execute([':b' => $bookingId, ':o' => $occId, ':bed' => (int) $bedId]);
            $assign->closeCursor();
        }
    } catch (Throwable $e) {
        /* Not enough beds for the whole stay, or a hard closure inside it.
           Remove the booking rather than leave a half-held one behind. */
        $pdo->prepare('DELETE FROM bookings WHERE id = :b')->execute([':b' => $bookingId]);
        bs_reply(409, ['ok' => false,
            'message' => 'There are not enough free beds for every night of that stay. Please pick different dates or fewer beds.']);
    }
}

/* ---- the ID, stored outside the web root (includes/documents.php) ---- */
$docError = '';
doc_store($bookingId, 'customer_id', $_FILES['id_document']['tmp_name'],
          $_FILES['id_document']['name'], (int) $_SESSION['user_id'], $docError);
/* A booking whose ID failed to store is NOT rejected: the reservation is real
   and the slot is held. It surfaces to staff as a booking with no ID document,
   which is a thing they can chase — throwing away a valid booking over a failed
   file write would be worse. */

$submitted = $pdo->prepare('SELECT submitted_at FROM bookings WHERE id = :b');
$submitted->execute([':b' => $bookingId]);

bs_reply(200, [
    'ok'        => true,
    'bookingId' => $bookingId,
    'reference' => booking_reference($bookingId, $submitted->fetchColumn()),
    'idStored'  => $docError === '',
    'message'   => 'Booking submitted.',
]);
