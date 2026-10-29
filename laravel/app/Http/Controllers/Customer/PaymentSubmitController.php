<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use PDO;
use PDOException;

/* =====================================================================
   PAYMENT SUBMIT (was customer/payment-submit.php) — the ONLY way a
   customer records a payment.

   POST (multipart for GCash)
     _token, booking=<VB-reference>, method=gcash|cash|cancel_cash
     gcash: receipt FILE, plus what the browser's OCR read —
            ref, receiver_name, receiver_number, amount_centavos,
            receipt_datetime, confidence, flags (json), text_excerpt
   Replies with JSON (same shapes and statuses as the old endpoint).

   The OCR runs in the BROWSER, so the parsed fields are a claim, not
   evidence. What this guarantees: the VERDICT is computed here (amount vs
   the booking's total_amount, receiver vs the venue's gcash_accounts row);
   the file hash is computed here from the stored bytes; duplicates are
   enforced by the database (uq_gcash_active_reference / _file); every
   counted upload is recorded with its attempt number (DB-DECISIONS #6);
   nothing here confirms a payment — the best outcome is 'under_review'.

   CSRF: Laravel's middleware checks _token; an expired page gets the old
   400 JSON from the 419 handler in bootstrap/app.php.
   ===================================================================== */
class PaymentSubmitController
{
    private static function reply(int $status, array $data, array $headers = [])
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'] + $headers, 0);
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'db', 'documents', 'bookings', 'demo-mode');

        if (!$request->isMethod('post')) {
            return self::reply(405, ['ok' => false, 'message' => 'Use POST.'], ['Allow' => 'POST']);
        }
        if (!customer_logged_in()) {
            return self::reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }

        $ref        = (string) $request->input('booking', '');
        $postMethod = (string) $request->input('method', '');
        $cancelCash = $postMethod === 'cancel_cash';
        $method     = $postMethod === 'cash' ? 'cash' : 'gcash';

        /* DEMO MODE — a payment against a booking that only exists in the session.
           Nothing is stored, no reference consumed, no UNIQUE index touched. The
           verdict still reflects what was actually sent. */
        if (demo_owns($ref)) {
            $demoRow = demo_get_booking($ref);
            $owed    = (int) round((float) $demoRow['amountValue'] * 100);
            $claimed = (string) $request->input('amount_centavos', '') !== '' ? (int) $request->input('amount_centavos') : null;
            $demoPtu = !empty($demoRow['payToUse']);
            if ($cancelCash) {
                if ($demoRow['paymentCode'] !== 'await_cash') {
                    return self::reply(409, ['ok' => false, 'message' => 'There is no cash payment to cancel.']);
                }
                demo_update_booking($ref, ['paymentCode' => 'await_gcash', 'paymentStatus' => 'Payment due',
                                           'paymentStatusStaff' => 'Awaiting GCash payment', 'method' => 'GCash']);
                return self::reply(200, ['ok' => true, 'demo' => true, 'status' => 'await_gcash',
                    'message' => 'Cash choice cancelled. (Demo mode — nothing was saved.)']);
            }
            if ($demoRow['paymentCode'] === 'await_cash' && $postMethod !== 'cash') {
                return self::reply(409, ['ok' => false, 'message' => 'You chose to pay in cash ' . ($demoPtu ? 'at the front desk' : 'at the office') . '. Cancel that first to pay by GCash.']);
            }
            if ($postMethod === 'cash') {
                demo_update_booking($ref, ['paymentCode' => 'await_cash', 'paymentStatus' => 'Payment due',
                                           'paymentStatusStaff' => 'Awaiting cash payment', 'method' => 'Cash']);
                return self::reply(200, ['ok' => true, 'demo' => true, 'status' => 'await_cash',
                    'message' => 'Pay at the venue office — staff will record it. (Demo mode — nothing was saved.)']);
            }
            $amountOk = $claimed === null ? null : ($claimed === $owed);
            $verdict  = $amountOk === false ? 'manual_review' : 'accepted';
            demo_update_booking($ref, ['paymentCode' => 'under_review', 'paymentStatus' => 'Under review',
                                       'paymentStatusStaff' => 'Receipt under review', 'receiptVerdict' => $verdict]);
            return self::reply(200, ['ok' => true, 'demo' => true, 'verdict' => $verdict,
                'flags' => $amountOk === false ? ['amount_mismatch'] : [], 'status' => 'under_review',
                'message' => 'Receipt received (demo mode — nothing was saved).']);
        }
        /* Demo mode is on but this is a REAL booking: paying it would be written for real. */
        if (demo_mode_on()) {
            return self::reply(409, ['ok' => false,
                'message' => 'Demo mode is on, so payments on real bookings are not recorded. Ask an administrator to turn demo mode off.']);
        }

        $id = booking_id_from_reference($ref);
        if ($id === null) {
            return self::reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return self::reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your payment was not recorded.']);
        }

        /* A deadline that passed while the page sat open is applied before the
           booking is read, so payment is refused for a hold that has lapsed. */
        venusep_expire_due_bookings();

        /* The booking must be THIS customer's — scoped by customer_id. */
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
        $bk->execute([':b' => $id, ':c' => (int) session('customer_id')]);
        $booking = $bk->fetch();
        if (!$booking) {
            return self::reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
        }

        /* THE CASH CHOICE (2026-10-28): take back "I will pay in cash" while the
           cash is still unpaid. A hostel stay goes back to "no method chosen"; a
           venue booking to GCash. */
        if ($cancelCash) {
            if ($booking['payment_status'] !== 'await_cash') {
                return self::reply(409, ['ok' => false, 'message' => $booking['payment_status'] === 'paid_cash'
                    ? 'Staff have already recorded your cash payment, so it cannot be cancelled.'
                    : 'There is no cash payment to cancel.']);
            }
            $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
                ->execute([':a' => (int) session('user_id'), ':n' => $booking['pay_to_use']
                    ? 'Guest cancelled paying cash at the front desk; GCash is open again.'
                    : 'Customer cancelled paying cash at the office; GCash is open again.']);
            $pdo->prepare("UPDATE bookings SET payment_status = 'await_gcash', payment_method = IF(pay_to_use, NULL, 'gcash')
                            WHERE id = :b AND payment_status = 'await_cash'")->execute([':b' => $id]);
            $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
            return self::reply(200, ['ok' => true, 'status' => 'await_gcash', 'message' => 'Cash choice cancelled. You can pay by GCash again.']);
        }
        /* ...and while that choice stands, GCash stays closed: one way to pay at a time. */
        if ($booking['payment_status'] === 'await_cash' && $method === 'gcash') {
            return self::reply(409, ['ok' => false, 'message' => 'You chose to pay in cash ' . ($booking['pay_to_use'] ? 'at the front desk' : 'at the office')
                . '. Cancel that first to pay by GCash.']);
        }

        /* Payment has to be OPEN. */
        if (!in_array($booking['payment_status'], ['await_gcash', 'await_cash', 'overdue'], true)) {
            $why = $booking['payment_status'] === 'await_event'
                ? 'This booking cannot be paid until after your event has finished.'
                : 'This booking is not currently awaiting a payment.';
            return self::reply(409, ['ok' => false, 'message' => $why]);
        }

        /* The customer may switch method at the payment step. */
        if ($method !== $booking['payment_method']) {
            $pdo->prepare('UPDATE bookings SET payment_method = :m WHERE id = :b')
                ->execute([':m' => $method, ':b' => $id]);
        }

        /* CASH — nothing to submit. The customer pays at the office and staff
           record it, which is what creates the payment row. */
        if ($method === 'cash') {
            $pdo->prepare('SET @venusep_actor_user_id = :a, @venusep_action_note = :n')
                ->execute([':a' => (int) session('user_id'),
                           ':n' => $booking['pay_to_use'] ? 'Guest will pay cash at the front desk.' : 'Customer will pay in cash.']);
            $pdo->prepare("UPDATE bookings SET payment_status = 'await_cash' WHERE id = :b AND payment_status <> 'overdue'")
                ->execute([':b' => $id]);
            $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
            return self::reply(200, ['ok' => true, 'status' => 'await_cash',
                'message' => $booking['pay_to_use'] ? 'Pay at the hostel front desk — staff will record it.' : 'Pay at the venue office — staff will record it.']);
        }

        /* ---- GCASH ---- */
        $file = $request->file('receipt');
        if (!$file || is_array($file) || !$file->isValid()) {
            return self::reply(400, ['ok' => false, 'message' => 'Attach the GCash receipt screenshot.']);
        }
        if (!$booking['mobile_number']) {
            return self::reply(409, ['ok' => false,
                'message' => 'This venue has no GCash account configured, so it cannot take a GCash payment yet.']);
        }

        $claimRef      = preg_replace('/\D+/', '', (string) $request->input('ref', ''));
        $claimNumber   = (string) $request->input('receiver_number', '');
        $claimName     = (string) $request->input('receiver_name', '');
        $claimCentavos = (string) $request->input('amount_centavos', '') !== '' ? (int) $request->input('amount_centavos') : null;
        /* The browser's OCR date text; one PHP cannot parse is "no date", not 1970. */
        $claimWhenTs   = strtotime((string) $request->input('receipt_datetime', ''));
        $claimWhen     = $claimWhenTs === false ? null : date('Y-m-d H:i:s', $claimWhenTs);
        $confidence    = (string) $request->input('confidence', '') !== '' ? (float) $request->input('confidence') : null;
        $clientFlags   = json_decode((string) $request->input('flags', '[]'), true);
        if (!is_array($clientFlags)) { $clientFlags = []; }

        if ($claimRef === '' || strlen($claimRef) < 10 || strlen($claimRef) > 16) {
            return self::reply(400, ['ok' => false,
                'message' => 'The reference number could not be read from that receipt. Try a clearer screenshot.']);
        }

        /* THE TWO CHECKS THAT MATTER, both against values read from the database. */
        $owedCentavos = (int) round((float) $booking['total_amount'] * 100);
        $amountOk     = $claimCentavos === null ? null : ($claimCentavos === $owedCentavos);
        $receiverOk   = self::numberMatches($claimNumber, $booking['mobile_number']);

        $flags = array_values(array_unique(array_filter($clientFlags, 'is_string')));
        if ($amountOk === false)   { $flags[] = 'amount_mismatch'; }
        if ($amountOk === null)    { $flags[] = 'amount_unreadable'; }
        if ($receiverOk === false) { $flags[] = 'receiver_mismatch'; }
        if ($receiverOk === null)  { $flags[] = 'receiver_unreadable'; }
        if ($confidence !== null && $confidence < 40) { $flags[] = 'low_confidence'; }
        $flags = array_values(array_unique($flags));

        /* THE VERDICT (DB-DECISIONS #6), recomputed here rather than trusted.
           Reject only when both fields are POSITIVELY wrong, or a duplicate. */
        $bothWrong = ($amountOk === false && $receiverOk === false);
        $bothRight = ($amountOk === true  && $receiverOk === true);

        /* Store the image FIRST, then hash the bytes that actually landed. */
        $docError = '';
        $path = doc_store_raw('bookings/' . $id, 'gcash_receipt', $file->getPathname(), $file->getClientOriginalName(), $docError);
        if ($path === null) {
            return self::reply(400, ['ok' => false, 'message' => $docError ?: 'The receipt could not be saved.']);
        }
        $sha = hash_file('sha256', doc_path($path));

        /* RECORD THE ATTEMPT — every one, whatever its verdict. */
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
                return self::reply(409, ['ok' => false, 'message' => 'This booking is not currently awaiting a payment.']);
            }
            $allowed = (int) $locked['receipt_attempts_allowed'];
            $usedQ = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(attempt_no), 0) FROM gcash_receipts WHERE booking_id = :b');
            $usedQ->execute([':b' => $id]);
            [$used, $lastNo] = array_map('intval', $usedQ->fetch(PDO::FETCH_NUM));

            /* THE LIMIT. One more upload is refused outright and recorded nowhere. */
            if ($used >= $allowed) {
                $pdo->rollBack();
                @unlink(doc_path($path));
                return self::reply(409, ['ok' => false, 'limit' => true, 'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
                    'message' => 'You have used all ' . $allowed . ' receipt attempts for this booking. Staff will review your '
                               . 'payment and contact you, or you can pay in cash at the office.']);
            }

            /* THIS BOOKING'S OWN EARLIER RECEIPT, sent again: refused, NOT counted. */
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
                return self::reply(409, ['ok' => false, 'verdict' => 'rejected', 'repeat' => true,
                    'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
                    'message' => ($again['same_file'] ? 'You already uploaded this exact image' : 'You already uploaded a receipt with this reference number')
                               . ' (attempt ' . (int) $again['attempt_no'] . ', ' . booking_receipt_outcome($again) . '). '
                               . 'The same receipt cannot be accepted again, so this upload was not counted — you still have '
                               . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left. Please upload a different receipt.']);
            }

            /* DUPLICATES — against every receipt that was not rejected, on ANY booking. */
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

            /* The LAST allowed attempt goes to staff whatever its verdict. */
            $isLast = ($used + 1 >= $allowed);
            if ($isLast) { $flags[] = 'attempt_limit_reached'; }
            $flags = array_values(array_unique($flags));

            /* A payment row only for a receipt that is going to staff as a payment. */
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
                ':o' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                ':r' => $claimRef, ':rn' => mb_substr($claimName, 0, 150), ':rnum' => mb_substr($claimNumber, 0, 30),
                ':amt' => $claimCentavos, ':when' => $claimWhen,
                ':sha' => $sha, ':conf' => $confidence, ':v' => $verdict,
                ':flags' => json_encode($flags),
                ':txt' => mb_substr((string) $request->input('text_excerpt', ''), 0, 2000),
            ]);

            /* under_review for anything staff must look at; a rejected attempt with
               tries left changes nothing. */
            if ($verdict !== 'rejected' || $isLast) {
                /* The CUSTOMER did this — named for the booking timeline. */
                $pdo->prepare('SET @venusep_actor_user_id = :u')->execute([':u' => (int) session('user_id')]);
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
            /* 23000 = a UNIQUE violation: another upload of the same receipt landed
               between the check above and this insert. Nothing was recorded. */
            if ($e->getCode() === '23000') {
                $dupRefRace = strpos($e->getMessage(), 'uq_gcash_active_reference') !== false;
                return self::reply(409, ['ok' => false, 'verdict' => 'rejected',
                    'message' => $dupRefRace
                        ? 'That GCash reference number has already been used for a booking.'
                        : 'That exact receipt image has already been submitted before.']);
            }
            error_log('VENUSeP payment-submit: ' . $e->getMessage());
            return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so your payment was not recorded.']);
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
                /* Recorded, and the booking is with staff now — a success for the page. */
                return self::reply(200, ['ok' => true, 'verdict' => 'rejected', 'flags' => $flags, 'status' => 'under_review']
                    + $attemptInfo + ['message' => $why . ' That was your last attempt, so staff will now review your payment.']);
            }
            $left = $allowed - ($used + 1);
            return self::reply(422, ['ok' => false, 'verdict' => 'rejected', 'flags' => $flags] + $attemptInfo
                + ['message' => $why . ' ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.']);
        }

        return self::reply(200, [
            'ok'      => true,
            'verdict' => $verdict,
            'flags'   => $flags,
            'status'  => 'under_review',
            'message' => $verdict === 'accepted'
                ? 'Receipt received and checked. Staff will confirm it shortly.'
                : 'Receipt received. Staff will review it — this usually just means the screenshot was hard to read.',
        ] + $attemptInfo);
    }

    /* The receiver check, against the account THIS venue is paid into (old
       ps_number_matches(), a port of numberMatchesMasked() in gcash-checker.php).
       GCash masks the middle digits, so this compares only the VISIBLE ones and
       refuses to judge on fewer than four. true / false / null ("no signal" —
       absence of data must never reject). */
    private static function numberMatches($receiptNumber, $expected)
    {
        $exp = preg_replace('/\D+/', '', (string) $expected);
        if (strlen($exp) !== 11) {
            return null;                      // our own account is misconfigured; never fail a customer on it
        }
        /* The NATIONAL part (after +63 / 63 / 0), ten characters. Every non-digit
           is a hidden digit (OCR reads the masking dots as letters). */
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
        /* Dots merged or split by OCR: judge the visible ENDS. */
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
}
