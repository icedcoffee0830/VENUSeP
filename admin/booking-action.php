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
            $pay = !$demoAfter['refundsAllowed'] ? 'await_event'
                 : ($demoAfter['type'] === 'hostel' ? 'await_pos'
                 : ($demoAfter['method'] === 'Cash' ? 'await_cash' : 'await_gcash'));
            break;
        case 'reject':           $res = 'rejected';  $pay = 'locked'; break;
        case 'confirm_payment':  $pay = $demoAfter['method'] === 'Cash' ? 'paid_cash' : 'confirmed'; break;
        case 'reject_payment':   $pay = $demoAfter['method'] === 'Cash' ? 'await_cash' : 'await_gcash'; break;
        case 'record_pos':       $pay = $demoAfter['refundsAllowed'] ? 'await_gcash' : $pay; break;
        case 'refund_return':    $pay = 'refund_correction'; break;
        case 'refund_deny':      $pay = 'refund_denied'; break;
        case 'refund_complete':  $res = 'cancelled'; $pay = 'refunded'; break;
        case 'record_or':
        case 'check_in':         break;      // documents and arrival change no status
        default:
            ba_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
    ba_reply(200, ['ok' => true, 'demo' => true,
        'message' => 'Saved (demo mode — nothing was written).',
        'booking' => ['res' => $res, 'pay' => $pay,
                      'resLabel' => 'Reservation ' . $res, 'payLabel' => str_replace('_', ' ', $pay),
                      'total' => $demoAfter['amount'], 'discount' => $demoAfter['discountPercent'],
                      'payBy' => $demoAfter['payment']['payByLabel']]]);
}

$bookingId = booking_id_from_reference($ref);
if ($bookingId === null) {
    ba_reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    ba_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
}

$cur = $pdo->prepare(
    'SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
            b.is_usep_affiliated, b.room_price, b.refunds_allowed
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

try {
    switch ($action) {

    /* ---- APPROVE the ID + the reservation. Optionally grant the USeP rate. ---- */
    case 'approve':
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
           stays locked until its stay is over, POS or no POS. */
        if ($bk['refunds_allowed'] && $bk['payment_status'] === 'await_pos') {
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

    /* ---- HOSTEL: the guest arrived. A manual staff action, hostel only. ---- */
    case 'check_in':
        if ($bk['booking_type'] !== 'hostel') {
            ba_reply(400, ['ok' => false, 'message' => 'Only a hostel booking has a check-in.']);
        }
        $pdo->prepare(
            'UPDATE hostel_booking_details SET checked_in = 1, checked_in_at = NOW() WHERE booking_id = :b'
        )->execute([':b' => $bookingId]);
        break;

    /* ---- Money landed and staff verified it. ---- */
    case 'confirm_payment':
        if (!in_array($bk['payment_status'], ['await_gcash', 'await_cash', 'under_review', 'overdue'], true)) {
            ba_reply(409, ['ok' => false, 'message' => 'This booking is not waiting on a payment.']);
        }
        $paid = $bk['payment_method'] === 'cash' ? 'paid_cash' : 'confirmed';
        ba_actor($pdo, $actor, $note !== '' ? $note : 'Payment confirmed.');
        $pdo->prepare('UPDATE bookings SET payment_status = :s, updated_by_user_id = :u WHERE id = :b')
            ->execute([':s' => $paid, ':u' => $actor, ':b' => $bookingId]);
        ba_clear($pdo);
        /* The payment row is the money's own record, separate from the booking's
           state — a booking says what it owes, a payment says what arrived.

           SETTLE the row the customer's submission already created rather than
           adding another. Inserting unconditionally gave one payment TWO rows,
           an 'under_review' and a 'confirmed', for a single transfer — which
           would have made the ledger describe money that never moved twice. */
        $settle = $pdo->prepare(
            "UPDATE payments SET payment_record_status = 'confirmed', confirmed_at = NOW(),
                    confirmed_by_user_id = :u
              WHERE booking_id = :b AND payment_record_status IN ('submitted', 'under_review')"
        );
        $settle->execute([':u' => $actor, ':b' => $bookingId]);
        if ($settle->rowCount() === 0) {
            /* Nothing was pending: a cash payment handed over at the counter,
               which the customer side never records because no money moves
               online. Staff confirming IS its first record. */
            $pdo->prepare(
                "INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at, confirmed_at, confirmed_by_user_id)
                 SELECT id, COALESCE(payment_method, 'gcash'), total_amount, 'confirmed', NOW(), NOW(), :u
                   FROM bookings WHERE id = :b"
            )->execute([':u' => $actor, ':b' => $bookingId]);
        }
        break;

    /* ---- The receipt was wrong. The booking stays live so the customer can
            try again before the deadline; only the payment goes back. ---- */
    case 'reject_payment':
        if ($note === '') {
            ba_reply(400, ['ok' => false, 'message' => 'A reason is required — the customer reads it.']);
        }
        ba_actor($pdo, $actor, 'Payment rejected: ' . $note);
        $pdo->prepare(
            "UPDATE bookings SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    staff_notes = :n, updated_by_user_id = :u WHERE id = :b"
        )->execute([':n' => $note, ':u' => $actor, ':b' => $bookingId]);
        ba_clear($pdo);
        $pdo->prepare("UPDATE payments SET payment_record_status = 'rejected' WHERE booking_id = :b AND payment_record_status IN ('submitted','under_review')")
            ->execute([':b' => $bookingId]);
        break;

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
} catch (PDOException $e) {
    ba_clear($pdo);
    /* 45000 is a procedure refusing on a real rule — a booking in the wrong
       state, a closed room. Those are worth showing staff verbatim. */
    if ($e->getCode() === '45000') {
        ba_reply(409, ['ok' => false, 'message' => $e->getMessage()]);
    }
    ba_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
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
    ] : null,
]);
