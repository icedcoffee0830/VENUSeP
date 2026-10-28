<?php
/* =====================================================================
   PAYMENT SUBMIT — the ONLY way a customer records a payment.

   POST (multipart for GCash)
     csrf, booking=<VB-reference>, method=gcash|cash|cancel_cash
     cancel_cash (a pay-to-use hostel stay, 2026-10-28): take back "I will
            pay in cash at the front desk" — GCash opens again, and staff no
            longer see Cash received
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
     3. DUPLICATES ARE ENFORCED BY THE DATABASE. uq_gcash_active_reference
        and uq_gcash_active_file are UNIQUE across ALL bookings, forever,
        for every receipt that was not rejected — the old localStorage store
        was per-browser and forgot everything the moment you switched
        machines.
     5. EVERY UPLOAD IS RECORDED (2026-10-26), rejected ones included, with
        its attempt number. A booking gets receipt_attempts_allowed uploads
        (5); the last one goes to staff whatever its verdict, and one more is
        refused. An upload whose reference number cannot be read at all is
        NOT counted — nothing was checked, it is usually a blurry screenshot.
        Nor is one of this booking's OWN earlier receipts sent again: it is
        refused with what became of it, and the attempt is kept.
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
$cancelCash = ($_POST['method'] ?? '') === 'cancel_cash';
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
    $demoPtu = !empty($demoRow['payToUse']);
    if ($cancelCash) {
        if ($demoRow['paymentCode'] !== 'await_cash') {
            ps_reply(409, ['ok' => false, 'message' => 'There is no cash payment to cancel.']);
        }
        demo_update_booking($ref, ['paymentCode' => 'await_gcash', 'paymentStatus' => 'Payment due',
                                   'paymentStatusStaff' => 'Awaiting GCash payment', 'method' => 'GCash']);
        ps_reply(200, ['ok' => true, 'demo' => true, 'status' => 'await_gcash',
            'message' => 'Cash choice cancelled. (Demo mode — nothing was saved.)']);
    }
    if ($demoRow['paymentCode'] === 'await_cash' && ($_POST['method'] ?? '') !== 'cash') {
        ps_reply(409, ['ok' => false, 'message' => 'You chose to pay in cash ' . ($demoPtu ? 'at the front desk' : 'at the office') . '. Cancel that first to pay by GCash.']);
    }
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
/* Demo mode is on but this is a REAL booking. Paying it would be written for
   real — exactly what demo mode promises never happens. The booking pages never
   reached real bookings, but customer/pay.php ("Pay now") does. */
if (demo_mode_on()) {
    ps_reply(409, ['ok' => false,
        'message' => 'Demo mode is on, so payments on real bookings are not recorded. Ask an administrator to turn demo mode off.']);
}

$id = booking_id_from_reference($ref);
if ($id === null) {
    ps_reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    ps_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your payment was not recorded.']);
}

/* A deadline that passed while the page sat open is applied before the
   booking is read, so payment is refused for a hold that has lapsed. */
venusep_expire_due_bookings();

/* The booking must be THIS customer's. Scoped by customer_id, not just by
   reference: otherwise anyone could pay against — or read the total of —
   someone else's booking by typing its number. */
$bk = $pdo->prepare(
    "SELECT b.id, b.customer_id, b.payment_status, b.payment_method, b.total_amount, b.pay_to_use,
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

/* THE CASH CHOICE (2026-10-28): a customer who said "I will pay in cash" — at
   the hostel front desk or the venue cashier — may take it back while the cash
   is still unpaid. GCash opens again, and staff stop seeing their cash button
   (booking-action.php only takes cash from someone who chose it). A hostel
   stay goes back to "no method chosen"; a venue booking to GCash. */
if ($cancelCash) {
    if ($booking['payment_status'] !== 'await_cash') {
        ps_reply(409, ['ok' => false, 'message' => $booking['payment_status'] === 'paid_cash'
            ? 'Staff have already recorded your cash payment, so it cannot be cancelled.'
            : 'There is no cash payment to cancel.']);
    }
    $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
        ->execute([':a' => (int) $_SESSION['user_id'], ':n' => $booking['pay_to_use']
            ? 'Guest cancelled paying cash at the front desk; GCash is open again.'
            : 'Customer cancelled paying cash at the office; GCash is open again.']);
    $pdo->prepare("UPDATE bookings SET payment_status = 'await_gcash', payment_method = IF(pay_to_use, NULL, 'gcash')
                    WHERE id = :b AND payment_status = 'await_cash'")->execute([':b' => $id]);
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    ps_reply(200, ['ok' => true, 'status' => 'await_gcash', 'message' => 'Cash choice cancelled. You can pay by GCash again.']);
}
/* ...and while that choice stands, GCash stays closed: one way to pay at a time. */
if ($booking['payment_status'] === 'await_cash' && $method === 'gcash') {
    ps_reply(409, ['ok' => false, 'message' => 'You chose to pay in cash ' . ($booking['pay_to_use'] ? 'at the front desk' : 'at the office')
        . '. Cancel that first to pay by GCash.']);
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
    $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
        ->execute([':a' => (int) $_SESSION['user_id'],
                   ':n' => $booking['pay_to_use'] ? 'Guest will pay cash at the front desk.' : 'Customer will pay in cash.']);
    $pdo->prepare("UPDATE bookings SET payment_status = 'await_cash' WHERE id = :b AND payment_status <> 'overdue'")
        ->execute([':b' => $id]);
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    ps_reply(200, ['ok' => true, 'status' => 'await_cash',
        'message' => $booking['pay_to_use'] ? 'Pay at the hostel front desk — staff will record it.' : 'Pay at the venue office — staff will record it.']);
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
    /* The NATIONAL part (after +63 / 63 / 0), ten characters. Every non-digit
       is a hidden digit: OCR reads GCash's masking dots as letters
       ("+63 9•••••2358" -> "+63 Deceee2358"). mb_ functions, because a "•"
       is one character but three bytes. */
    $s = preg_replace('/[\s\-]+/u', '', (string) $receiptNumber);
    if (preg_match('/^\+?63/', $s)) { $s = preg_replace('/^\+?63/', '', $s); }
    elseif (mb_substr($s, 0, 1) === '0') { $s = mb_substr($s, 1); }
    $r   = preg_replace('/[^0-9]/u', '*', $s);
    $nat = substr($exp, 1);
    if (strlen($r) === 10) {
        $visible = 0;
        for ($i = 0; $i < 10; $i++) {
            if ($r[$i] === '*') { continue; }
            if ($r[$i] !== $nat[$i]) { return false; }
            $visible++;
        }
        return $visible >= 4 ? true : null;
    }
    /* Dots merged or split by OCR: positions are lost, so judge the visible
       ENDS — the leading and trailing digit runs. */
    preg_match('/^\d*/', $r, $lm);
    preg_match('/\d*$/', $r, $tm);
    $lead = $lm[0];
    $tail = $tm[0];
    if ($lead === $r) {
        return null;                      // all digits, wrong length: a misread, not a mismatch
    }
    if ($lead !== '' && strpos($nat, $lead) !== 0) { return false; }
    if ($tail !== '' && substr($nat, -strlen($tail)) !== $tail) { return false; }
    return (strlen($lead) + strlen($tail)) >= 4 ? true : null;
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
   POSITIVELY wrong, or a duplicate (decided below, against the database).
   One field wrong, or merely unreadable, is a person's call: this matcher
   reads masked names off a phone screenshot, and a false mismatch is its
   classic failure. */
$bothWrong = ($amountOk === false && $receiverOk === false);
$bothRight = ($amountOk === true  && $receiverOk === true);

/* Store the image FIRST, then hash the bytes that actually landed — not a hash
   the client calculated for us, which it could simply make up to dodge the
   duplicate-file gate. Every counted upload keeps its image, rejected ones
   included: that is the evidence staff see in the upload history. */
$docError = '';
$path = doc_store_raw('bookings/' . $id, 'gcash_receipt', $_FILES['receipt']['tmp_name'], $_FILES['receipt']['name'], $docError);
if ($path === null) {
    ps_reply(400, ['ok' => false, 'message' => $docError ?: 'The receipt could not be saved.']);
}
$sha = hash_file('sha256', doc_path($path));

/* ---------------------------------------------------------------------
   RECORD THE ATTEMPT — every one, whatever its verdict (DB-DECISIONS #6,
   2026-10-26). The 5-attempt rule counts them all, and staff see them all.
   --------------------------------------------------------------------- */
try {
    $pdo->beginTransaction();

    /* Lock this booking's row: two uploads at the same moment must not both be
       counted as "attempt 5", nor both slip past a closed payment. */
    $lk = $pdo->prepare('SELECT payment_status, receipt_attempts_allowed FROM bookings WHERE id = :b FOR UPDATE');
    $lk->execute([':b' => $id]);
    $locked = $lk->fetch();
    if (!$locked || !in_array($locked['payment_status'], ['await_gcash', 'await_cash', 'overdue'], true)) {
        $pdo->rollBack();
        @unlink(doc_path($path));
        ps_reply(409, ['ok' => false, 'message' => 'This booking is not currently awaiting a payment.']);
    }
    $allowed = (int) $locked['receipt_attempts_allowed'];
    $usedQ = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(attempt_no), 0) FROM gcash_receipts WHERE booking_id = :b');
    $usedQ->execute([':b' => $id]);
    [$used, $lastNo] = array_map('intval', $usedQ->fetch(PDO::FETCH_NUM));

    /* THE LIMIT. A 6th upload is refused outright and recorded nowhere — the
       5th already went to staff, and only staff can open more attempts. */
    if ($used >= $allowed) {
        $pdo->rollBack();
        @unlink(doc_path($path));
        ps_reply(409, ['ok' => false, 'limit' => true, 'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
            'message' => 'You have used all ' . $allowed . ' receipt attempts for this booking. Staff will review your '
                       . 'payment and contact you, or you can pay in cash at the office.']);
    }

    /* THIS BOOKING'S OWN EARLIER RECEIPT, sent again (2026-10-26). Refused, but
       NOT counted and recorded nowhere: nothing new was checked, and an attempt
       spent re-sending a receipt staff already turned down is an attempt lost
       for nothing. The customer is told which attempt it was and what became
       of it. customer/pay.php warns before it is even sent; this is the word
       that counts. A receipt from ANOTHER booking still falls through to the
       duplicate gates below and counts — that is the reuse they exist for. */
    $againQ = $pdo->prepare(
        'SELECT attempt_no, verdict, flags_json, override_note, sha256_hash = :sha AS same_file
           FROM gcash_receipts
          WHERE booking_id = :b AND (sha256_hash = :sha2 OR reference_number = :r)
          ORDER BY id DESC LIMIT 1'
    );
    $againQ->execute([':sha' => $sha, ':b' => $id, ':sha2' => $sha, ':r' => $claimRef]);
    $again = $againQ->fetch();
    if ($again) {
        $pdo->rollBack();
        @unlink(doc_path($path));
        $againFlags = json_decode((string) $again['flags_json'], true);
        $again['flags'] = is_array($againFlags) ? $againFlags : [];
        $left = $allowed - $used;
        ps_reply(409, ['ok' => false, 'verdict' => 'rejected', 'repeat' => true,
            'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
            'message' => ($again['same_file'] ? 'You already uploaded this exact image' : 'You already uploaded a receipt with this reference number')
                       . ' (attempt ' . (int) $again['attempt_no'] . ', ' . booking_receipt_outcome($again) . '). '
                       . 'The same receipt cannot be accepted again, so this upload was not counted — you still have '
                       . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left. Please upload a different receipt.']);
    }

    /* DUPLICATES — against every receipt that was not rejected, on ANY booking.
       Checked here so the duplicate can be recorded as a rejected attempt; the
       UNIQUE indexes on the active_* columns stay as the last word if two
       uploads race (caught below). */
    $dupQ = $pdo->prepare('SELECT 1 FROM gcash_receipts WHERE active_reference = :v LIMIT 1');
    $dupQ->execute([':v' => $claimRef]);
    $dupRef = $dupQ->fetchColumn() !== false;
    $dupQ = $pdo->prepare('SELECT 1 FROM gcash_receipts WHERE active_sha256 = :v LIMIT 1');
    $dupQ->execute([':v' => $sha]);
    $dupFile = $dupQ->fetchColumn() !== false;
    if ($dupRef)  { $flags[] = 'duplicate_ref'; }
    if ($dupFile) { $flags[] = 'duplicate_file'; }

    $verdict = ($dupRef || $dupFile || $bothWrong) ? 'rejected'
             : (($bothRight && !$flags) ? 'accepted' : 'manual_review');

    /* The LAST allowed attempt goes to staff whatever its verdict (decided
       2026-10-26) — flagged so staff know there is no next try. Added after the
       verdict, so a perfect receipt on attempt 5 still reads 'accepted'. */
    $isLast = ($used + 1 >= $allowed);
    if ($isLast) { $flags[] = 'attempt_limit_reached'; }
    $flags = array_values(array_unique($flags));

    /* A payment row only for a receipt that is going to staff as a payment. A
       rejected upload claims no money, so it has none (payment_id NULL). */
    $paymentId = null;
    if ($verdict !== 'rejected') {
        $pdo->prepare(
            "INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at)
             VALUES (:b, 'gcash', :a, 'under_review', NOW())"
        )->execute([':b' => $id, ':a' => $booking['total_amount']]);
        $paymentId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare(
        'INSERT INTO gcash_receipts
            (payment_id, booking_id, attempt_no, file_path, original_filename, reference_number,
             receiver_name, receiver_number, amount_centavos, receipt_datetime,
             sha256_hash, ocr_confidence, verdict, flags_json, ocr_text)
         VALUES (:p, :b, :n, :f, :o, :r, :rn, :rnum, :amt, :when, :sha, :conf, :v, :flags, :txt)'
    )->execute([
        ':p' => $paymentId, ':b' => $id, ':n' => $lastNo + 1, ':f' => $path,
        ':o' => mb_substr((string) $_FILES['receipt']['name'], 0, 255),
        ':r' => $claimRef, ':rn' => mb_substr($claimName, 0, 150), ':rnum' => mb_substr($claimNumber, 0, 30),
        ':amt' => $claimCentavos, ':when' => $claimWhen !== '' ? date('Y-m-d H:i:s', strtotime($claimWhen)) : null,
        ':sha' => $sha, ':conf' => $confidence, ':v' => $verdict,
        ':flags' => json_encode($flags),
        ':txt' => mb_substr((string) ($_POST['text_excerpt'] ?? ''), 0, 2000),
    ]);

    /* under_review for anything staff must look at: a received receipt (an
       'accepted' scan means "nothing for a human to query", not "paid" — staff
       still match the reference in GCash), or the last attempt, rejected or not.
       A rejected attempt with tries left changes nothing: payment stays open. */
    if ($verdict !== 'rejected' || $isLast) {
        /* The CUSTOMER did this. Without naming them, the timeline falls back to
           whoever last updated the booking — often the staff member who had just
           rejected the previous receipt. */
        $pdo->prepare('SET @venusep_actor_user_id = :u')->execute([':u' => (int) $_SESSION['user_id']]);
        $pdo->prepare('SET @venusep_action_note = :n')->execute([':n' => $isLast
            ? 'Receipt attempt ' . ($used + 1) . ' of ' . $allowed . ' — the last allowed; sent to staff for manual review.'
            : null]);
        $pdo->prepare("UPDATE bookings SET payment_status = 'under_review' WHERE id = :b")->execute([':b' => $id]);
        $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    }
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
    @unlink(doc_path($path));
    /* 23000 here = a UNIQUE violation on uq_gcash_active_reference or
       uq_gcash_active_file: another upload of the same receipt landed in the
       instant between the check above and this insert. Nothing was recorded. */
    if ($e->getCode() === '23000') {
        $dupRefRace = strpos($e->getMessage(), 'uq_gcash_active_reference') !== false;
        ps_reply(409, ['ok' => false, 'verdict' => 'rejected',
            'message' => $dupRefRace
                ? 'That GCash reference number has already been used for a booking.'
                : 'That exact receipt image has already been submitted before.']);
    }
    error_log('VENUSeP payment-submit: ' . $e->getMessage());
    ps_reply(500, ['ok' => false, 'message' => 'Something went wrong, so your payment was not recorded.']);
}

$attemptInfo = ['attemptsUsed' => $used + 1, 'attemptsAllowed' => $allowed, 'limit' => $isLast];

if ($verdict === 'rejected') {
    $why = $dupRef
        ? 'That GCash reference number has already been used for a booking.'
        : ($dupFile
            ? 'That exact receipt image has already been submitted before.'
            : 'That receipt is for a different amount AND a different account. Check you sent '
              . '₱' . number_format($owedCentavos / 100, 2) . ' to ' . $booking['account_name'] . '.');
    if ($isLast) {
        /* Recorded, and the booking is with staff now — that is a success for the
           page to move on from, even though this receipt was not accepted. */
        ps_reply(200, ['ok' => true, 'verdict' => 'rejected', 'flags' => $flags, 'status' => 'under_review']
            + $attemptInfo + ['message' => $why . ' That was your last attempt, so staff will now review your payment.']);
    }
    $left = $allowed - ($used + 1);
    ps_reply(422, ['ok' => false, 'verdict' => 'rejected', 'flags' => $flags] + $attemptInfo
        + ['message' => $why . ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.']);
}

ps_reply(200, [
    'ok'      => true,
    'verdict' => $verdict,
    'flags'   => $flags,
    'status'  => 'under_review',
    'message' => $verdict === 'accepted'
        ? 'Receipt received and checked. Staff will confirm it shortly.'
        : 'Receipt received. Staff will review it — this usually just means the screenshot was hard to read.',
] + $attemptInfo);
