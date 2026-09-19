<?php
/* =====================================================================
   REFUND SUBMIT — the customer's side of the refund chain.

   POST  csrf, booking=<VB-reference>, action=file|withdraw|resubmit
         file/resubmit: reason_category, reason, refund_to, or_pending
         FILES (optional): support[] — the documents staff asked for
   Replies with JSON.

   This replaces includes/refund-store.php, which carried refund requests
   between the two portals in localStorage. That store was per-browser:
   a customer could file a refund and no staff member on any other machine
   would ever see it. A refund is a `refunds` row now, so it reaches the
   queue the way every other piece of work does.

   ELIGIBILITY IS RE-CHECKED HERE. The history page decides whether to
   OFFER the button; this decides whether the request is accepted, and it
   asks the same question of the same data so the two cannot disagree.

   THE LIVE SWITCH IS DELIBERATELY NOT CONSULTED. A booking carries the
   policy it was MADE under (bookings.refunds_allowed, DB-DECISIONS #16):
   turning refunds off must never take a refund away from a booking that
   was sold as refundable, and turning it on must not grant one to a
   booking sold without.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/bookings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rs_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rs_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
if (!customer_logged_in()) {
    rs_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    rs_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$action = (string) ($_POST['action'] ?? '');
$ref    = (string) ($_POST['booking'] ?? '');

/* DEMO MODE — a refund against a session-only booking. The chain still walks
   (file -> withdraw -> file again), so the screens can be shown; nothing is
   written, so the showcase is repeatable. */
require_once __DIR__ . '/../includes/demo-mode.php';
if (demo_owns($ref)) {
    if ($action === 'withdraw') {
        $row = demo_get_booking($ref);
        demo_update_booking($ref, [
            'refundStatus' => null,
            'paymentCode' => $row['method'] === 'Cash' ? 'paid_cash' : 'confirmed',
            'paymentStatus' => 'Paid',
        ]);
        rs_reply(200, ['ok' => true, 'demo' => true, 'status' => 'withdrawn',
            'message' => 'Request withdrawn. Your booking is unchanged. (Demo mode — nothing was saved.)']);
    }
    demo_update_booking($ref, ['refundStatus' => 'requested',
        'paymentCode' => 'refund_requested', 'paymentStatus' => 'Refund requested',
        'paymentStatusStaff' => 'Refund requested']);
    rs_reply(200, ['ok' => true, 'demo' => true, 'status' => 'requested', 'refundId' => 0,
        'documents' => ['stored' => 0, 'failed' => 0],
        'message' => 'Refund request filed (demo mode — nothing was saved).']);
}

$id = booking_id_from_reference($ref);
if ($id === null || !in_array($action, ['file', 'withdraw', 'resubmit'], true)) {
    rs_reply(400, ['ok' => false, 'message' => 'Invalid request.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    rs_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
}

/* Scoped to the session customer — this is what stops someone opening, or
   withdrawing, a refund on a booking that is not theirs by typing its number. */
$bStmt = $pdo->prepare(
    'SELECT b.id, b.payment_method, b.payment_status, b.total_amount, b.refunds_allowed,
            COALESCE(vd.start_date, hd.check_in_date) AS first_day,
            (SELECT p.id FROM payments p WHERE p.booking_id = b.id
              AND p.payment_record_status = \'confirmed\' ORDER BY p.id DESC LIMIT 1) AS payment_id
       FROM bookings b
       LEFT JOIN venue_booking_details vd  ON vd.booking_id = b.id
       LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
      WHERE b.id = :b AND b.customer_id = :c'
);
$bStmt->execute([':b' => $id, ':c' => (int) $_SESSION['customer_id']]);
$booking = $bStmt->fetch();
if (!$booking) {
    rs_reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
}

/* The latest refund on this booking, if any. */
$rStmt = $pdo->prepare('SELECT * FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
$rStmt->execute([':b' => $id]);
$refund = $rStmt->fetch();

/* What the booking's payment goes back to when a request ends without a
   payout. Derived from the method rather than remembered, because those are
   the only two states a paid booking can be in. */
$paidState = $booking['payment_method'] === 'cash' ? 'paid_cash' : 'confirmed';

/* ---------------------------------------------------------------------
   WITHDRAW — the customer pulls the request before any decision.
   The booking is untouched: it was never cancelled by filing.
   --------------------------------------------------------------------- */
if ($action === 'withdraw') {
    if (!$refund || !in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction'], true)) {
        rs_reply(409, ['ok' => false, 'message' => 'There is no open refund request to withdraw.']);
    }
    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE refunds SET refund_status = 'withdrawn', withdrawn_at = NOW() WHERE id = :r")
            ->execute([':r' => $refund['id']]);
        $pdo->prepare('SET @venusep_actor_user_id = :u, @venusep_action_note = :n')
            ->execute([':u' => (int) $_SESSION['user_id'], ':n' => 'Refund request withdrawn by the customer.']);
        $pdo->prepare('UPDATE bookings SET payment_status = :s WHERE id = :b')
            ->execute([':s' => $paidState, ':b' => $id]);
        $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        rs_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
    }
    rs_reply(200, ['ok' => true, 'status' => 'withdrawn', 'message' => 'Request withdrawn. Your booking is unchanged.']);
}

/* ---------------------------------------------------------------------
   FILE / RESUBMIT
   --------------------------------------------------------------------- */
$reasonCat = (string) ($_POST['reason_category'] ?? 'other');
$allowedCat = ['event_cancelled', 'schedule_conflict', 'wrong_room', 'venue_unavailable', 'payment_error', 'other'];
if (!in_array($reasonCat, $allowedCat, true)) {
    $reasonCat = 'other';
}
$reason    = trim((string) ($_POST['reason'] ?? ''));
$orPending = !empty($_POST['or_pending']) ? 1 : 0;
$refundTo  = cb_normalise_mobile((string) ($_POST['refund_to'] ?? ''));

if ($reason === '') {
    rs_reply(400, ['ok' => false, 'message' => 'Tell us why you are requesting a refund.']);
}
/* A GCash number is required only when the money went out by GCash — a cash
   booking is refunded at the counter, so demanding a mobile number would be
   asking for something that has nowhere to go. */
if ($booking['payment_method'] !== 'cash' && $refundTo === null) {
    rs_reply(400, ['ok' => false, 'message' => 'Enter a valid GCash number in the form 09XX XXX XXXX — this is where your refund will be sent.']);
}

if ($action === 'file') {
    if ($refund && in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction'], true)) {
        rs_reply(409, ['ok' => false, 'message' => 'You already have an open refund request for this booking.']);
    }
    /* THE ELIGIBILITY RULE, asked of the same function the history page asks
       (includes/bookings.php). A past event is a service already delivered;
       refunding it is a staff-side exception, not a self-service request. */
    $rows = bookings_query(['booking_id' => $id]);
    if (!$rows || !$rows[0]['refundable']) {
        $why = !$booking['refunds_allowed']
            ? 'This booking was made under the non-refundable policy.'
            : ($booking['first_day'] <= date('Y-m-d')
                ? 'This booking cannot be refunded because the event has already taken place.'
                : 'This booking is not eligible for a refund.');
        rs_reply(409, ['ok' => false, 'message' => $why]);
    }
} else {
    /* RESUBMIT — only from a correction, which is a PAPERWORK problem and not
       a denial. The original filing date is kept so "no staff reply for N days"
       measures the real wait rather than restarting the clock. */
    if (!$refund || $refund['refund_status'] !== 'returned_for_correction') {
        rs_reply(409, ['ok' => false, 'message' => 'There is nothing to resubmit.']);
    }
}

try {
    $pdo->beginTransaction();

    if ($action === 'file') {
        $pdo->prepare(
            "INSERT INTO refunds (booking_id, payment_id, refund_status, reason_category, reason,
                                  amount_requested, refund_to_number, official_receipt_pending, requested_by_user_id)
             VALUES (:b, :p, 'requested', :c, :r, :a, :t, :o, :u)"
        )->execute([
            ':b' => $id, ':p' => $booking['payment_id'] ?: null, ':c' => $reasonCat,
            ':r' => mb_substr($reason, 0, 2000), ':a' => $booking['total_amount'],
            ':t' => $refundTo, ':o' => $orPending, ':u' => (int) $_SESSION['user_id'],
        ]);
        $refundId = (int) $pdo->lastInsertId();
    } else {
        $refundId = (int) $refund['id'];
        $pdo->prepare(
            "UPDATE refunds SET refund_status = 'requested', reason_category = :c, reason = :r,
                    refund_to_number = :t, official_receipt_pending = :o,
                    resubmitted_at = NOW(), correction_attempts = correction_attempts + 1
              WHERE id = :i"
        )->execute([':c' => $reasonCat, ':r' => mb_substr($reason, 0, 2000),
                    ':t' => $refundTo, ':o' => $orPending, ':i' => $refundId]);
    }

    /* The booking is NOT cancelled by filing. It stays live — and keeps holding
       its date — until the refund COMPLETES. Only then is the date freed. */
    $pdo->prepare('SET @venusep_actor_user_id = :u, @venusep_action_note = :n')
        ->execute([':u' => (int) $_SESSION['user_id'],
                   ':n' => $action === 'file' ? 'Refund requested by the customer.' : 'Corrected documents resubmitted.']);
    $pdo->prepare("UPDATE bookings SET payment_status = 'refund_requested' WHERE id = :b")->execute([':b' => $id]);
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    rs_reply(500, ['ok' => false, 'message' => 'Something went wrong, so your request was not filed.']);
}

/* Supporting documents, stored outside the web root like every other
   sensitive upload. A failure here does not throw the request away — the
   claim is filed and staff can chase a missing document, which is a far
   better outcome than losing the request over one file. */
$stored = 0; $failed = 0;
if (!empty($_FILES['support']['name'][0])) {
    foreach ($_FILES['support']['name'] as $i => $name) {
        if ($_FILES['support']['error'][$i] !== UPLOAD_ERR_OK) { continue; }
        $err = '';
        $ok = doc_store($id, 'refund_support', $_FILES['support']['tmp_name'][$i], $name, (int) $_SESSION['user_id'], $err);
        $ok === null ? $failed++ : $stored++;
    }
}

rs_reply(200, [
    'ok'        => true,
    'status'    => 'requested',
    'refundId'  => $refundId,
    'documents' => ['stored' => $stored, 'failed' => $failed],
    'message'   => $action === 'file'
        ? 'Refund request filed. Your booking stays live until it is decided.'
        : 'Corrected documents sent. Your request is back with staff.',
]);
