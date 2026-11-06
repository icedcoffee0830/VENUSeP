<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind the outbox and a booking's Emails panel. Columns: venusep_schema.sql
   (search "CREATE TABLE email_outbox").

   email_outbox: one row per email: pending, sent or failed. The wording is
     legacy/includes/receipt-emails.php, the sending mailer.php.
   Read only: bookings, customers, users, booking_timeline. */
class EmailQueries
{
    /* One email, waiting to go. */
    public static function add(array $m): int
    {
        DB::insert("
            INSERT INTO email_outbox (kind, booking_id, receipt_id, requested_by_user_id, to_email, subject, body_html, body_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ", [$m['kind'], $m['booking'], $m['receipt'], $m['by'], $m['to'], $m['subject'], $m['html'], $m['text']]);
        return (int) DB::getPdo()->lastInsertId();
    }

    public static function markSent(int $id): void
    {
        DB::update("
            UPDATE email_outbox
            SET status = 'sent',
                sent_at = NOW(),
                last_error = NULL,
                attempts = attempts + 1
            WHERE id = ?
        ", [$id]);
    }

    public static function markFailed(int $id, string $error): void
    {
        DB::update("
            UPDATE email_outbox
            SET status = 'failed',
                last_error = ?,
                attempts = attempts + 1
            WHERE id = ?
        ", [$error, $id]);
    }

    /* The walk-in of a booking: only a customer with no account; or null. */
    public static function findWalkIn(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT c.full_name, c.contact_email
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            WHERE b.id = ? AND c.user_id IS NULL
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* Who a booking's status email goes to, or null. */
    public static function findNameAndEmail(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT c.full_name, COALESCE(u.email, c.contact_email) AS email
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            LEFT JOIN users u ON u.id = c.user_id
            WHERE b.id = ?
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    /* Bookings that just turned due or overdue with no email yet: three at a time, recent changes only. */
    public static function dueWithoutEmail(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT DISTINCT b.id, b.payment_status
            FROM bookings b
            JOIN booking_timeline t ON t.booking_id = b.id AND t.action_code = 'status_changed'
                 AND t.new_payment_status = b.payment_status AND t.occurred_at >= NOW() - INTERVAL 3 DAY
            WHERE b.refunds_allowed = 0 AND b.pay_to_use = 0
              AND (b.payment_status = 'overdue'
                   OR (b.payment_status IN ('await_gcash', 'await_cash') AND t.old_payment_status = 'await_event'))
              AND NOT EXISTS (SELECT 1 FROM email_outbox e WHERE e.booking_id = b.id
                               AND e.kind = IF(b.payment_status = 'overdue', 'payment_overdue', 'payment_due'))
            LIMIT 3
        "));
    }

    /* Pending requests the procedure cancelled because the customer was still blocked, with no
       email yet: their History note starts "Cancelled automatically:". */
    public static function blockCancelledWithoutEmail(): array
    {
        return array_column(DB::select("
            SELECT DISTINCT b.id
            FROM bookings b
            JOIN booking_timeline t ON t.booking_id = b.id AND t.action_code = 'status_changed'
                 AND t.new_reservation_status = 'cancelled' AND t.note LIKE 'Cancelled automatically:%'
                 AND t.occurred_at >= NOW() - INTERVAL 3 DAY
            WHERE b.reservation_status = 'cancelled'
              AND NOT EXISTS (SELECT 1 FROM email_outbox e WHERE e.booking_id = b.id AND e.kind = 'booking_block_cancelled')
            LIMIT 3
        "), 'id');
    }

    /* The "Email me" throttle: a copy of this receipt in the last five minutes? */
    public static function copySentRecently(int $receiptId): bool
    {
        return DB::selectOne("
            SELECT 1 AS found
            FROM email_outbox
            WHERE kind = 'receipt_copy' AND receipt_id = ?
              AND created_at > NOW() - INTERVAL 5 MINUTE
            LIMIT 1
        ", [$receiptId]) !== null;
    }

    /* The id of a booking's email that may go out again: failed, or stuck for five minutes; or null. */
    public static function findResendable(int $id, int $bookingId): ?int
    {
        $row = DB::selectOne("
            SELECT id
            FROM email_outbox
            WHERE id = ? AND booking_id = ?
              AND (status = 'failed' OR (status = 'pending' AND created_at < NOW() - INTERVAL 5 MINUTE))
        ", [$id, $bookingId]);
        return $row ? (int) $row->id : null;
    }

    public static function find(int $id): ?array
    {
        $row = DB::selectOne('SELECT * FROM email_outbox WHERE id = ?', [$id]);
        return $row ? (array) $row : null;
    }

    /* Where a booking's email goes: an account's address, or a walk-in's contact email; or null. */
    public static function findRecipient(int $bookingId): ?array
    {
        $row = DB::selectOne("
            SELECT c.user_id, c.contact_email, u.email AS account_email
            FROM bookings b
            JOIN customers c ON c.id = b.customer_id
            LEFT JOIN users u ON u.id = c.user_id
            WHERE b.id = ?
        ", [$bookingId]);
        return $row ? (array) $row : null;
    }

    public static function forBooking(int $bookingId): array
    {
        return array_map(fn ($r) => (array) $r, DB::select('SELECT * FROM email_outbox WHERE booking_id = ? ORDER BY id', [$bookingId]));
    }
}
