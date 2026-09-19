<?php
/* =====================================================================
   PAYMENT SUBMIT — the ONLY way a customer records a payment.

   POST (multipart for GCash)
     csrf, booking=<VB-reference>, method=gcash|cash
     gcash: receipt FILE, plus what the browser's OCR read —
            ref, receiver_name, receiver_number, amount_centavos,
            receipt_datetime, confidence, flags (json), text_excerpt
   Replies with JSON.

   ---------------------------------------------------------------------
   WHAT THIS CAN AND CANNOT GUARANTEE — read before trusting it
   ---------------------------------------------------------------------
   The OCR runs in the BROWSER (Tesseract.js), so the parsed fields
   arriving here are a claim, not evidence. A tampered client can say the
   receipt reads any amount it likes. That is not fixable without running
   OCR server-side, which is a much larger job and deliberately deferred.

   So this endpoint does not pretend to verify a receipt. What it DOES
   guarantee, and what makes the claim safe to accept:

     1. THE VERDICT IS COMPUTED HERE, never accepted from the client. The
        amount is compared against the booking's own total_amount and the
        receiver against the venue's own gcash_accounts row — both read
        from the database, so a client cannot supply the thing it is
        checked against.
     2. THE FILE HASH IS COMPUTED HERE from the bytes that actually
        arrived, not taken from the client.
     3. DUPLICATES ARE ENFORCED BY THE DATABASE. uq_gcash_reference and
        uq_gcash_file_hash are UNIQUE across ALL bookings, forever — the
        old localStorage store was per-browser and forgot everything the
        moment you switched machines.
     4. NOTHING HERE CONFIRMS A PAYMENT. The best outcome is
        'under_review': a human still matches the reference in GCash and
        clicks confirm. The scanner has never been trusted to confirm
        money, and this does not change that.

   The honest summary: lying to this endpoint gets a receipt recorded as
   'accepted' that a staff member will then fail to find in GCash. It
   cannot move money, free a reference, or reuse a file.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/bookings.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ps_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ps_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
if (!customer_logged_in()) {
    ps_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    ps_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$ref    = (string) ($_POST['booking'] ?? '');
$method = ($_POST['method'] ?? '') === 'cash' ? 'cash' : 'gcash';

/* DEMO MODE — a payment against a booking that only exists in the session.
   The receipt is not stored, no reference is consumed, and no UNIQUE index is
   touched, which is what makes the same test screenshot re-usable on every
   run. The verdict still reflects what was actually sent so the screen shows a
   real outcome rather than always passing. */
require_once __DIR__ . '/../includes/demo-mode.php';
if (demo_owns($ref)) {
    $demoRow = demo_get_booking($ref);
    $owed    = (int) round((float) $demoRow['amountValue'] * 100);
    $claimed = ($_POST['amount_centavos'] ?? '') !== '' ? (int) $_POST['amount_centavos'] : null;
    if (($_POST['method'] ?? '') === 'cash') {
        demo_update_booking($ref, ['paymentCode' => 'await_cash', 'paymentStatus' => 'Payment due',
                                   'paymentStatusStaff' => 'Awaiting cash payment', 'method' => 'Cash']);
        ps_reply(200, ['ok' => true, 'demo' => true, 'status' => 'await_cash',
            'message' => 'Pay at the venue office — staff will record it. (Demo mode — nothing was saved.)']);
    }
    $amountOk = $claimed === null ? null : ($claimed === $owed);
    $verdict  = $amountOk === false ? 'manual_review' : 'accepted';
    demo_update_booking($ref, ['paymentCode' => 'under_review', 'paymentStatus' => 'Under review',
                               'paymentStatusStaff' => 'Receipt under review', 'receiptVerdict' => $verdict]);
    ps_reply(200, ['ok' => true, 'demo' => true, 'verdict' => $verdict,
        'flags' => $amountOk === false ? ['amount_mismatch'] : [], 'status' => 'under_review',
        'message' => 'Receipt received (demo mode — nothing was saved).']);
}

$id = booking_id_from_reference($ref);
if ($id === null) {
    ps_reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    ps_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your payment was not recorded.']);
}

/* The booking must be THIS customer's. Scoped by customer_id, not just by
   reference: otherwise anyone could pay against — or read the total of —
   someone else's booking by typing its number. */
$bk = $pdo->prepare(
    "SELECT b.id, b.customer_id, b.payment_status, b.payment_method, b.total_amount,
            v.name AS venue_name,
            g.account_name, g.mobile_number
       FROM bookings b
       JOIN rooms r  ON r.id = b.room_id
       JOIN venues v ON v.id = r.venue_id
       LEFT JOIN gcash_accounts g ON g.venue_id = v.id AND g.is_active = 1 AND g.valid_until IS NULL
      WHERE b.id = :b AND b.customer_id = :c"
);
$bk->execute([':b' => $id, ':c' => (int) $_SESSION['customer_id']]);
$booking = $bk->fetch();
if (!$booking) {
    ps_reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
}

/* Payment has to be OPEN. A post-pay booking sitting in await_event cannot be
   paid early, and a booking already confirmed must not be paid twice. */
if (!in_array($booking['payment_status'], ['await_gcash', 'await_cash', 'overdue'], true)) {
    $why = $booking['payment_status'] === 'await_event'
        ? 'This booking cannot be paid until after your event has finished.'
        : 'This booking is not currently awaiting a payment.';
    ps_reply(409, ['ok' => false, 'message' => $why]);
}

/* The customer may switch method at the payment step. */
if ($method !== $booking['payment_method']) {
    $pdo->prepare('UPDATE bookings SET payment_method = :m WHERE id = :b')
        ->execute([':m' => $method, ':b' => $id]);
}

/* ---------------------------------------------------------------------
   CASH — nothing to submit. The customer pays at the office and staff
   record it, which is what creates the payment row. Recording a payment
   here would claim money arrived that nobody has seen.
   --------------------------------------------------------------------- */
if ($method === 'cash') {
    $pdo->prepare("UPDATE bookings SET payment_status = 'await_cash' WHERE id = :b AND payment_status <> 'overdue'")
        ->execute([':b' => $id]);
    ps_reply(200, ['ok' => true, 'status' => 'await_cash',
        'message' => 'Pay at the venue office — staff will record it.']);
}

/* ---------------------------------------------------------------------
   GCASH
   --------------------------------------------------------------------- */
if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
    ps_reply(400, ['ok' => false, 'message' => 'Attach the GCash receipt screenshot.']);
}
if (!$booking['mobile_number']) {
    ps_reply(409, ['ok' => false,
        'message' => 'This venue has no GCash account configured, so it cannot take a GCash payment yet.']);
}

$claimRef      = preg_replace('/\D+/', '', (string) ($_POST['ref'] ?? ''));
$claimNumber   = (string) ($_POST['receiver_number'] ?? '');
$claimName     = (string) ($_POST['receiver_name'] ?? '');
$claimCentavos = ($_POST['amount_centavos'] ?? '') !== '' ? (int) $_POST['amount_centavos'] : null;
$claimWhen     = (string) ($_POST['receipt_datetime'] ?? '');
$confidence    = ($_POST['confidence'] ?? '') !== '' ? (float) $_POST['confidence'] : null;
$clientFlags   = json_decode((string) ($_POST['flags'] ?? '[]'), true);
if (!is_array($clientFlags)) { $clientFlags = []; }

if ($claimRef === '' || strlen($claimRef) < 10 || strlen($claimRef) > 16) {
    ps_reply(400, ['ok' => false,
        'message' => 'The reference number could not be read from that receipt. Try a clearer screenshot.']);
}

/* ---- the receiver check, against the account THIS venue is paid into ----
   A port of numberMatchesMasked() from includes/gcash-checker.php. GCash
   masks the middle digits ("0995 ••• 1234"), so this compares only the
   VISIBLE ones and refuses to judge on fewer than four. Returns true /
   false / null, where null means "no signal" — absence of data must never
   reject, which is this engine's oldest rule. */
function ps_number_matches($receiptNumber, $expected) {
    $exp = preg_replace('/\D+/', '', (string) $expected);
    if (strlen($exp) !== 11) {
        return null;                      // our own account is misconfigured; never fail a customer on it
    }
    $r = preg_replace('/[^0-9*]/', '', str_replace(['•', 'x', 'X', '#'], '*', (string) $receiptNumber));
    if (strlen($r) === 12 && substr($r, 0, 2) === '63') { $r = '0' . substr($r, 2); }
    if (strlen($r) === 10 && $r[0] === '9')             { $r = '0' . $r; }
    if (strlen($r) !== 11) {
        return null;
    }
    $visible = 0;
    for ($i = 0; $i < 11; $i++) {
        if ($r[$i] === '*') { continue; }
        if ($r[$i] !== $exp[$i]) { return false; }
        $visible++;
    }
    return $visible >= 4 ? true : null;
}

/* THE TWO CHECKS THAT MATTER, both against values read from the database. */
$owedCentavos = (int) round((float) $booking['total_amount'] * 100);
$amountOk     = $claimCentavos === null ? null : ($claimCentavos === $owedCentavos);
$receiverOk   = ps_number_matches($claimNumber, $booking['mobile_number']);

$flags = array_values(array_unique(array_filter($clientFlags, 'is_string')));
if ($amountOk === false)   { $flags[] = 'amount_mismatch'; }
if ($amountOk === null)    { $flags[] = 'amount_unreadable'; }
if ($receiverOk === false) { $flags[] = 'receiver_mismatch'; }
if ($receiverOk === null)  { $flags[] = 'receiver_unreadable'; }
if ($confidence !== null && $confidence < 40) { $flags[] = 'low_confidence'; }
$flags = array_values(array_unique($flags));

/* THE VERDICT (DB-DECISIONS #6), recomputed here rather than trusted.
   Reject only when there is nothing for a human to weigh — both fields
   POSITIVELY wrong. One field wrong, or merely unreadable, is a person's
   call: this matcher reads masked names off a phone screenshot, and a false
   mismatch is its classic failure. */
$bothWrong = ($amountOk === false && $receiverOk === false);
$bothRight = ($amountOk === true  && $receiverOk === true);
$verdict   = $bothWrong ? 'rejected' : (($bothRight && !$flags) ? 'accepted' : 'manual_review');

if ($verdict === 'rejected') {
    ps_reply(422, ['ok' => false, 'verdict' => 'rejected', 'flags' => $flags,
        'message' => 'That receipt is for a different amount AND a different account. Check you sent '
                   . '₱' . number_format($owedCentavos / 100, 2) . ' to ' . $booking['account_name'] . '.']);
}

/* Store the image, then hash the bytes that actually landed — not a hash the
   client calculated for us, which it could simply make up to dodge the
   duplicate-file gate. */
$docError = '';
$path = doc_store_raw('bookings/' . $id, 'gcash_receipt', $_FILES['receipt']['tmp_name'], $_FILES['receipt']['name'], $docError);
if ($path === null) {
    ps_reply(400, ['ok' => false, 'message' => $docError ?: 'The receipt could not be saved.']);
}
$sha = hash_file('sha256', doc_path($path));

try {
    $pdo->beginTransaction();

    $attempt = (int) $pdo->query('SELECT COALESCE(MAX(attempt_no), 0) + 1 FROM gcash_receipts WHERE booking_id = ' . (int) $id)->fetchColumn();

    $pay = $pdo->prepare(
        "INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at)
         VALUES (:b, 'gcash', :a, 'under_review', NOW())"
    );
    $pay->execute([':b' => $id, ':a' => $booking['total_amount']]);
    $paymentId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO gcash_receipts
            (payment_id, booking_id, attempt_no, file_path, original_filename, reference_number,
             receiver_name, receiver_number, amount_centavos, receipt_datetime,
             sha256_hash, ocr_confidence, verdict, flags_json, ocr_text)
         VALUES (:p, :b, :n, :f, :o, :r, :rn, :rnum, :amt, :when, :sha, :conf, :v, :flags, :txt)'
    )->execute([
        ':p' => $paymentId, ':b' => $id, ':n' => $attempt, ':f' => $path,
        ':o' => mb_substr((string) $_FILES['receipt']['name'], 0, 255),
        ':r' => $claimRef, ':rn' => mb_substr($claimName, 0, 150), ':rnum' => mb_substr($claimNumber, 0, 30),
        ':amt' => $claimCentavos, ':when' => $claimWhen !== '' ? date('Y-m-d H:i:s', strtotime($claimWhen)) : null,
        ':sha' => $sha, ':conf' => $confidence, ':v' => $verdict,
        ':flags' => json_encode($flags),
        ':txt' => mb_substr((string) ($_POST['text_excerpt'] ?? ''), 0, 2000),
    ]);

    /* under_review either way. An 'accepted' scan means "nothing for a human to
       query", not "paid" — staff still match the reference in GCash. */
    $pdo->prepare("UPDATE bookings SET payment_status = 'under_review' WHERE id = :b")->execute([':b' => $id]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    @unlink(doc_path($path));
    /* 23000 = a UNIQUE violation, which here can only be uq_gcash_reference or
       uq_gcash_file_hash. These are the strongest anti-reuse gates in the
       system and they hold across every booking, not just this one. */
    if ($e->getCode() === '23000') {
        $dupRef = strpos($e->getMessage(), 'uq_gcash_reference') !== false;
        ps_reply(409, ['ok' => false, 'verdict' => 'rejected',
            'message' => $dupRef
                ? 'That GCash reference number has already been used for a booking.'
                : 'That exact receipt image has already been submitted before.']);
    }
    ps_reply(500, ['ok' => false, 'message' => 'Something went wrong, so your payment was not recorded.']);
}

ps_reply(200, [
    'ok'      => true,
    'verdict' => $verdict,
    'flags'   => $flags,
    'status'  => 'under_review',
    'message' => $verdict === 'accepted'
        ? 'Receipt received and checked. Staff will confirm it shortly.'
        : 'Receipt received. Staff will review it — this usually just means the screenshot was hard to read.',
]);
