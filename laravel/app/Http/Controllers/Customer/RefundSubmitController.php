<?php

namespace App\Http\Controllers\Customer;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use PDOException;

/* =====================================================================
   REFUND SUBMIT (was customer/refund-submit.php) — the customer's side of
   the refund chain.

   POST  _token, booking=<VB-reference>, action=file|withdraw|resubmit
         file/resubmit: reason_category, reason, refund_to, or_pending
         FILES (optional): support[] — the documents staff asked for
   Replies with JSON (same shapes and statuses as the old endpoint).

   ELIGIBILITY IS RE-CHECKED HERE with the same cb_is_refundable() rule the
   history page asks. THE LIVE SWITCH IS DELIBERATELY NOT CONSULTED: a booking
   carries the policy it was MADE under (bookings.refunds_allowed, #16).

   CSRF: Laravel's middleware checks _token; an expired page gets the old
   400 JSON from the 419 handler in bootstrap/app.php.
   ===================================================================== */
class RefundSubmitController
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

        $action = (string) $request->input('action', '');
        $ref    = (string) $request->input('booking', '');

        /* DEMO MODE — a refund against a session-only booking. The chain still
           walks (file -> withdraw -> file again); nothing is written. */
        if (demo_owns($ref)) {
            if ($action === 'withdraw') {
                $row = demo_get_booking($ref);
                demo_update_booking($ref, [
                    'refundStatus' => null,
                    'paymentCode' => $row['method'] === 'Cash' ? 'paid_cash' : 'confirmed',
                    'paymentStatus' => 'Paid',
                ]);
                return self::reply(200, ['ok' => true, 'demo' => true, 'status' => 'withdrawn',
                    'message' => 'Request withdrawn. Your booking is unchanged. (Demo mode — nothing was saved.)']);
            }
            demo_update_booking($ref, ['refundStatus' => 'requested',
                'paymentCode' => 'refund_requested', 'paymentStatus' => 'Refund requested',
                'paymentStatusStaff' => 'Refund requested']);
            return self::reply(200, ['ok' => true, 'demo' => true, 'status' => 'requested', 'refundId' => 0,
                'documents' => ['stored' => 0, 'failed' => 0],
                'message' => 'Refund request filed (demo mode — nothing was saved).']);
        }

        $id = booking_id_from_reference($ref);
        if ($id === null || !in_array($action, ['file', 'withdraw', 'resubmit'], true)) {
            return self::reply(400, ['ok' => false, 'message' => 'Invalid request.']);
        }

        $pdo = venusep_db();
        if ($pdo === null) {
            return self::reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was changed.']);
        }

        /* Scoped to the session customer. */
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
        $bStmt->execute([':b' => $id, ':c' => (int) session('customer_id')]);
        $booking = $bStmt->fetch();
        if (!$booking) {
            return self::reply(404, ['ok' => false, 'message' => 'That booking was not found.']);
        }

        /* The latest refund on this booking, if any. */
        $rStmt = $pdo->prepare('SELECT * FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1');
        $rStmt->execute([':b' => $id]);
        $refund = $rStmt->fetch();

        /* What the booking's payment goes back to when a request ends without a payout. */
        $paidState = $booking['payment_method'] === 'cash' ? 'paid_cash' : 'confirmed';

        /* ---- WITHDRAW — the customer pulls the request before any decision. ---- */
        if ($action === 'withdraw') {
            if (!$refund || !in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction'], true)) {
                return self::reply(409, ['ok' => false, 'message' => 'There is no open refund request to withdraw.']);
            }
            try {
                $pdo->beginTransaction();
                $pdo->prepare("UPDATE refunds SET refund_status = 'withdrawn', withdrawn_at = NOW() WHERE id = :r")
                    ->execute([':r' => $refund['id']]);
                $pdo->prepare('SET @venusep_actor_user_id = :u, @venusep_action_note = :n')
                    ->execute([':u' => (int) session('user_id'), ':n' => 'Refund request withdrawn by the customer.']);
                $pdo->prepare('UPDATE bookings SET payment_status = :s WHERE id = :b')
                    ->execute([':s' => $paidState, ':b' => $id]);
                $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
                $pdo->commit();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
            }
            return self::reply(200, ['ok' => true, 'status' => 'withdrawn', 'message' => 'Request withdrawn. Your booking is unchanged.']);
        }

        /* ---- FILE / RESUBMIT ---- */
        $reasonCat = (string) $request->input('reason_category', 'other');
        $allowedCat = ['event_cancelled', 'schedule_conflict', 'wrong_room', 'venue_unavailable', 'payment_error', 'other'];
        if (!in_array($reasonCat, $allowedCat, true)) {
            $reasonCat = 'other';
        }
        $reason    = trim((string) $request->input('reason', ''));
        $orPending = !empty($request->input('or_pending')) ? 1 : 0;
        $refundTo  = cb_normalise_mobile((string) $request->input('refund_to', ''));

        if ($reason === '') {
            return self::reply(400, ['ok' => false, 'message' => 'Tell us why you are requesting a refund.']);
        }
        /* A GCash number is required only when the money went out by GCash. */
        if ($booking['payment_method'] !== 'cash' && $refundTo === null) {
            return self::reply(400, ['ok' => false, 'message' => 'Enter a valid GCash number in the form 09XX XXX XXXX — this is where your refund will be sent.']);
        }

        if ($action === 'file') {
            if ($refund && in_array($refund['refund_status'], ['requested', 'under_review', 'returned_for_correction'], true)) {
                return self::reply(409, ['ok' => false, 'message' => 'You already have an open refund request for this booking.']);
            }
            /* THE ELIGIBILITY RULE, asked of the same function the history page asks. */
            $rows = bookings_query(['booking_id' => $id]);
            if (!$rows || !$rows[0]['refundable']) {
                $why = !$booking['refunds_allowed']
                    ? 'This booking was made under the non-refundable policy.'
                    : ($booking['first_day'] <= date('Y-m-d')
                        ? 'This booking cannot be refunded because the event has already taken place.'
                        : 'This booking is not eligible for a refund.');
                return self::reply(409, ['ok' => false, 'message' => $why]);
            }
        } else {
            /* RESUBMIT — only from a correction (paperwork, not a denial). The
               original filing date is kept. */
            if (!$refund || $refund['refund_status'] !== 'returned_for_correction') {
                return self::reply(409, ['ok' => false, 'message' => 'There is nothing to resubmit.']);
            }
        }

        /* The documents are checked BEFORE anything is filed: a file the store would
           refuse (wrong type, over 7 MB) used to be dropped silently after the request
           was saved, so the customer believed the required receipts were attached. */
        $supportFiles = $request->file('support', []);
        $supportFiles = array_filter(is_array($supportFiles) ? $supportFiles : [$supportFiles]);
        foreach ($supportFiles as $file) {
            if (!$file instanceof UploadedFile) {
                return self::reply(400, ['ok' => false, 'message' => 'Invalid request.']);
            }
            $err = 'The upload did not arrive.';
            if (!$file->isValid() || doc_validate($file->getPathname(), $file->getClientOriginalName(), $err) === null) {
                return self::reply(400, ['ok' => false, 'message' => '"' . $file->getClientOriginalName() . '": ' . $err . ' Nothing was filed.']);
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
                    ':t' => $refundTo, ':o' => $orPending, ':u' => (int) session('user_id'),
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

            /* The booking is NOT cancelled by filing — it keeps its date until the
               refund COMPLETES. */
            $pdo->prepare('SET @venusep_actor_user_id = :u, @venusep_action_note = :n')
                ->execute([':u' => (int) session('user_id'),
                           ':n' => $action === 'file' ? 'Refund requested by the customer.' : 'Corrected documents resubmitted.']);
            $pdo->prepare("UPDATE bookings SET payment_status = 'refund_requested' WHERE id = :b")->execute([':b' => $id]);
            $pdo->exec('SET @venusep_actor_user_id = NULL, @venusep_action_note = NULL');
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            return self::reply(500, ['ok' => false, 'message' => 'Something went wrong, so your request was not filed.']);
        }

        /* Supporting documents, stored outside the web root. A failure here does
           not throw the request away — staff can chase a missing document. */
        $stored = 0; $failed = 0;
        foreach ($supportFiles as $file) {
            $err = '';
            $ok = doc_store($id, 'refund_support', $file->getPathname(), $file->getClientOriginalName(), (int) session('user_id'), $err);
            $ok === null ? $failed++ : $stored++;
        }

        return self::reply(200, [
            'ok'        => true,
            'status'    => 'requested',
            'refundId'  => $refundId,
            'documents' => ['stored' => $stored, 'failed' => $failed],
            'message'   => $action === 'file'
                ? 'Refund request filed. Your booking stays live until it is decided.'
                : 'Corrected documents sent. Your request is back with staff.',
        ]);
    }
}
