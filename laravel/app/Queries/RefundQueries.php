<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind a refund. The customer's side: filing, correcting, withdrawing. The
   staff's side: filing one for a walk-in, deciding it,
   paying it out, and recording one the school made outside the system.
   Columns: venusep_schema.sql (search "CREATE TABLE refunds").

   refunds: one row per refund request.
     refund_status: requested, under_review, returned_for_correction, approved, rejected,
     completed, withdrawn
     official_receipt_pending = 1: filed without the receipt, so an administrator must
     approve the proof before it is paid.
   bookings: the payment_status follows the refund (refund_requested, refund_correction,
     refund_denied, refunded). Only a paid-out refund closes the booking and frees its date.
   payments: a refunded booking's confirmed payment row becomes 'refunded'.

   Triggers that fire here: trg_bookings_after_update and trg_booking_timeline_notify, on
   the booking (see BookingQueries). None on refunds or payments. */
class RefundQueries
{
    use SavesWithHistory;

    /* The customer's own booking, as a refund needs it, or null. */
    public static function findForCustomer(int $bookingId, int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT b.id, b.payment_method, b.payment_status, b.total_amount, b.refunds_allowed,
                   COALESCE(vd.start_date, hd.check_in_date) AS first_day,
                   (SELECT p.id FROM payments p
                     WHERE p.booking_id = b.id AND p.payment_record_status = 'confirmed'
                     ORDER BY p.id DESC LIMIT 1) AS payment_id
            FROM bookings b
            LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
            LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
            WHERE b.id = ? AND b.customer_id = ?
        ", [$bookingId, $customerId]);
        return $row ? (array) $row : null;
    }

    /* The customer pulls the request back before any decision: the payment is as it was. */
    public static function withdraw(int $refundId, int $bookingId, string $paidState, int $actor): void
    {
        self::saveAs($actor, 'Refund request withdrawn by the customer.', function () use ($refundId, $bookingId, $paidState) {
            self::mustChange('withdraw', DB::update("
                UPDATE refunds
                SET refund_status = 'withdrawn',
                    withdrawn_at = NOW()
                WHERE id = ?
            ", [$refundId]));
            self::mustChange('withdraw', DB::update("
                UPDATE bookings
                SET payment_status = ?
                WHERE id = ?
            ", [$paidState, $bookingId]));
        });
    }

    /* The customer's new request: the booking waits for staff. */
    public static function fileRequest(int $bookingId, ?int $paymentId, string $category, string $reason, mixed $amount, ?string $refundTo, int $orPending, int $actor): int
    {
        return self::saveAs($actor, 'Refund requested by the customer.', function () use ($bookingId, $paymentId, $category, $reason, $amount, $refundTo, $orPending, $actor) {
            DB::insert("
                INSERT INTO refunds (booking_id, payment_id, refund_status, reason_category, reason,
                                     amount_requested, refund_to_number, official_receipt_pending, requested_by_user_id)
                VALUES (?, ?, 'requested', ?, ?, ?, ?, ?, ?)
            ", [$bookingId, $paymentId, $category, $reason, $amount, $refundTo, $orPending, $actor]);
            $refundId = (int) DB::getPdo()->lastInsertId();
            self::markRequested($bookingId, $orPending, $actor);
            return $refundId;
        });
    }

    /* A corrected request goes back to staff. The original filing date stays. */
    public static function resubmit(int $refundId, int $bookingId, string $category, string $reason, ?string $refundTo, int $orPending, int $actor): void
    {
        self::saveAs($actor, 'Corrected documents resubmitted.', function () use ($refundId, $bookingId, $category, $reason, $refundTo, $orPending, $actor) {
            self::mustChange('resubmit', DB::update("
                UPDATE refunds
                SET refund_status = 'requested',
                    reason_category = ?,
                    reason = ?,
                    refund_to_number = ?,
                    official_receipt_pending = ?,
                    resubmitted_at = NOW(),
                    correction_attempts = correction_attempts + 1
                WHERE id = ?
            ", [$category, $reason, $refundTo, $orPending, $refundId]));
            self::markRequested($bookingId, $orPending, $actor);
        });
    }

    /* The booking's side of a request; an administrator hears of one filed without its receipt. */
    private static function markRequested(int $bookingId, int $orPending, int $actor): void
    {
        self::mustChange('markRequested', DB::update("
            UPDATE bookings
            SET payment_status = 'refund_requested'
            WHERE id = ?
        ", [$bookingId]));
        if ($orPending) {
            legacy('notifications');
            notify_refund_needs_admin(DB::connection()->getPdo(), $bookingId, $actor);
        }
    }

    /* A booking's latest refund request, or null. */
    public static function findLatestRefund(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT rf.*
            FROM refunds rf
            WHERE rf.booking_id = ?
            ORDER BY rf.id DESC
            LIMIT 1
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* Whether the booking has a refund request staff can still decide (the same list as Status::REFUND_OPEN). */
    public static function hasOpenRefund(int $bookingId): bool
    {
        return DB::selectOne("
            SELECT rf.id
            FROM refunds rf
            WHERE rf.booking_id = ?
              AND rf.refund_status IN ('requested', 'under_review', 'returned_for_correction', 'approved')
            LIMIT 1
        ", [$bookingId]) !== null;
    }

    /* Staff file a refund for a walk-in, for the full amount, against the booking's latest
       confirmed payment. Without the receipt it waits in 'under_review', and the
       administrators are told. */
    public static function saveWalkInRefund(int $bookingId, bool $receiptLost, string $category, string $reason, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($bookingId, $receiptLost, $category, $reason, $actor) {
            DB::insert("
                INSERT INTO refunds (booking_id, payment_id, refund_status, reason_category, reason,
                                     amount_requested, requested_by_user_id)
                SELECT b.id,
                       (SELECT p.id
                        FROM payments p
                        WHERE p.booking_id = b.id
                          AND p.payment_record_status = 'confirmed'
                        ORDER BY p.id DESC
                        LIMIT 1),
                       ?, ?, ?,
                       b.total_amount, ?
                FROM bookings b
                WHERE b.id = ?
            ", [$receiptLost ? 'under_review' : 'requested', $category, $reason, $actor, $bookingId]);
            self::mustChange('saveWalkInRefund', DB::update("
                UPDATE bookings
                SET payment_status = 'refund_requested',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $bookingId]));
            if ($receiptLost) {
                legacy('notifications');
                notify_refund_needs_admin(DB::connection()->getPdo(), $bookingId, $actor);
            }
        });
    }

    /* An administrator accepts the proof for a missing receipt, so the payout can be
       recorded. False when no refund on the booking is waiting for that. */
    public static function markRefundApproved(int $bookingId, int $actor, string $note): bool
    {
        return self::saveAs($actor, $note, function () use ($bookingId, $actor, $note) {
            $waiting = DB::update("
                UPDATE refunds
                SET refund_status = 'approved',
                    reviewed_by_user_id = ?,
                    reviewed_at = NOW()
                WHERE booking_id = ?
                  AND (refund_status = 'under_review'
                       OR (refund_status = 'requested' AND official_receipt_pending = 1))
            ", [$actor, $bookingId]);
            if ($waiting === 0) {
                return false;
            }
            self::writeHistory($bookingId, 'refund_approved', $actor, $note);
            return true;
        });
    }

    /* Send a refund back to the customer to correct: paperwork, not a denial. They have 48 hours. */
    public static function markRefundReturned(int $refundId, int $bookingId, string $reason, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($refundId, $bookingId, $reason, $actor) {
            self::mustChange('markRefundReturned', DB::update("
                UPDATE refunds
                SET refund_status = 'returned_for_correction',
                    reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    notes = ?,
                    correction_due_at = DATE_ADD(NOW(), INTERVAL 48 HOUR)
                WHERE id = ?
            ", [$actor, $reason, $refundId]));
            self::mustChange('markRefundReturned', DB::update("
                UPDATE bookings
                SET payment_status = 'refund_correction',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $bookingId]));
        });
    }

    /* Deny a refund. FINAL, but the booking keeps its date and its paid status. */
    public static function markRefundDenied(int $refundId, int $bookingId, string $reason, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($refundId, $bookingId, $reason, $actor) {
            self::mustChange('markRefundDenied', DB::update("
                UPDATE refunds
                SET refund_status = 'rejected',
                    reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    notes = ?
                WHERE id = ?
            ", [$actor, $reason, $refundId]));
            self::mustChange('markRefundDenied', DB::update("
                UPDATE bookings
                SET payment_status = 'refund_denied',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $bookingId]));
        });
    }

    /* Record the payout of a refund, in cash or by GCash. ONLY NOW is the booking closed
       (the History trigger frees its date) and its payment marked refunded. */
    public static function markRefundPaidOut(int $refundId, int $bookingId, float $amount, bool $inCash, ?string $payoutTo,
                                             ?string $payoutReference, ?string $staffNote, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($refundId, $bookingId, $amount, $inCash, $payoutTo, $payoutReference, $staffNote, $actor) {
            self::mustChange('markRefundPaidOut', DB::update("
                UPDATE refunds
                SET refund_status = 'completed',
                    amount_approved = ?,
                    refund_to_number = IF(?, NULL, COALESCE(?, refund_to_number)),
                    payout_reference = ?,
                    reviewed_by_user_id = ?,
                    reviewed_at = COALESCE(reviewed_at, NOW()),
                    completed_at = NOW(),
                    notes = CONCAT_WS(' | ', NULLIF(notes, ''), ?)
                WHERE id = ?
            ", [$amount, $inCash ? 1 : 0, $payoutTo, $payoutReference, $actor, $staffNote, $refundId]));
            self::closeAsRefunded('markRefundPaidOut', $bookingId, $actor);
        });
    }

    /* Record a refund the school made outside the system, already paid: a completed refund
       row with the Cashier's reference, the booking closed, the administrators told. */
    public static function saveOutsideRefund(int $bookingId, string $category, string $reason, string $cashierReference,
                                             string $refundedOn, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($bookingId, $category, $reason, $cashierReference, $refundedOn, $actor) {
            DB::insert("
                INSERT INTO refunds (booking_id, refund_status, reason_category, reason,
                                     amount_requested, amount_approved,
                                     requested_by_user_id, reviewed_by_user_id, reviewed_at, completed_at,
                                     outside_system, cashier_reference, refunded_on)
                SELECT b.id, 'completed', ?, ?,
                       b.total_amount, b.total_amount,
                       ?, ?, NOW(), NOW(),
                       1, ?, ?
                FROM bookings b
                WHERE b.id = ?
            ", [$category, $reason, $actor, $actor, $cashierReference, $refundedOn, $bookingId]);
            self::closeAsRefunded('saveOutsideRefund', $bookingId, $actor);
            legacy('notifications');
            notify_refund_needs_admin(DB::connection()->getPdo(), $bookingId, $actor, 'refund_recorded');
        });
    }

    /* A refunded booking is cancelled (the History trigger frees its date), and its
       confirmed payment row is marked refunded. A booking with no payment row is fine. */
    private static function closeAsRefunded(string $what, int $bookingId, int $actor): void
    {
        self::mustChange($what, DB::update("
            UPDATE bookings
            SET reservation_status = 'cancelled',
                payment_status = 'refunded',
                cancelled_at = NOW(),
                updated_by_user_id = ?
            WHERE id = ?
        ", [$actor, $bookingId]));
        DB::update("
            UPDATE payments
            SET payment_record_status = 'refunded'
            WHERE booking_id = ?
              AND payment_record_status = 'confirmed'
        ", [$bookingId]);
    }
}
