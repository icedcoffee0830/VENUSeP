<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind the System Receipt (legacy/includes/system-receipt.php).
   Columns: venusep_schema.sql (search "CREATE TABLE system_receipts").

   system_receipts: one row per confirmed payment. snapshot_json keeps what the receipt
     said on the day, so a later change never rewrites it.
   Read only: payments, bookings, customers, booking_timeline, staff. */
class ReceiptQueries
{
    /* A booking's newest confirmed payment, or null. */
    public static function findConfirmedPayment(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT id, amount, payment_method, confirmed_at
            FROM payments
            WHERE booking_id = ? AND payment_record_status = 'confirmed'
            ORDER BY id DESC
            LIMIT 1
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* The receipt already issued for a payment, or null. */
    public static function findIdForPayment(int $paymentId): ?int
    {
        $row = DB::selectOne('SELECT id FROM system_receipts WHERE payment_id = ?', [$paymentId]);
        return $row ? (int) $row->id : null;
    }

    public static function contactEmail(int $bookingId): string
    {
        return (string) (DB::selectOne("
            SELECT c.contact_email
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            WHERE b.id = ?
        ", [$bookingId])->contact_email ?? '');
    }

    /* Who handled the booking, by staff name ('' when unknown): the staff member or admin
       who approved it (its latest move into Approved; a counter booking is approved by the
       staff who took it), and who confirmed this payment (or issued its receipt). */
    public static function handlers(int $bookingId, int $paymentId): array
    {
        $row = DB::selectOne("
            SELECT (SELECT s.full_name
                      FROM booking_timeline t
                      JOIN staff s ON s.user_id = t.performed_by_user_id
                     WHERE t.booking_id = ? AND t.new_reservation_status = 'approved'
                       AND NOT (t.old_reservation_status <=> 'approved')
                     ORDER BY t.occurred_at DESC, t.id DESC
                     LIMIT 1) AS approved_by,
                   (SELECT s.full_name
                      FROM payments p
                      LEFT JOIN system_receipts r ON r.payment_id = p.id
                      JOIN staff s ON s.user_id = COALESCE(p.confirmed_by_user_id, r.issued_by_user_id)
                     WHERE p.id = ?) AS confirmed_by
        ", [$bookingId, $paymentId]);
        return ['approved_by' => (string) $row->approved_by, 'payment_confirmed_by' => (string) $row->confirmed_by];
    }

    public static function add(int $bookingId, int $paymentId, string $snapshot, ?int $actor): int
    {
        DB::insert("
            INSERT INTO system_receipts (booking_id, payment_id, snapshot_json, issued_at, issued_by_user_id)
            VALUES (?, ?, ?, NOW(), ?)
        ", [$bookingId, $paymentId, $snapshot, $actor]);
        return (int) DB::getPdo()->lastInsertId();
    }

    public static function find(int $id): ?array
    {
        $row = DB::selectOne('SELECT * FROM system_receipts WHERE id = ?', [$id]);
        return $row ? (array) $row : null;
    }

    /* The newest on a booking: a second one exists only after a second confirmed payment. */
    public static function findLatestForBooking(int $bookingId): ?array
    {
        $row = DB::selectOne('SELECT * FROM system_receipts WHERE booking_id = ? ORDER BY id DESC LIMIT 1', [$bookingId]);
        return $row ? (array) $row : null;
    }
}
