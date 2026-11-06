<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind the bell: who is told what, and what they see.
   The wording is legacy/includes/notifications.php. Columns: venusep_schema.sql
   (search "CREATE TABLE notifications").

   notifications: one row per person told. read_at set = read. kind says what happened,
     detail holds its words as JSON.
   Read only: users, staff, bookings, rooms, venues, customers.
   Procedure: sp_notify_venue_staff. fn_staff_on_room: does this staff member handle this room;
   fn_request_expiry: when a pending request lapses. */
class NotificationQueries
{
    /* The booking's hall staff and its group's normal admins, except whoever did it. */
    public static function tellVenueStaff(int $bookingId, string $kind, ?int $actor): void
    {
        DB::statement('CALL sp_notify_venue_staff(?, ?, ?, NULL)', [$bookingId, $kind, $actor]);
    }

    /* The booking's group admins; $group: the SQL for them. A group with no active normal
       admin is covered by the super admin. The NOT EXISTS reads its own users u. */
    public static function tellGroupAdmins(string $group, int $bookingId, string $kind, int $actor): void
    {
        DB::insert("
            INSERT INTO notifications (user_id, kind, booking_id, actor_user_id)
            SELECT u.id, ?, ?, ?
            FROM users u
            WHERE u.is_active = 1 AND u.id <> ?
              AND (($group) OR (u.is_super_admin = 1 AND NOT EXISTS (SELECT 1 FROM users u WHERE u.is_active = 1 AND $group)))
        ", [$kind, $bookingId, $actor, $actor]);
    }

    /* The customer and the booking's group admins; $group: the SQL for them. */
    public static function tellBookingParties(string $group, int $bookingId, string $kind, int $actor, string $detail): void
    {
        DB::insert("
            INSERT INTO notifications (user_id, kind, booking_id, actor_user_id, detail)
            SELECT u.id, ?, ?, ?, ?
            FROM users u
            WHERE u.is_active = 1 AND u.id <> ?
              AND (($group)
                   OR u.id = (SELECT c.user_id FROM bookings b JOIN customers c ON c.id = b.customer_id WHERE b.id = ?))
        ", [$kind, $bookingId, $actor, $detail, $actor, $bookingId]);
    }

    /* The admins picked by $who, the SQL for them, about an account event. */
    public static function tellAdmins(string $who, string $kind, int $actor, string $detail): void
    {
        DB::insert("
            INSERT INTO notifications (user_id, kind, actor_user_id, detail)
            SELECT u.id, ?, ?, ?
            FROM users u
            WHERE u.account_type = 'admin' AND u.is_active = 1 AND u.id <> ? AND $who
        ", [$kind, $actor, $detail, $actor]);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) DB::selectOne(
            'SELECT COUNT(*) AS n FROM notifications n WHERE n.user_id = ? AND n.read_at IS NULL' . staff_news_sql(),
            [$userId]
        )->n;
    }

    /* Is something waiting for this customer? $waiting: the quoted payment statuses. */
    public static function somethingWaiting(int $userId, string $waiting): bool
    {
        return (bool) DB::selectOne("
            SELECT EXISTS (
                SELECT 1
                FROM notifications n
                JOIN bookings b ON b.id = n.booking_id
                WHERE n.user_id = ?
                  AND ((b.payment_status IN ($waiting) AND b.reservation_status NOT IN ('cancelled', 'rejected', 'released'))
                       OR (b.reservation_status = 'rejected' AND fn_booking_first_day(b.id) > CURDATE()))
            ) AS waiting
        ", [$userId])->waiting;
    }

    public static function markAllRead(int $userId): void
    {
        DB::update('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /* Only ever the viewer's own row. */
    public static function markRead(int $id, int $userId): void
    {
        DB::update('UPDATE notifications SET read_at = COALESCE(read_at, NOW()) WHERE id = ? AND user_id = ?', [$id, $userId]);
    }

    /* Read notifications older than $days go. */
    public static function cleanUp(int $userId, int $days): void
    {
        DB::delete('DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL AND read_at < NOW() - INTERVAL ' . $days . ' DAY', [$userId]);
    }

    /* The newest $limit for one person, with what the wording needs. */
    public static function newest(int $userId, int $limit): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT n.id, n.kind, n.booking_id, n.detail, n.created_at, n.read_at,
                   b.submitted_at, b.booking_type, b.payment_status, b.approved_at, b.current_deadline_at,
                   r.name AS room_name, v.name AS venue_name, c.full_name AS customer_name,
                   COALESCE(sa.full_name, ua.email) AS actor_name
            FROM notifications n
            LEFT JOIN bookings b ON b.id = n.booking_id
            LEFT JOIN rooms r ON r.id = b.room_id
            LEFT JOIN venues v ON v.id = r.venue_id
            LEFT JOIN customers c ON c.id = b.customer_id
            LEFT JOIN users ua ON ua.id = n.actor_user_id
            LEFT JOIN staff sa ON sa.user_id = n.actor_user_id
            WHERE n.user_id = ?" . staff_news_sql() . "
            ORDER BY n.id DESC
            LIMIT " . $limit, [$userId]));
    }

    /* Requests that have waited three days for an answer, oldest first. $officeIds: a comma list of numbers. */
    public static function longWaiting(string $officeIds): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT b.id, b.submitted_at, DATEDIFF(NOW(), b.submitted_at) AS days
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            WHERE b.reservation_status = 'pending'
              AND b.submitted_at <= NOW() - INTERVAL 3 DAY
              AND r.venue_id IN ($officeIds)
            ORDER BY b.submitted_at
            LIMIT 5
        "));
    }

    /* Requests lapsing within 24 hours. $staffId: only this staff member's rooms. */
    public static function expiringSoon(string $officeIds, ?int $staffId): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT b.id, b.submitted_at, r.name AS room_name, fn_request_expiry(b.id, b.booking_type) AS expires_at
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            WHERE b.reservation_status = 'pending'
              AND fn_request_expiry(b.id, b.booking_type) BETWEEN NOW() AND NOW() + INTERVAL 24 HOUR
              AND r.venue_id IN ($officeIds)" . ($staffId === null ? '' : ' AND fn_staff_on_room(' . $staffId . ', r.id)') . "
            ORDER BY expires_at
            LIMIT 10
        "));
    }
}
