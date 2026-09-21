<?php
/* =====================================================================
   COUNTER BOOKING — the ONLY way staff create a booking for a walk-in.

   POST (from the booking page in counter mode, includes/auth.php)
     csrf, type=venue, room=<room_code>, method=gcash|cash,
     event_name, date_start, date_end, attendees, times=<json>,
     agree_no_refund=0|1,
     booker_mode=account|walkin,
       account: customer_id
       walkin : walkin_name, walkin_phone[, walkin_address]
     id_checked=1, usep_id=0|1
   Replies with JSON: { ok, reference, bookingId, payBy }

   HOW THIS DIFFERS FROM customer/booking-submit.php, and why each one:

     NO ID FILE. The staff member looked at the ID. What is stored is
     that judgement, with their name on it, in booking_timeline — not a
     photograph of someone's identity document that nobody asked to
     keep.

     THE DISCOUNT IS GRANTED HERE, not deferred. Online, affiliation is
     a CLAIM and the discount lands at approval when staff see the
     uploaded ID (DB-DECISIONS #3). At the counter that inspection has
     already happened, so affiliation_verified is 1 and the rate applies
     immediately.

     IT IS APPROVED IMMEDIATELY, through sp_approve_booking — the same
     procedure the queue calls. Staff ARE the approver and the customer
     is standing there; making them approve their own booking afterwards
     would be theatre. Going through the procedure rather than writing
     the statuses here keeps ONE definition of "approved" for both
     booking types and both payment policies.

     A WALK-IN WITH NO ACCOUNT CAN ONLY PAY AT THE COUNTER. Not a rule
     imposed for neatness: with no login there is no way to upload a
     receipt, no recorded GCash number to refund to, and no email
     address to send anything to. The method is forced to 'cash', which
     in this system means "staff recorded a payment they witnessed" —
     including a GCash transfer handed over at the desk.

   WHAT IS NOT TRUSTED FROM THE PAGE: the price (recomputed from the
   room's rate), the slot (decided by sp_add_venue_slot), the customer
   (re-read from the database), and the identity proof — the page can
   claim a customer_id, but only a password checked in THIS staff
   session, by admin/customer-verify.php, lets it be used.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/refund-policy.php';
/* pricing.php ends with a <script> block for the booking pages; this endpoint
   answers in JSON, so its output is swallowed and only the functions kept —
   venusep_discount_percent(), the ONE rate. Same treatment booking-action.php
   and includes/faqs.php give it. */
ob_start();
require_once __DIR__ . '/../includes/pricing.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const COUNTER_PROOF_TTL = 900;      // must match admin/customer-verify.php

function bc_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    bc_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
$actorType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($actorType, ['admin', 'staff'], true)) {
    bc_reply(403, ['ok' => false, 'message' => 'Only staff can take a counter booking.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    bc_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}
$actor = (int) $_SESSION['user_id'];

/* The ID check is the whole basis for dropping the upload. If staff did not
   tick it, there is no identity evidence at all and this is not a booking we
   are willing to create. */
if (empty($_POST['id_checked'])) {
    bc_reply(400, ['ok' => false, 'message' => 'Confirm you have seen a valid ID before creating the booking.']);
}

$type     = (string) ($_POST['type'] ?? '');
$roomCode = (string) ($_POST['room'] ?? '');
$usepId   = !empty($_POST['usep_id']) ? 1 : 0;
$method   = (isset($_POST['method']) && $_POST['method'] === 'cash') ? 'cash' : 'gcash';

if ($type !== 'venue') {
    /* Hostel counter bookings are the next piece of work: they need the
       occupant roster, bed assignment and the POS chain. Refusing clearly beats
       half-creating one. */
    bc_reply(400, ['ok' => false, 'message' => 'Counter bookings currently cover venues only.']);
}
if (!preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode)) {
    bc_reply(400, ['ok' => false, 'message' => 'Invalid room.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    bc_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was created.']);
}

/* ---- who the booking is for ---- */
$bookerMode = ($_POST['booker_mode'] ?? '') === 'walkin' ? 'walkin' : 'account';
$customerId = 0;
$bookerName = '';
$isNewWalkIn = false;

if ($bookerMode === 'account') {
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    if ($customerId <= 0) {
        bc_reply(400, ['ok' => false, 'message' => 'Pick the customer&rsquo;s account.']);
    }
    /* The identity proof, re-checked server-side. The page cannot assert this:
       only admin/customer-verify.php writes it, only into THIS staff session,
       and only after the customer typed their own password. */
    $proof = isset($_SESSION['counter_proof'][$customerId]) ? (int) $_SESSION['counter_proof'][$customerId] : 0;
    if ($proof <= 0 || (time() - $proof) > COUNTER_PROOF_TTL) {
        bc_reply(403, ['ok' => false, 'message' => 'Ask the customer to confirm their password again before booking.']);
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT c.id, c.full_name FROM customers c JOIN users u ON u.id = c.user_id
              WHERE c.id = :c AND u.is_active = 1 LIMIT 1'
        );
        $stmt->execute([':c' => $customerId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('VENUSeP booking-create (customer): ' . $e->getMessage());
        bc_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
    }
    if (!$row) {
        bc_reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
    }
    $bookerName = (string) $row['full_name'];
} else {
    $bookerName = trim((string) ($_POST['walkin_name'] ?? ''));
    $phone      = cb_normalise_mobile($_POST['walkin_phone'] ?? '');
    $address    = trim((string) ($_POST['walkin_address'] ?? ''));
    if ($bookerName === '' || mb_strlen($bookerName) > 190) {
        bc_reply(400, ['ok' => false, 'message' => 'Enter the guest&rsquo;s full name.']);
    }
    if ($phone === null) {
        bc_reply(400, ['ok' => false, 'message' => 'Enter an 11-digit mobile number starting 09.']);
    }
    /* No account means no receipt upload, no GCash number on file and no email
       address — so the money has to change hands at the counter. */
    $method = 'cash';
    $isNewWalkIn = true;
}

/* ---- the room, and the rate THIS server believes in ---- */
try {
    $roomStmt = $pdo->prepare(
        "SELECT r.id, r.room_type, r.is_active, erd.attendee_capacity, erd.fee_per_day
           FROM rooms r LEFT JOIN event_room_details erd ON erd.room_id = r.id
          WHERE r.room_code = :c"
    );
    $roomStmt->execute([':c' => $roomCode]);
    $room = $roomStmt->fetch();
} catch (PDOException $e) {
    error_log('VENUSeP booking-create (room): ' . $e->getMessage());
    bc_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was created.']);
}
if (!$room || !$room['is_active'] || $room['room_type'] !== 'event') {
    bc_reply(404, ['ok' => false, 'message' => 'That room is not available for booking.']);
}

$refundsAllowed = refunds_enabled() ? 1 : 0;
if (!$refundsAllowed && empty($_POST['agree_no_refund'])) {
    bc_reply(400, ['ok' => false, 'message' => 'The customer must confirm they understand the booking is non-refundable.']);
}

/* ---- the days being asked for ---- */
$eventName = trim((string) ($_POST['event_name'] ?? ''));
$startIso  = (string) ($_POST['date_start'] ?? '');
$endIso    = (string) ($_POST['date_end'] ?? '') ?: $startIso;
$attendees = (int) ($_POST['attendees'] ?? 0);
$times     = json_decode((string) ($_POST['times'] ?? '[]'), true);
if (!is_array($times)) { $times = []; }

if ($eventName === '' || mb_strlen($eventName) > 190) {
    bc_reply(400, ['ok' => false, 'message' => 'Enter an event name.']);
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startIso) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endIso) || $endIso < $startIso) {
    bc_reply(400, ['ok' => false, 'message' => 'Pick valid reservation dates.']);
}
if ($attendees <= 0 || $attendees > (int) $room['attendee_capacity']) {
    bc_reply(400, ['ok' => false, 'message' => 'Enter an attendee count within the room capacity.']);
}

$blockStmt = $pdo->prepare('SELECT fn_room_hard_blocked(:r, :d) AS blocked');
$slots = [];
for ($d = $startIso; $d <= $endIso; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
    $blockStmt->execute([':r' => $room['id'], ':d' => $d]);
    if ((int) $blockStmt->fetchColumn() === 1) {
        continue;                       // a venue booking books AROUND a closed day
    }
    $t = isset($times[$d]) && is_array($times[$d]) ? $times[$d] : [];
    $s = isset($t['start']) ? (string) $t['start'] : '';
    $e = isset($t['end'])   ? (string) $t['end']   : '';
    if (!preg_match('/^\d{2}:\d{2}$/', $s) || !preg_match('/^\d{2}:\d{2}$/', $e) || $e <= $s) {
        bc_reply(400, ['ok' => false, 'message' => 'Set valid hours for ' . $d . '.']);
    }
    $slots[] = ['date' => $d, 'start' => $s . ':00', 'end' => $e . ':00'];
}
if (!$slots) {
    bc_reply(409, ['ok' => false, 'message' => 'Every day picked is closed for maintenance.']);
}

/* THE 12-HOUR RULE APPLIES TO WALK-INS TOO. Agreed deliberately: every booking
   needs an Official Receipt and one cannot be issued that fast, so a venue
   cannot be booked for the same afternoon at the counter any more than it can
   be online. The message says why, because a staff member has to explain it to
   the person in front of them. */
$LEAD_HOURS = 12;
if (strtotime($slots[0]['date'] . ' ' . $slots[0]['start']) - time() < $LEAD_HOURS * 3600) {
    bc_reply(409, ['ok' => false,
        'message' => 'Venue bookings must start at least ' . $LEAD_HOURS . ' hours from now — there has to be time for the Official Receipt.']);
}

/* Priced per day, from the room's own rate, for the days actually held. The
   discount is applied HERE because staff have already seen the ID. */
$roomPrice = (float) $room['fee_per_day'] * count($slots);
$pct       = $usepId ? (float) venusep_discount_percent() : 0.0;
$discount  = round($roomPrice * $pct) / 100;
$total     = $roomPrice - $discount;

/* ---------------------------------------------------------------------
   DEMO MODE — validated exactly as above, then recorded in the session
   instead of the database, so a showcase of the counter flow leaves
   nothing behind (includes/demo-mode.php).
   --------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/demo-mode.php';
if (demo_mode_on()) {
    $days   = count($slots);
    $policy = payment_policy_for($refundsAllowed, $slots[0]['date'], $slots[count($slots) - 1]['date']);
    $row = demo_add_booking([
        'type' => 'venue', 'customerId' => $customerId, 'customerName' => $bookerName,
        'customerEmail' => '', 'customerPhone' => (string) ($_POST['walkin_phone'] ?? ''),
        'isWalkIn' => true, 'isUsep' => (bool) $usepId, 'usepVerified' => (bool) $usepId,
        'roomCode' => $roomCode, 'roomName' => $roomCode, 'venueName' => '',
        'eventName' => $eventName, 'eventDateIso' => $slots[0]['date'],
        'endDateIso' => $slots[count($slots) - 1]['date'], 'days' => $days,
        'attendees' => $attendees, 'capacity' => (int) $room['attendee_capacity'],
        'roomPrice' => $roomPrice, 'discountPercent' => $pct, 'amount' => $total,
        'method' => $method === 'cash' ? 'Cash' : 'GCash',
        'reservationCode' => 'approved',
        'paymentCode' => $refundsAllowed ? ($method === 'cash' ? 'await_cash' : 'await_gcash') : 'await_event',
        'refundsAllowed' => (bool) $refundsAllowed, 'refundable' => false,
        'payment' => $policy, 'beds' => 0, 'nights' => 0,
    ]);
    bc_reply(200, ['ok' => true, 'demo' => true, 'bookingId' => $row['id'],
        'reference' => $row['bookingId'], 'payBy' => $policy['payByLabel'],
        'message' => 'Booking created (demo mode — nothing was saved).']);
}

/* ---- the write ---- */
try {
    $pdo->beginTransaction();

    /* A walk-in with no account is a customers row with user_id NULL — the
       shape the database has always had for this (DB-DECISIONS #4). No email
       is stored because there is nowhere to put one: email lives on `users`,
       and having a users row is what would make them not a walk-in. */
    if ($isNewWalkIn) {
        $pdo->prepare('INSERT INTO customers (user_id, full_name, phone, address) VALUES (NULL, :n, :p, :a)')
            ->execute([':n' => $bookerName, ':p' => $phone, ':a' => $address !== '' ? $address : null]);
        $customerId = (int) $pdo->lastInsertId();
    }

    $ins = $pdo->prepare(
        "INSERT INTO bookings
            (customer_id, room_id, booking_type, reservation_status, payment_status,
             payment_method, is_usep_affiliated, affiliation_verified,
             room_price, discount_percent, discount_amount, total_amount,
             refunds_allowed, updated_by_user_id)
         VALUES (:c, :r, 'venue', 'pending', 'locked', :m, :a, :av, :rp, :dp, :da, :t, :ra, :u)"
    );
    $ins->execute([
        ':c' => $customerId, ':r' => $room['id'], ':m' => $method,
        ':a' => $usepId, ':av' => $usepId,
        ':rp' => $roomPrice, ':dp' => $pct, ':da' => $discount, ':t' => $total,
        ':ra' => $refundsAllowed, ':u' => $actor,
    ]);
    $bookingId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
         VALUES (:b, :n, :s, :e, :a)'
    )->execute([':b' => $bookingId, ':n' => $eventName, ':s' => $startIso, ':e' => $endIso, ':a' => $attendees]);

    /* The same concurrency guard the customer flow goes through: a room+date
       lock, a re-check of maintenance and of overlapping live holds. A counter
       booking cannot take a slot someone else is holding, however senior the
       person typing it is. */
    $slotCall = $pdo->prepare('CALL sp_add_venue_slot(:b, :d, :s, :e)');
    foreach ($slots as $s) {
        $slotCall->execute([':b' => $bookingId, ':d' => $s['date'], ':s' => $s['start'], ':e' => $s['end']]);
        $slotCall->closeCursor();
    }

    /* THE EVIDENCE. Each row is a judgement a person made, not a fact the
       system observed, so each one carries the name of whoever made it. */
    $tl = $pdo->prepare(
        'INSERT INTO booking_timeline (booking_id, action_code, performed_by_user_id, note)
         VALUES (:b, :a, :u, :n)'
    );
    $tl->execute([':b' => $bookingId, ':a' => 'counter_created', ':u' => $actor,
        ':n' => 'Walk-in booking taken at the counter for ' . $bookerName . '.']);
    $tl->execute([':b' => $bookingId, ':a' => 'counter_id_checked', ':u' => $actor,
        ':n' => $usepId
            ? 'Valid USeP ID inspected in person; ' . (int) $pct . '% affiliate discount applied.'
            : 'Valid ID inspected in person; no affiliate discount.']);
    if ($bookerMode === 'account') {
        $tl->execute([':b' => $bookingId, ':a' => 'counter_identity_confirmed', ':u' => $actor,
            ':n' => 'Customer confirmed the account by entering their password at the counter.']);
    }

    /* Approved on the spot, through the one procedure that knows what
       "approved" means for this type and this payment policy. */
    $ap = $pdo->prepare('CALL sp_approve_booking(:b, :u)');
    $ap->execute([':b' => $bookingId, ':u' => $actor]);
    $ap->closeCursor();

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    if ($e->getCode() !== '45000') {
        error_log('VENUSeP booking-create: ' . $e->getMessage());
    }
    bc_reply($e->getCode() === '45000' ? 409 : 500, ['ok' => false,
        'message' => $e->getCode() === '45000'
            ? 'That slot was taken while the booking was being made. Pick another time.'
            : 'Something went wrong, so nothing was created.']);
}

/* The identity proof is spent. It confirmed one person for one booking; leaving
   it in the session would let the next customer be booked on the last one's
   password. */
if ($bookerMode === 'account') {
    unset($_SESSION['counter_proof'][$customerId]);
}

try {
    $after = $pdo->prepare('SELECT submitted_at, current_deadline_at FROM bookings WHERE id = :b');
    $after->execute([':b' => $bookingId]);
    $row = $after->fetch();
} catch (PDOException $e) {
    $row = ['submitted_at' => null, 'current_deadline_at' => null];
}

bc_reply(200, [
    'ok'        => true,
    'bookingId' => $bookingId,
    'reference' => booking_reference($bookingId, $row['submitted_at']),
    'payBy'     => $row['current_deadline_at']
                    ? date('M j, Y', strtotime($row['current_deadline_at']))
                    : '—',
    'message'   => 'Booking created and approved.',
]);
