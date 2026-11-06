<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The version strings behind the 30-second check (public/assets/js/live.js). A page only
   compares a string with the one it saw last, so these stay small: no rows, no shaping.

   A booking's version is the count, the newest bookings.updated_at (ON UPDATE
   CURRENT_TIMESTAMP) and the newest booking_timeline id: every status change lands there,
   so two changes inside one second still show, and so does a refund step.
   Read only: bookings, rooms, booking_timeline, notifications. */
class LiveQueries
{
    /* The bookings a staff-side session sees: staff_venue_sql() narrows to its offices,
       staff_hall_sql() to a staff member's halls. */
    public static function staffBookings(): string
    {
        return self::version(DB::selectOne("
            SELECT COUNT(DISTINCT b.id) AS n, MAX(b.updated_at) AS at, MAX(t.id) AS tl
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            LEFT JOIN booking_timeline t ON t.booking_id = b.id
        " . staff_venue_sql('r.venue_id IN (%s)') . staff_hall_sql('r.id')));
    }

    /* One customer's own bookings. */
    public static function customerBookings(int $customerId): string
    {
        return self::version(DB::selectOne("
            SELECT COUNT(DISTINCT b.id) AS n, MAX(b.updated_at) AS at, MAX(t.id) AS tl
            FROM bookings b
            LEFT JOIN booking_timeline t ON t.booking_id = b.id
            WHERE b.customer_id = ?
        ", [$customerId]));
    }

    /* The person's notifications: a new one, or one read in another tab. */
    public static function bell(int $userId): string
    {
        $row = DB::selectOne("
            SELECT COUNT(*) AS n, MAX(n.id) AS newest, COALESCE(SUM(n.read_at IS NULL), 0) AS unread
            FROM notifications n
            WHERE n.user_id = ?" . staff_news_sql() . "
        ", [$userId]);
        return $row->n . '.' . $row->newest . '.' . $row->unread;
    }

    private static function version(object $row): string
    {
        return $row->n . '.' . $row->at . '.' . $row->tl;
    }
}
