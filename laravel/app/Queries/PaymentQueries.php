<?php

namespace App\Queries;

use App\Support\Status;
use Illuminate\Support\Facades\DB;
use PDOException;

/* The SQL behind the customer's payment step (choosing cash, uploading a GCash receipt) and
   the staff's decisions about money: confirming a payment, judging a GCash receipt,
   reversing a payment. Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   payments: the money's own record, one row per transfer or cash payment.
     payment_record_status: submitted, under_review, confirmed, rejected, refunded
   gcash_receipts: one row per GCash receipt a customer uploaded.
     verdict (the checker's): accepted, rejected, manual_review
     flags_json: what the checker and staff found, e.g. duplicate_ref, rejected_by_staff,
     overridden_by_staff
   system_receipts: the System Receipt issued for a confirmed payment; voided on a reversal.
   bookings: the payment_status changes here too (BookingQueries has the bookings notes).
   Read only: rooms, venues, gcash_accounts (the account an office is paid into).
   Confirming a payment is done by payment_confirm() (legacy/includes/system-receipt.php).

   Triggers that fire here: trg_bookings_after_update and trg_booking_timeline_notify, on
   the booking (see BookingQueries). None on payments, gcash_receipts or system_receipts. */
class PaymentQueries
{
    use SavesWithHistory;

    /* Whose booking this is; null when there is no such booking. */
    public static function findBookingCustomer(int $bookingId): ?int
    {
        $row = DB::selectOne('SELECT customer_id FROM bookings WHERE id = ?', [$bookingId]);
        return $row ? (int) $row->customer_id : null;
    }

    /* Which of these bookings have a System Receipt: ONE query for a whole list. */
    public static function bookingsWithReceipt(array $bookingIds): array
    {
        return array_map(fn ($r) => (int) $r->booking_id, DB::select(
            'SELECT DISTINCT booking_id FROM system_receipts WHERE booking_id IN (' . implode(',', array_map('intval', $bookingIds)) . ')'
        ));
    }

    /* The customer's own booking and the GCash account its office is paid into, or null. */
    public static function findForCustomer(int $bookingId, int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT b.id, b.customer_id, b.payment_status, b.payment_method, b.total_amount, b.pay_to_use,
                   v.name AS venue_name,
                   g.account_name, g.mobile_number
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN gcash_accounts g ON g.venue_id = v.id AND g.is_active = 1 AND g.valid_until IS NULL
            WHERE b.id = ? AND b.customer_id = ?
        ", [$bookingId, $customerId]);
        return $row ? (array) $row : null;
    }

    /* Take back "I will pay in cash": a hostel stay goes back to no method chosen, a venue
       booking to GCash. */
    public static function cancelCashChoice(int $bookingId, int $actor, string $note): void
    {
        self::saveAs($actor, $note, fn () => DB::update("
            UPDATE bookings
            SET payment_status = 'await_gcash',
                payment_method = IF(pay_to_use, NULL, 'gcash')
            WHERE id = ? AND payment_status = 'await_cash'
        ", [$bookingId]));
    }

    /* The customer's new way to pay, saved with the request that goes through. */
    public static function saveMethod(int $bookingId, string $method): void
    {
        self::mustChange('saveMethod', DB::update("
            UPDATE bookings
            SET payment_method = ?
            WHERE id = ?
        ", [$method, $bookingId]));
    }

    /* The customer will pay in cash: staff record it when it comes. */
    public static function chooseCash(int $bookingId, bool $methodChanged, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($bookingId, $methodChanged) {
            if ($methodChanged) {
                self::saveMethod($bookingId, 'cash');
            }
            self::mustChange('chooseCash', DB::update("
                UPDATE bookings
                SET payment_status = 'await_cash'
                WHERE id = ?
            ", [$bookingId]));
        });
    }

    /* The booking's payment state, locked until the caller's transaction ends; or null. */
    public static function lockForUpload(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT payment_status, receipt_attempts_allowed
            FROM bookings
            WHERE id = ?
            FOR UPDATE
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* How many receipts the booking has uploaded, and the last attempt number. */
    public static function countUploads(int $bookingId): array
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS used, COALESCE(MAX(attempt_no), 0) AS last
            FROM gcash_receipts
            WHERE booking_id = ?
        ", [$bookingId]);
        return ['used' => (int) $row->used, 'last' => (int) $row->last];
    }

    /* This booking's own earlier upload of the same image or reference number, or null. */
    public static function findOwnEarlierUpload(int $bookingId, string $sha, string $reference): ?array
    {
        $row = DB::selectOne("
            SELECT attempt_no, verdict, flags_json, override_note, sha256_hash = ? AS same_file
            FROM gcash_receipts
            WHERE booking_id = ? AND (sha256_hash = ? OR reference_number = ?)
            ORDER BY id DESC
            LIMIT 1
        ", [$sha, $bookingId, $sha, $reference]);
        return $row ? (array) $row : null;
    }

    /* Whether a receipt not rejected, on any booking, has this reference number. */
    public static function referenceIsActive(string $reference): bool
    {
        return DB::selectOne('SELECT 1 AS found FROM gcash_receipts WHERE active_reference = ? LIMIT 1', [$reference]) !== null;
    }

    /* Whether a receipt not rejected, on any booking, is this exact image. */
    public static function fileIsActive(string $sha): bool
    {
        return DB::selectOne('SELECT 1 AS found FROM gcash_receipts WHERE active_sha256 = ? LIMIT 1', [$sha]) !== null;
    }

    /* The payment a receipt going to staff stands for. */
    public static function addPaymentUnderReview(int $bookingId, mixed $amount): int
    {
        DB::insert("
            INSERT INTO payments (booking_id, payment_method, amount, payment_record_status, paid_at)
            VALUES (?, 'gcash', ?, 'under_review', NOW())
        ", [$bookingId, $amount]);
        return (int) DB::getPdo()->lastInsertId();
    }

    /* One uploaded GCash receipt, whatever its verdict. */
    public static function addUpload(array $u): void
    {
        DB::insert("
            INSERT INTO gcash_receipts
                (payment_id, booking_id, attempt_no, file_path, original_filename, reference_number,
                 receiver_name, receiver_number, amount_centavos, receipt_datetime,
                 sha256_hash, ocr_confidence, verdict, flags_json, ocr_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$u['payment'], $u['booking'], $u['attempt'], $u['path'], $u['filename'], $u['reference'],
            $u['receiverName'], $u['receiverNumber'], $u['centavos'], $u['when'],
            $u['sha'], $u['confidence'], $u['verdict'], $u['flags'], $u['text']]);
    }

    /* The booking goes to staff to look at. Inside the caller's transaction. */
    public static function sendToReview(int $bookingId, int $actor, ?string $note): void
    {
        self::signedAs($actor, $note, fn () => self::mustChange('sendToReview', DB::update("
            UPDATE bookings
            SET payment_status = 'under_review'
            WHERE id = ?
        ", [$bookingId])));
    }

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
