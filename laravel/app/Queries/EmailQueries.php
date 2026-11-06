<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind a booking's Emails panel. Columns: venusep_schema.sql
   (search "CREATE TABLE email_outbox").

   email_outbox: one row per email: pending, sent or failed. Written and sent by the legacy
     mail helpers (legacy/includes/mailer.php, receipt-emails.php).
   Read only: bookings, customers, users. */
class EmailQueries
{
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
