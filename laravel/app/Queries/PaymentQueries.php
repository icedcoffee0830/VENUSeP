<?php

namespace App\Queries;

use App\Support\Status;
use Illuminate\Support\Facades\DB;
use PDOException;

/* The SQL behind the staff's decisions about money: confirming a payment, judging a
   GCash receipt, reversing a payment. Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   payments: the money's own record, one row per transfer or cash payment.
     payment_record_status: submitted, under_review, confirmed, rejected, refunded
   gcash_receipts: one row per GCash receipt a customer uploaded.
     verdict (the checker's): accepted, rejected, manual_review
     flags_json: what the checker and staff found, e.g. duplicate_ref, rejected_by_staff,
     overridden_by_staff
   system_receipts: the System Receipt issued for a confirmed payment; voided on a reversal.
   bookings: the payment_status changes here too (BookingQueries has the bookings notes).
   users: an administrator's own row, locked for the password check before a reversal.
   Confirming a payment is done by payment_confirm() (legacy/includes/system-receipt.php).

   Triggers that fire here: trg_bookings_after_update and trg_booking_timeline_notify, on
   the booking (see BookingQueries). None on payments, gcash_receipts or system_receipts. */
class PaymentQueries
{
    use SavesWithHistory;

    /* A booking's latest GCash upload (the one a rejection, an undo or an override is
       about), with its flags decoded, or null. */
    public static function findLatestGcashUpload(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT g.id, g.payment_id, g.verdict, g.flags_json, g.amount_centavos
            FROM gcash_receipts g
            WHERE g.booking_id = ?
            ORDER BY g.id DESC
            LIMIT 1
        ", [$bookingId]);
        if (!$row) {
            return null;
        }
        $upload = (array) $row;
        $flags = json_decode((string) $upload['flags_json'], true);
        $upload['flags'] = is_array($flags) ? $flags : [];
        $upload['payment_id'] = $upload['payment_id'] !== null ? (int) $upload['payment_id'] : null;
        $upload['rejected'] = $upload['verdict'] === Status::REJECTED || in_array('rejected_by_staff', $upload['flags'], true);
        return $upload;
    }

    /* Whether an upload on another booking already carries this GCash reference. */
    public static function referenceIsOnAnotherBooking(string $reference, int $bookingId): bool
    {
        return DB::selectOne("
            SELECT g.id
            FROM gcash_receipts g
            WHERE g.active_reference = ?
              AND g.booking_id <> ?
            LIMIT 1
        ", [$reference, $bookingId]) !== null;
    }

    /* Money landed and staff verified it: the booking is paid, its payment row settled and
       its System Receipt issued, together or not at all. Returns the outbox id of a walk-in's
       receipt email, or null. */
    public static function markPaymentConfirmed(int $bookingId, bool $asCash, int $actor, string $note): ?int
    {
        return self::saveAs($actor, $note, fn () => self::confirmAndIssueReceipt($bookingId, $asCash, $actor, $note));
    }

    /* Staff found the money of a rejected receipt in the venue's GCash history: the receipt
       gains who overruled it and why (the checker's verdict stays on record), the payment row
       the upload created goes back to review, then the payment is confirmed on that same row.
       Returns the outbox id of a walk-in's receipt email, or null. */
    public static function overrideRejectedUpload(int $bookingId, int $uploadId, ?int $paymentId, int $actor, string $note): ?int
    {
        return self::saveAs($actor, $note, function () use ($bookingId, $uploadId, $paymentId, $actor, $note) {
            self::mustChange('overrideRejectedUpload', DB::update("
                UPDATE gcash_receipts
                SET reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    override_note = ?,
                    flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'overridden_by_staff')
                WHERE id = ?
            ", [$actor, $note, $uploadId]));
            if ($paymentId !== null) {
                self::reopenRejectedPayment($paymentId);
            }
            return self::confirmAndIssueReceipt($bookingId, false, $actor, $note);
        });
    }

    /* Take back a staff rejection: the receipt is under review again, as it was. */
    public static function undoStaffRejection(int $bookingId, int $uploadId, ?int $paymentId, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($bookingId, $uploadId, $paymentId, $actor) {
            self::mustChange('undoStaffRejection', DB::update("
                UPDATE gcash_receipts
                SET reviewed_by_user_id = NULL,
                    reviewed_at = NULL,
                    override_note = NULL,
                    flags_json = JSON_REMOVE(flags_json, JSON_UNQUOTE(JSON_SEARCH(flags_json, 'one', 'rejected_by_staff')))
                WHERE id = ?
            ", [$uploadId]));
            if ($paymentId !== null) {
                self::reopenRejectedPayment($paymentId);
            }
            self::mustChange('undoStaffRejection', DB::update("
                UPDATE bookings
                SET payment_status = 'under_review',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $bookingId]));
        });
    }

    /* Staff reject the receipt under review: it keeps the CHECKER's verdict and gains who
       decided, when and why (DB-DECISIONS #6); payment opens again; the payment row is rejected. */
    public static function rejectReceiptUnderReview(int $bookingId, string $reason, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($bookingId, $reason, $actor) {
            /* the newest receipt nobody has decided yet */
            DB::update("
                UPDATE gcash_receipts
                SET reviewed_by_user_id = ?,
                    reviewed_at = NOW(),
                    override_note = ?,
                    flags_json = JSON_ARRAY_APPEND(COALESCE(flags_json, JSON_ARRAY()), '$', 'rejected_by_staff')
                WHERE booking_id = ?
                  AND reviewed_at IS NULL
                ORDER BY id DESC
                LIMIT 1
            ", [$actor, $reason, $bookingId]);
            self::mustChange('rejectReceiptUnderReview', DB::update("
                UPDATE bookings
                SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    staff_notes = ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$reason, $actor, $bookingId]));
            DB::update("
                UPDATE payments
                SET payment_record_status = 'rejected'
                WHERE booking_id = ?
                  AND payment_record_status IN ('submitted', 'under_review')
            ", [$bookingId]);
        });
    }

    /* The signed-in administrator's row, locked until the reversal commits, with what
       reauth_verify() (legacy/includes/auth.php) needs to check the password: two wrong
       tries at the same moment must both count. */
    public static function lockAccountForPasswordCheck(int $userId): ?array
    {
        $row = DB::selectOne("
            SELECT u.id, u.password_hash, u.account_type, u.is_active,
                   u.reauth_failed_attempts, u.reauth_lock_level,
                   CASE WHEN u.reauth_locked_until > NOW()
                        THEN TIMESTAMPDIFF(SECOND, NOW(), u.reauth_locked_until)
                        ELSE 0 END AS lock_seconds
            FROM users u
            WHERE u.id = ?
            FOR UPDATE
        ", [$userId]);
        return $row ? (array) $row : null;
    }

    /* Reverse a payment (a fake receipt, or the wrong booking): its payment row is rejected,
       its System Receipt voided, and the booking owes again, with 48 hours to pay. The
       customer and the admins are told. Runs inside the transaction the caller opened for
       the password check. */
    public static function markPaymentReversed(int $bookingId, string $reason, int $actor, string $note): void
    {
        self::signedAs($actor, $note, function () use ($bookingId, $reason, $actor) {
            /* none is fine: an older paid booking may have no payment row or receipt */
            DB::update("
                UPDATE payments
                SET payment_record_status = 'rejected'
                WHERE booking_id = ?
                  AND payment_record_status = 'confirmed'
            ", [$bookingId]);
            DB::update("
                UPDATE system_receipts
                SET voided_at = NOW(),
                    voided_by_user_id = ?,
                    void_reason = ?
                WHERE booking_id = ?
                  AND voided_at IS NULL
            ", [$actor, $reason, $bookingId]);
            self::mustChange('markPaymentReversed', DB::update("
                UPDATE bookings
                SET reservation_status = IF(reservation_status = 'completed', 'approved', reservation_status),
                    payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    current_deadline_at = DATE_ADD(NOW(), INTERVAL 48 HOUR),
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $bookingId]));
            legacy('notifications');
            notify_booking_parties(DB::connection()->getPdo(), $bookingId, 'payment_reversed', $actor, ['note' => $reason]);
        });
    }

    /* The payment row a staff rejection closed goes back to review. Already back is fine. */
    private static function reopenRejectedPayment(int $paymentId): void
    {
        DB::update("
            UPDATE payments
            SET payment_record_status = 'under_review'
            WHERE id = ?
              AND payment_record_status = 'rejected'
        ", [$paymentId]);
    }

    /* payment_confirm() (legacy/includes/system-receipt.php) marks the booking paid, settles
       its payment row and issues the System Receipt, inside the caller's save: it is given
       Laravel's own connection. A database error undoes it all; a problem building a walk-in's
       EMAIL must not, because the payment is real. */
    private static function confirmAndIssueReceipt(int $bookingId, bool $asCash, int $actor, string $note): ?int
    {
        legacy('system-receipt', 'receipt-emails');
        $pdo = DB::connection()->getPdo();
        $receiptId = payment_confirm($pdo, $bookingId, $actor, $note, $asCash);
        if ($receiptId === null) {
            return null;
        }
        try {
            return queue_walkin_receipt($pdo, $bookingId, $receiptId, $actor);
        } catch (\Throwable $mailErr) {
            if ($mailErr instanceof PDOException) {
                throw $mailErr;
            }
            error_log('VENUSeP walk-in receipt email for booking ' . $bookingId . ': ' . $mailErr->getMessage());
            return null;
        }
    }
}
