<?php
/* =====================================================================
   STAFF ACTIONS — the ONLY way staff change a booking.

   POST  csrf, booking=<VB-reference>, action=<name>, plus per-action fields
   Replies with JSON.

   One endpoint rather than eight, because every action needs the same four
   checks first — logged in as staff, CSRF, the booking exists, and the
   action is legal from the booking's CURRENT state. Eight endpoints would
   be eight places to forget one of them.

   STATE TRANSITIONS GO THROUGH THE PROCEDURES. sp_approve_booking() knows
   that a pre-pay hostel booking approves into 'await_pos' rather than
   'await_gcash', and recomputes the deadline; re-deriving that here would
   be a second opinion on the same rule. This endpoint decides WHO may do
   WHAT, and the database decides what the booking then becomes.

   THE DISCOUNT IS APPLIED HERE, not at booking time. A customer's
   affiliation is a CLAIM until staff see the ID (DB-DECISIONS #3), so
   approval is where the USeP rate is either granted or refused, and the
   rate is SNAPSHOTTED onto the booking so a later change to the live
   percentage cannot re-price it (#2).
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/bookings.php';
require_once __DIR__ . '/../includes/receipt-emails.php';   /* the System Receipt + the walk-in receipt email (DB-DECISIONS #22) */
/* pricing.php ends with a <script> block for the booking pages; this endpoint
   answers in JSON, so its output is swallowed and only the functions kept.
   (Same treatment includes/faqs.php gives it.) */
ob_start();
require_once __DIR__ . '/../includes/pricing.php';
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ba_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ba_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    ba_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    ba_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$actor  = (int) $_SESSION['user_id'];
$action = isset($_POST['action']) ? (string) $_POST['action'] : '';
$ref    = isset($_POST['booking']) ? (string) $_POST['booking'] : '';
$note   = trim((string) ($_POST['note'] ?? ''));

/* STAFF ACT ONLY ON THEIR OWN VENUE'S BOOKINGS (decided 2026-10-27). Checked
   before the demo branch too, so a showcase never teaches the wrong rule.
   Admins handle every venue (staff_venue_scope() in includes/auth.php). */
$scopeId = booking_id_from_reference($ref);
if ($scopeId !== null && staff_venue_scope() !== null) {
    $scopePdo = venusep_db();
    $scopeVenue = $scopePdo ? $scopePdo->prepare('SELECT r.venue_id FROM bookings b JOIN rooms r ON r.id = b.room_id WHERE b.id = :b') : null;
    if ($scopeVenue) {
        $scopeVenue->execute([':b' => $scopeId]);
        $venueOfBooking = $scopeVenue->fetchColumn();
        if ($venueOfBooking !== false && !staff_may_handle_venue($venueOfBooking)) {
            ba_reply(403, ['ok' => false, 'message' => 'This booking belongs to another venue. Only that venue\'s staff or an administrator can act on it.']);
        }
    }
}

/* ---------------------------------------------------------------------
   DEMO MODE — staff actions are a showcase too, and this is the endpoint
   that would otherwise APPROVE, CONFIRM and REFUND the seeded bookings a
   demo is built from. Left unguarded, one run through the staff flow
   would consume the very rows the next run needs.

   So while the switch is on, nothing here writes: the reply describes
   what WOULD have happened, the page redraws from it, and the seed is
   untouched. sp_seed_demo() stays a reset for rebuilding the showcase,
   not a chore after every rehearsal.
   --------------------------------------------------------------------- */
require_once __DIR__ . '/../includes/demo-mode.php';
if (demo_mode_on()) {
    $demoAfter = booking_by_reference($ref);
    if (!$demoAfter) {
        ba_reply(404, ['ok' => false, 'message' => 'That booking no longer exists.']);
    }
    /* What each action would have produced, so the screen still moves. */
    $res = $demoAfter['reservationCode'];
    $pay = $demoAfter['paymentCode'];
    switch ($action) {
        case 'approve':
            $res = 'approved';
            /* A pay-to-use hostel stay opens payment at once; an older one keeps
               its POS or post-pay route (sp_approve_booking draws the same lines). */
            $pay = !empty($demoAfter['payToUse']) ? ($demoAfter['method'] === 'Cash' ? 'await_cash' : 'await_gcash')
                 : (!$demoAfter['refundsAllowed'] ? 'await_event'
                 : ($demoAfter['type'] === 'hostel'
                    ? ($demoAfter['eventDateIso'] !== '' && $demoAfter['eventDateIso'] <= date('Y-m-d') ? 'await_cash' : 'await_pos')
                 : ($demoAfter['method'] === 'Cash' ? 'await_cash' : 'await_gcash')));
            break;
        case 'reject':           $res = 'rejected';  $pay = 'locked'; break;
        case 'confirm_payment':  $pay = $demoAfter['method'] === 'Cash' ? 'paid_cash' : 'confirmed'; break;
        case 'cash_received':
            if (empty($demoAfter['payToUse']) || $pay !== 'await_cash') {
                ba_reply(409, ['ok' => false, 'message' => $pay === 'await_gcash'
                    ? 'The guest has not chosen to pay at the front desk. Ask them to choose "Cash — hostel front desk" in Pay now first.'
                    : 'This stay is not waiting on a cash payment.']);
            }
            $pay = 'paid_cash';
            break;
        case 'reject_payment':   $pay = $demoAfter['method'] === 'Cash' ? 'await_cash' : 'await_gcash'; break;
        case 'record_pos':       $pay = ($demoAfter['refundsAllowed'] && empty($demoAfter['payToUse']) && $pay === 'await_pos') ? 'await_gcash' : $pay; break;
        case 'refund_return':    $pay = 'refund_correction'; break;
        case 'refund_deny':      $pay = 'refund_denied'; break;
        case 'refund_complete':  $res = 'cancelled'; $pay = 'refunded'; break;
        case 'check_in':
            if (!empty($demoAfter['payToUse']) && !in_array($pay, ['confirmed', 'paid_cash'], true)) {
                ba_reply(409, ['ok' => false, 'message' => 'Not paid yet. The guest pays before using the bed.']);
            }
            break;
        case 'record_or':
        case 'allow_receipt_attempts': break;   // documents, arrival and the attempt allowance change no status
        default:
            ba_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
    ba_reply(200, ['ok' => true, 'demo' => true,
        'message' => 'Saved (demo mode — nothing was written).',
        'booking' => ['res' => $res, 'pay' => $pay,
                      'resLabel' => 'Reservation ' . $res, 'payLabel' => str_replace('_', ' ', $pay),
                      'total' => $demoAfter['amount'], 'discount' => $demoAfter['discountPercent'],
                      'payBy' => $demoAfter['payment']['payByLabel'],
                      'method' => $action === 'cash_received' ? 'Cash' : $demoAfter['method']]]);
}

$bookingId = booking_id_from_reference($ref);
if ($bookingId === null) {
    ba_reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    ba_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
}

/* Apply the deadline rules first, so staff act on what the booking IS now: a
   request whose date has arrived cannot still be approved. */
venusep_expire_due_bookings();

$cur = $pdo->prepare(
    'SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
            b.is_usep_affiliated, b.room_price, b.refunds_allowed, b.pay_to_use, b.approved_at
       FROM bookings b WHERE b.id = :b'
);
$cur->execute([':b' => $bookingId]);
$bk = $cur->fetch();
if (!$bk) {
    ba_reply(404, ['ok' => false, 'message' => 'That booking no longer exists.']);
}

/* The timeline is written by trg_bookings_after_update on every status change;
   these two session variables are how it learns WHO acted and WHY. Set before
   each UPDATE, cleared after, so a later unrelated write cannot inherit them. */
function ba_actor(PDO $pdo, $actor, $note) {
    $pdo->prepare('SET @venusep_actor_user_id = :a')->execute([':a' => $actor]);
    $pdo->prepare('SET @venusep_action_note = :n')->execute([':n' => $note !== '' ? $note : null]);
}
function ba_clear(PDO $pdo) {
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
}

$queued = null;   /* an email this action queued; sent only after the action is committed */
try {
    switch ($action) {

    /* ---- APPROVE the ID + the reservation. Optionally grant the USeP rate. ---- */
    case 'approve':
        if ($bk['reservation_status'] === 'released' && $bk['approved_at'] === null) {
            ba_reply(409, ['ok' => false, 'message' => 'This request has expired: it was not approved before its date, so the slot was released.']);
        }
        if ($bk['reservation_status'] !== 'pending') {
            ba_reply(409, ['ok' => false, 'message' => 'Only a pending booking can be approved.']);
        }
        $withDiscount = !empty($_POST['discount']);
        if ($withDiscount && !$bk['is_usep_affiliated']) {
            ba_reply(400, ['ok' => false, 'message' => 'This booking did not claim USeP affiliation.']);
        }
        /* Snapshot the rate ONTO the booking. Reading the live percentage later
           would re-price a booking that was already quoted. */
        $pct    = $withDiscount ? (int) venusep_discount_percent() : 0;
        $amount = round((float) $bk['room_price'] * $pct / 100, 2);
        ba_actor($pdo, $actor, $note !== '' ? $note : ($withDiscount ? 'ID verified; USeP rate applied.' : 'ID verified.'));
        $pdo->prepare(
            'UPDATE bookings
                SET affiliation_verified = :v, discount_percent = :p, discount_amount = :d,
                    total_amount = room_price - :d2, updated_by_user_id = :u
              WHERE id = :b'
        )->execute([':v' => $withDiscount ? 1 : 0, ':p' => $pct, ':d' => $amount,
                    ':d2' => $amount, ':u' => $actor, ':b' => $bookingId]);
        /* The procedure decides what 'approved' MEANS for this booking: a
           pre-pay hostel booking goes to await_pos (CEDU), a post-pay booking
           to await_event, everything else to await_gcash/await_cash. */
        $pdo->prepare('CALL sp_approve_booking(:b, :a)')->execute([':b' => $bookingId, ':a' => $actor]);
        ba_clear($pdo);
        break;

    /* ---- REJECT the request. The booking keeps its history; the slot frees. ---- */
    case 'reject':
        if (in_array($bk['reservation_status'], ['rejected', 'cancelled', 'completed'], true)) {
            ba_reply(409, ['ok' => false, 'message' => 'That booking is already closed.']);
        }
        ba_actor($pdo, $actor, $note !== '' ? $note : 'Request rejected.');
        /* trg_bookings_after_update releases the held slots and bed-nights as
           soon as the reservation reaches a terminal state — nothing to free
           by hand, and nothing left holding a date it no longer has a claim to. */
        $pdo->prepare(
            "UPDATE bookings SET reservation_status = 'rejected', payment_status = 'locked',
                    staff_notes = :n, updated_by_user_id = :u WHERE id = :b"
        )->execute([':n' => $note, ':u' => $actor, ':b' => $bookingId]);
        ba_clear($pdo);
        break;

    /* ---- HOSTEL: the POS has come back from CEDU, so payment unlocks. ---- */
    case 'record_pos':
        $pos = trim((string) ($_POST['pos'] ?? ''));
        if ($bk['booking_type'] !== 'hostel') {
            ba_reply(400, ['ok' => false, 'message' => 'Only a hostel booking has a POS.']);
        }
        if ($pos === '') {
            ba_reply(400, ['ok' => false, 'message' => 'Enter the POS number.']);
        }
        $pdo->prepare(
            'UPDATE hostel_booking_details SET pos_number = :p, pos_recorded_at = NOW() WHERE booking_id = :b'
        )->execute([':p' => mb_substr($pos, 0, 100), ':b' => $bookingId]);
        /* Payment only opens now if this booking pre-pays. A post-pay booking
           stays locked until its stay is over, POS or no POS. A pay-to-use stay
           (2026-10-27) never waits on the POS: this only records the number. */
        if ($bk['refunds_allowed'] && !$bk['pay_to_use'] && $bk['payment_status'] === 'await_pos') {
            ba_actor($pdo, $actor, 'POS ' . $pos . ' recorded; payment unlocked.');
            $pdo->prepare(
                "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                        current_deadline_at = fn_payment_deadline(:b), updated_by_user_id = :u
                  WHERE id = :b2"
            )->execute([':b' => $bookingId, ':u' => $actor, ':b2' => $bookingId]);
            ba_clear($pdo);
        }
        break;

    /* ---- HOSTEL: the University Cashier issued the Official Receipt.
            The OR is a DOCUMENT, never a payment status — payment already
            ended at 'confirmed'. This only records the number. ---- */
    case 'record_or':
        $or = trim((string) ($_POST['or'] ?? ''));
        if ($bk['booking_type'] !== 'hostel') {
            ba_reply(400, ['ok' => false, 'message' => 'Only a hostel booking has an Official Receipt.']);
        }
        if ($or === '') {
            ba_reply(400, ['ok' => false, 'message' => 'Enter the Official Receipt number.']);
        }
        $pdo->prepare(
            'UPDATE hostel_booking_details SET official_receipt_no = :o, official_receipt_recorded_at = NOW()
              WHERE booking_id = :b'
        )->execute([':o' => mb_substr($or, 0, 100), ':b' => $bookingId]);
        break;

    /* ---- HOSTEL: the guest arrived. A manual staff action, hostel only.
            PAY TO USE (2026-10-27): no bed until the stay is paid — a GCash
            receipt still under review is not paid yet. ---- */
    case 'check_in':
        if ($bk['booking_type'] !== 'hostel') {
            ba_reply(400, ['ok' => false, 'message' => 'Only a hostel booking has a check-in.']);
        }
        if ($bk['pay_to_use'] && !in_array($bk['payment_status'], ['confirmed', 'paid_cash'], true)) {
            ba_reply(409, ['ok' => false, 'message' => $bk['payment_status'] === 'under_review'
                ? 'Not paid yet: the GCash receipt is still under review. Confirm it first, then check the guest in.'
                : ($bk['payment_status'] === 'await_cash'
                    ? 'Not paid yet. The guest chose to pay cash at the front desk — take it first (Cash received).'
                    : 'Not paid yet. The guest pays before using the bed — by GCash in Pay now, or by choosing cash at the front desk there.')]);
        }
        $pdo->prepare(
            'UPDATE hostel_booking_details SET checked_in = 1, checked_in_at = NOW() WHERE booking_id = :b'
        )->execute([':b' => $bookingId]);
        break;

    /* ---- HOSTEL, PAY TO USE (2026-10-27): the guest paid CASH at the front
            desk. Whatever they meant to pay with, the money in hand is cash,
            so the booking becomes a cash booking and is paid at once. ---- */
    case 'cash_received':
        if (!$bk['pay_to_use']) {
            ba_reply(400, ['ok' => false, 'message' => 'Only a hostel stay is paid in cash at the front desk this way.']);
        }
        if ($bk['payment_status'] === 'under_review') {
            ba_reply(409, ['ok' => false, 'message' => 'A GCash receipt is waiting for review. Confirm it or reject it first, then take the cash if it was rejected.']);
        }
        /* Only once the GUEST chose to pay at the front desk (2026-10-28) — in
           Pay now, which also closes GCash for them until they cancel it. */
        if ($bk['payment_status'] === 'await_gcash') {
            /* the current state goes back too: the guest may have cancelled the
               cash choice after this page was opened, and the page redraws */
            ba_reply(409, ['ok' => false, 'booking' => ['pay' => 'await_gcash'],
                'message' => 'The guest has not chosen to pay at the front desk (or cancelled it). Ask them to choose "Cash — hostel front desk" in Pay now first.']);
        }
        if ($bk['payment_status'] !== 'await_cash') {
            ba_reply(409, ['ok' => false, 'message' => 'This stay is not waiting on a payment.']);
        }
        /* fall through: the rest is confirming a payment, as cash */

    /* ---- Money landed and staff verified it. ---- */
    case 'confirm_payment':
        if (!in_array($bk['payment_status'], ['await_gcash', 'await_cash', 'under_review', 'overdue'], true)) {
            ba_reply(409, ['ok' => false, 'message' => 'This booking is not waiting on a payment.']);
        }
        $asCash = $action === 'cash_received';
        /* Waiting on GCash with no receipt in: there is nothing to confirm. Staff
           never record a GCash payment by hand (#18), and this is also a cash
           button clicked on a page loaded before the customer cancelled paying
           cash (2026-10-28) — refused, and the page redraws. */
        if ($action === 'confirm_payment' && $bk['payment_status'] === 'await_gcash') {
            ba_reply(409, ['ok' => false, 'booking' => ['pay' => 'await_gcash'],
                'message' => 'Nothing to confirm: the customer is paying by GCash and has not sent a receipt yet (they may have cancelled paying cash).']);
        }
        /* ONE transaction: the payment, its ledger row and its System Receipt
           land together or not at all, so a paid booking never lacks a receipt.
           The VENUSeP System Receipt: a walk-in who gave an email at the counter
           is sent it; account holders find it in My Bookings (nothing automatic). */
        $pdo->beginTransaction();
        $receiptId = payment_confirm($pdo, $bookingId, $actor,
            $note !== '' ? $note : ($asCash ? 'Cash received at the front desk.' : 'Payment confirmed.'), $asCash);
        if ($receiptId !== null) {
            /* A database error here undoes the confirmation like any other; a
               problem building the EMAIL must not — the payment is real. */
            try {
                $queued = queue_walkin_receipt($pdo, $bookingId, $receiptId, $actor);
            } catch (\Throwable $mailErr) {
                if ($mailErr instanceof PDOException) {
                    throw $mailErr;
                }
                error_log('VENUSeP walk-in receipt email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
            }
        }
        $pdo->commit();
        break;

    /* ---- The receipt was wrong. The booking stays live so the customer can
            try again before the deadline; only the payment goes back. ---- */
    case 'reject_payment':
        if ($note === '') {
            ba_reply(400, ['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
        }
        $pdo->beginTransaction();
        /* The receipt staff just looked at keeps the CHECKER's verdict, so its
           reference and image stay taken on every booking (the active_* UNIQUEs)
           — a receipt a person found no payment for must not come back. What it
           gains is who decided, when and why; customer/pay.php reads that to
           warn before the same receipt is uploaded again (DB-DECISIONS #6).
           Under review, the latest upload is always the one being reviewed:
           nothing can be uploaded while a receipt is with staff. */
        if ($bk['payment_status'] === 'under_review') {
            $pdo->prepare(
                "UPDATE gcash_receipts
                    SET reviewed_by_user_id = :u, reviewed_at = NOW(), override_note = :n,
                        flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'rejected_by_staff')
                  WHERE booking_id = :b AND reviewed_at IS NULL
                  ORDER BY id DESC LIMIT 1"
            )->execute([':u' => $actor, ':n' => $note, ':b' => $bookingId]);
        }
        ba_actor($pdo, $actor, 'Payment rejected: ' . $note);
        $pdo->prepare(
            "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    staff_notes = :n, updated_by_user_id = :u WHERE id = :b"
        )->execute([':n' => $note, ':u' => $actor, ':b' => $bookingId]);
        ba_clear($pdo);
        $pdo->prepare("UPDATE payments SET payment_record_status = 'rejected' WHERE booking_id = :b AND payment_record_status IN ('submitted','under_review')")
            ->execute([':b' => $bookingId]);
        $pdo->commit();
        break;

    /* ---- The 5-attempt rule (DB-DECISIONS #6): this booking used all its GCash
            receipt attempts, and a person decides it may have five more. The
            count itself never resets — the allowance grows, with a written
            reason in the booking's timeline, so it can never happen silently. ---- */
    case 'allow_receipt_attempts': {
        if ($note === '') {
            ba_reply(400, ['ok' => false, 'message' => 'A note is required — say why this booking gets more attempts.']);
        }
        $att = $pdo->prepare(
            'SELECT b.receipt_attempts_allowed, (SELECT COUNT(*) FROM gcash_receipts g WHERE g.booking_id = b.id) AS used
               FROM bookings b WHERE b.id = :b'
        );
        $att->execute([':b' => $bookingId]);
        $a = $att->fetch();
        if ((int) $a['used'] < (int) $a['receipt_attempts_allowed']) {
            ba_reply(409, ['ok' => false, 'message' => 'This booking still has receipt attempts left.']);
        }
        if (!in_array($bk['payment_status'], ['await_gcash', 'await_cash', 'overdue'], true)) {
            ba_reply(409, ['ok' => false, 'message' => 'Payment is not open on this booking. Reject the receipt under review first, or confirm it.']);
        }
        $newAllowed = (int) $a['used'] + 5;
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE bookings SET receipt_attempts_allowed = :n, updated_by_user_id = :u WHERE id = :b')
            ->execute([':n' => $newAllowed, ':u' => $actor, ':b' => $bookingId]);
        /* Not a status change, so the timeline trigger would not see it — written here. */
        $pdo->prepare(
            "INSERT INTO booking_timeline (booking_id, action_code, performed_by_user_id, note, metadata_json)
             VALUES (:b, 'receipt_attempts_allowed', :u, :n, :m)"
        )->execute([':b' => $bookingId, ':u' => $actor,
                    ':n' => '5 more GCash receipt attempts (now ' . $newAllowed . ' in total): ' . $note,
                    ':m' => json_encode(['allowed_before' => (int) $a['receipt_attempts_allowed'], 'allowed_after' => $newAllowed])]);
        $pdo->commit();
        break;
    }

    /* ================= THE REFUND CHAIN (staff decisions) =================
       A refund travels: requested -> (returned_for_correction) -> approved
       -> completed, or -> rejected, or the customer withdraws it. Only
       'completed' closes the booking and frees its date; every other outcome
       leaves the booking exactly as it was. That is the whole point of the
       chain — a refund being refused must not cost someone their reservation.
       ===================================================================== */
    case 'refund_return':
    case 'refund_deny':
    case 'refund_complete': {
        $rf = $pdo->prepare('SELECT * FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
        $rf->execute([':b' => $bookingId]);
        $refund = $rf->fetch();
        if (!$refund) {
            ba_reply(404, ['ok' => false, 'message' => 'There is no refund request on this booking.']);
        }
        /* The customer can withdraw while this page is open. Deciding a request
           that no longer exists would show staff an outcome the customer never
           receives — so the state is re-read here, not trusted from the page. */
        if (!in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction', 'approved'], true)) {
            ba_reply(409, ['ok' => false,
                'message' => 'This request is no longer open — it was ' . str_replace('_', ' ', $refund['refund_status'])
                           . ' while this page was open. Nothing has been recorded.']);
        }
        if ($note === '' && $action !== 'refund_complete') {
            ba_reply(400, ['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
        }

        if ($action === 'refund_return') {
            /* PAPERWORK, not a denial. The customer fixes and resubmits, and
               the booking is untouched. correction_attempts is bumped on the
               resubmit, not here, so it counts round trips the customer made. */
            ba_actor($pdo, $actor, 'Refund returned for correction: ' . $note);
            $pdo->prepare(
                "UPDATE refunds SET refund_status = 'returned_for_correction', reviewed_by_user_id = :u,
                        reviewed_at = NOW(), notes = :n, correction_due_at = DATE_ADD(NOW(), INTERVAL 7 DAY)
                  WHERE id = :r"
            )->execute([':u' => $actor, ':n' => $note, ':r' => $refund['id']]);
            $pdo->prepare("UPDATE bookings SET payment_status = 'refund_correction', updated_by_user_id = :u WHERE id = :b")
                ->execute([':u' => $actor, ':b' => $bookingId]);
            ba_clear($pdo);

        } elseif ($action === 'refund_deny') {
            /* FINAL. The customer would have to book again — but this booking
               keeps its date and its paid status, because refusing a refund is
               not the same as cancelling a reservation. */
            ba_actor($pdo, $actor, 'Refund denied: ' . $note);
            $pdo->prepare(
                "UPDATE refunds SET refund_status = 'rejected', reviewed_by_user_id = :u,
                        reviewed_at = NOW(), notes = :n WHERE id = :r"
            )->execute([':u' => $actor, ':n' => $note, ':r' => $refund['id']]);
            $pdo->prepare("UPDATE bookings SET payment_status = 'refund_denied', updated_by_user_id = :u WHERE id = :b")
                ->execute([':u' => $actor, ':b' => $bookingId]);
            ba_clear($pdo);

        } else {
            /* THE PAYOUT. THE OR RULE, enforced rather than merely asked about:
               no Official Receipt, no payout. An exception is allowed because
               reality demands one, but it costs a written reason that lands in
               the record — not a dialog anyone can click past. */
            if ($refund['official_receipt_pending'] && $note === '') {
                ba_reply(400, ['ok' => false,
                    'message' => 'The Official Receipt has not been provided, and a refund cannot be paid without it. '
                               . 'If you are recording an exception, write why in the note.']);
            }
            $payoutRef = trim((string) ($_POST['payout_reference'] ?? ''));
            $amount    = ($_POST['amount'] ?? '') !== '' ? (float) $_POST['amount'] : (float) $refund['amount_requested'];
            if ($amount <= 0 || $amount > (float) $refund['amount_requested']) {
                ba_reply(400, ['ok' => false, 'message' => 'The refunded amount cannot exceed what was requested.']);
            }
            ba_actor($pdo, $actor, 'Refund paid out' . ($payoutRef !== '' ? ' · GCash ref ' . $payoutRef : '') . ($note !== '' ? ' · ' . $note : ''));
            $pdo->prepare(
                "UPDATE refunds SET refund_status = 'completed', amount_approved = :a,
                        payout_reference = :p, reviewed_by_user_id = :u, reviewed_at = COALESCE(reviewed_at, NOW()),
                        completed_at = NOW(), notes = CONCAT_WS(' | ', NULLIF(notes,''), :n)
                  WHERE id = :r"
            )->execute([':a' => $amount, ':p' => $payoutRef !== '' ? mb_substr($payoutRef, 0, 100) : null,
                        ':u' => $actor, ':n' => $note !== '' ? $note : null, ':r' => $refund['id']]);
            /* ONLY NOW is the booking closed and its date freed —
               trg_bookings_after_update releases the held slots and bed-nights
               the moment the reservation reaches a terminal state. */
            $pdo->prepare(
                "UPDATE bookings SET reservation_status = 'cancelled', payment_status = 'refunded',
                        cancelled_at = NOW(), updated_by_user_id = :u WHERE id = :b"
            )->execute([':u' => $actor, ':b' => $bookingId]);
            $pdo->prepare("UPDATE payments SET payment_record_status = 'refunded' WHERE booking_id = :b AND payment_record_status = 'confirmed'")
                ->execute([':b' => $bookingId]);
            ba_clear($pdo);
        }
        break;
    }

    default:
        ba_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ba_clear($pdo);
    /* 45000 is a procedure refusing on a real rule — a booking in the wrong
       state, a closed room. Those are worth showing staff verbatim. */
    if ($e instanceof PDOException && $e->getCode() === '45000') {
        ba_reply(409, ['ok' => false, 'message' => $e->getMessage()]);
    }
    error_log('VENUSeP booking-action ' . $action . ': ' . $e->getMessage());
    ba_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
}

/* Committed. Now, and only now, the email goes out — a mail server being down
   leaves a 'failed' outbox row for Resend and never touches the action. */
if ($queued !== null) {
    mail_send($pdo, $queued);
}

/* Hand back the booking's new state so the page can redraw from the truth
   rather than from what it hoped would happen. */
$after = booking_by_reference($ref);
ba_reply(200, [
    'ok'      => true,
    'message' => 'Saved.',
    'booking' => $after ? [
        'res'        => $after['reservationCode'],
        'pay'        => $after['paymentCode'],
        'resLabel'   => $after['bookingStatusStaff'],
        'payLabel'   => $after['paymentStatusStaff'],
        'total'      => $after['amount'],
        'discount'   => $after['discountPercent'],
        'payBy'      => $after['payment']['payByLabel'],
        'method'     => $after['method'],          /* Cash received turns a stay into a cash booking */
        'attempts'        => $after['receiptAttempts'],
        'attemptsAllowed' => $after['receiptAttemptsAllowed'],
    ] : null,
]);
