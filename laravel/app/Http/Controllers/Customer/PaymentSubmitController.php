<?php

namespace App\Http\Controllers\Customer;

use App\Queries\PaymentQueries;
use App\Support\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        legacy('auth', 'db', 'documents', 'bookings');

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

        $id = booking_id_from_reference($ref);
        if ($id === null) {
            return self::reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
        }

        if (venusep_db() === null) {
            return self::reply(503, ['ok' => false, 'message' => 'The database is unreachable, so your payment was not recorded.']);
        }

        /* A deadline that passed while the page sat open is applied before the
           booking is read, so payment is refused for a hold that has lapsed. */
        venusep_expire_due_bookings();

        /* The booking must be THIS customer's — scoped by customer_id. */
        $booking = PaymentQueries::findForCustomer($id, (int) session('customer_id'));
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
            PaymentQueries::cancelCashChoice($id, (int) session('user_id'), $booking['pay_to_use']
                ? 'Guest cancelled paying cash at the front desk; GCash is open again.'
                : 'Customer cancelled paying cash at the office; GCash is open again.');
            return self::reply(200, ['ok' => true, 'status' => 'await_gcash', 'message' => 'Cash choice cancelled. You can pay by GCash again.']);
        }
        /* ...and while that choice stands, GCash stays closed: one way to pay at a time. */
        if ($booking['payment_status'] === 'await_cash' && $method === 'gcash') {
            return self::reply(409, ['ok' => false, 'message' => 'You chose to pay in cash ' . ($booking['pay_to_use'] ? 'at the front desk' : 'at the office')
                . '. Cancel that first to pay by GCash.']);
        }

        /* Payment has to be OPEN. */
        if (!in_array($booking['payment_status'], Status::PAY_OPEN, true)) {
            $why = match ($booking['payment_status']) {
                'await_event' => 'This booking cannot be paid until after your event has finished.',
                'overdue'     => 'Payment overdue. Please contact the office.',
                default       => 'This booking is not currently awaiting a payment.',
            };
            return self::reply(409, ['ok' => false, 'message' => $why]);
        }

        /* The customer may switch method at the payment step: saved only with a request that
           goes through (cash here, GCash inside its transaction below). */
        $methodChanged = $method !== $booking['payment_method'];

        /* CASH — nothing to submit. The customer pays at the office and staff
           record it, which is what creates the payment row. */
        if ($method === 'cash') {
            PaymentQueries::chooseCash($id, $methodChanged, (int) session('user_id'),
                $booking['pay_to_use'] ? 'Guest will pay cash at the front desk.' : 'Customer will pay in cash.');
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

        /* Only what the browser's checker can say (gcash-checker.php): a customer cannot
           write "rejected_by_staff" or "overridden_by_staff" onto their own upload. */
        $flags = array_values(array_intersect(array_unique(array_filter($clientFlags, 'is_string')), [
            'unreadable', 'likeness_uncertain', 'ref_format', 'ref_unreadable', 'receiver_mismatch', 'receiver_unreadable',
            'amount_mismatch', 'amount_unreadable', 'amounts_inconsistent', 'date_unreadable', 'date_future', 'date_stale', 'low_confidence',
        ]));
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

        /* RECORD THE ATTEMPT — every one, whatever its verdict. A refusal returns its reply
           before anything is written, so the transaction has nothing to keep. */
        try {
            $refused = DB::transaction(function () use ($request, $file, $id, $booking, $methodChanged, $bothWrong, $bothRight, $claimRef, $claimName, $claimNumber, $claimCentavos, $claimWhen, $confidence, $path, $sha, &$flags, &$used, &$allowed, &$verdict, &$isLast, &$dupRef, &$dupFile) {
                /* Lock this booking's row: two uploads at the same moment must not both be
                   counted as "attempt 5", nor both slip past a closed payment. */
                $locked = PaymentQueries::lockForUpload($id);
                if (!$locked || !in_array($locked['payment_status'], Status::PAY_OPEN, true)) {
                    return ['ok' => false, 'message' => 'This booking is not currently awaiting a payment.'];
                }
                $allowed = (int) $locked['receipt_attempts_allowed'];
                ['used' => $used, 'last' => $lastNo] = PaymentQueries::countUploads($id);

                /* THE LIMIT. One more upload is refused outright and recorded nowhere. */
                if ($used >= $allowed) {
                    return ['ok' => false, 'limit' => true, 'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
                        'message' => 'You have used all ' . $allowed . ' receipt attempts for this booking. Staff will review your '
                                   . 'payment and contact you, or you can pay in cash at the office.'];
                }

                /* THIS BOOKING'S OWN EARLIER RECEIPT, sent again: refused, NOT counted. */
                $again = PaymentQueries::findOwnEarlierUpload($id, $sha, $claimRef);
                if ($again) {
                    $againFlags = json_decode((string) $again['flags_json'], true);
                    $again['flags'] = is_array($againFlags) ? $againFlags : [];
                    $left = $allowed - $used;
                    return ['ok' => false, 'verdict' => 'rejected', 'repeat' => true,
                        'attemptsUsed' => $used, 'attemptsAllowed' => $allowed,
                        'message' => ($again['same_file'] ? 'You already uploaded this exact image' : 'You already uploaded a receipt with this reference number')
                                   . ' (attempt ' . (int) $again['attempt_no'] . ', ' . booking_receipt_outcome($again) . '). '
                                   . 'The same receipt cannot be accepted again, so this upload was not counted — you still have '
                                   . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left. Please upload a different receipt.'];
                }

                /* DUPLICATES — against every receipt that was not rejected, on ANY booking. */
                $dupRef = PaymentQueries::referenceIsActive($claimRef);
                $dupFile = PaymentQueries::fileIsActive($sha);
                if ($dupRef)  { $flags[] = 'duplicate_ref'; }
                if ($dupFile) { $flags[] = 'duplicate_file'; }

                $verdict = ($dupRef || $dupFile || $bothWrong) ? 'rejected'
                         : (($bothRight && !$flags) ? 'accepted' : 'manual_review');

                /* The LAST allowed attempt goes to staff whatever its verdict. */
                $isLast = ($used + 1 >= $allowed);
                if ($isLast) { $flags[] = 'attempt_limit_reached'; }
                $flags = array_values(array_unique($flags));

                if ($methodChanged) {
                    PaymentQueries::saveMethod($id, 'gcash');
                }

                /* A payment row only for a receipt that is going to staff as a payment. */
                $paymentId = $verdict === 'rejected' ? null : PaymentQueries::addPaymentUnderReview($id, $booking['total_amount']);

                PaymentQueries::addUpload([
                    'payment' => $paymentId, 'booking' => $id, 'attempt' => $lastNo + 1, 'path' => $path,
                    'filename' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                    'reference' => $claimRef, 'receiverName' => mb_substr($claimName, 0, 150),
                    'receiverNumber' => mb_substr($claimNumber, 0, 30),
                    'centavos' => $claimCentavos, 'when' => $claimWhen,
                    'sha' => $sha, 'confidence' => $confidence, 'verdict' => $verdict,
                    'flags' => json_encode($flags),
                    'text' => mb_substr((string) $request->input('text_excerpt', ''), 0, 2000),
                ]);

                /* under_review for anything staff must look at; a rejected attempt with
                   tries left changes nothing. The CUSTOMER did this: named for the History. */
                if ($verdict !== 'rejected' || $isLast) {
                    PaymentQueries::sendToReview($id, (int) session('user_id'), $isLast
                        ? 'Receipt attempt ' . ($used + 1) . ' of ' . $allowed . ' — the last allowed; sent to staff for manual review.'
                        : null);
                }
                return null;
            });
        } catch (PDOException $e) {
            @unlink(doc_path($path));
            /* 23000 = a UNIQUE violation: another upload of the same receipt landed
               between the check above and this insert. Nothing was recorded. */
            if ($e->getCode() === '23000') {
                $dupRefRace = strpos((string) ($e->errorInfo[2] ?? ''), 'uq_gcash_active_reference') !== false;
                return self::reply(409, ['ok' => false, 'verdict' => 'rejected',
                    'message' => $dupRefRace
                        ? 'That GCash reference number has already been used for a booking.'
                        : 'That exact receipt image has already been submitted before.']);
            }
            sql_failed($e);
            return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so your payment was not recorded.']);
        }

        if ($refused) {
            @unlink(doc_path($path));
            return self::reply(409, $refused);
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
