<?php

namespace App\Queries;

use App\Support\Status;
use Illuminate\Support\Facades\DB;
use PDOException;

/* The SQL behind the staff's decisions about money: confirming a payment and judging a
   GCash receipt. Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   payments: the money's own record, one row per transfer or cash payment.
     payment_record_status: submitted, under_review, confirmed, rejected, refunded
   gcash_receipts: one row per GCash receipt a customer uploaded.
     verdict (the checker's): accepted, rejected, manual_review
     flags_json: what the checker and staff found, e.g. duplicate_ref, rejected_by_staff,
     overridden_by_staff
   bookings: the payment_status changes here too (BookingQueries has the bookings notes).

   Triggers that fire here: trg_bookings_after_update and trg_booking_timeline_notify, on
   the booking (see BookingQueries). None on payments or gcash_receipts. */
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
