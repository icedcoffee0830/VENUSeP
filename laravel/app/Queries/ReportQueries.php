<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind Quarterly Reports and the Dashboard's revenue tile (DB-DECISIONS 28).
   Every figure is read for a list of offices (venue ids): the report's office filter is
   the plain `r.venue_id IN (...)` in base(), never a database view. The caller decides
   the list from who is signed in (ReportsController); nothing here widens it.

   A booking counts toward the quarter its EVENT falls in (the venue booking's first day,
   the hostel stay's check-in), not the quarter it was booked or paid in.
   Money comes from each booking's own snapshotted total_amount, never today's prices.
   REVENUE counts money actually collected, and keeps counting it until its refund payout
   is COMPLETE ('refunded'): a refund requested, in progress or denied has not left USeP
   (DB-DECISIONS #16). Read only: bookings, rooms, venues and their detail tables. */
class ReportQueries
{
    /* The offices a report can be about, oldest first: the ids given, or every office. */
    public static function offices(?array $ids): array
    {
        return array_map(fn ($row) => (array) $row, DB::select("
            SELECT v.id, v.venue_code, v.name, v.venue_type, v.office_group
            FROM venues v
            " . ($ids === null ? '' : 'WHERE v.id IN (' . self::idList($ids) . ')') . "
            ORDER BY v.id
        "));
    }

    /* The last $count quarters up to and including the current one, oldest first, with
       the office totals. Quarters with no business still appear, as zeroes: a missing
       quarter would compress the x-axis and make a quiet period look like growth.
       $now (a timestamp) is "today"; tests pass a fixed date.
       customers: each customer once, however many offices of the list they booked at.
       $halls: narrow to these rooms (a staff member's dashboard); Reports leaves it null. */
    public static function quarters(array $offices, int $count = 4, ?int $now = null, ?array $halls = null): array
    {
        $now ??= time();
        /* The window starts from the FIRST DAY of the current quarter: "-9 months" from a
           31st overflows (Mar 31 - 9 months = "Jun 31" = Jul 1) and skips a quarter. */
        $firstMonth = 3 * ((int) ceil((int) date('n', $now) / 3) - 1) + 1;
        $cursor = mktime(0, 0, 0, $firstMonth - 3 * ($count - 1), 1, (int) date('Y', $now));
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $y = (int) date('Y', $cursor);
            $q = (int) ceil((int) date('n', $cursor) / 3);
            $next = strtotime('+3 months', $cursor);
            $out["$y-$q"] = ['label' => 'Q' . $q . ' ' . $y, 'year' => $y, 'quarter' => $q,
                'days' => (int) round(($next - $cursor) / 86400),
                'revenue' => 0.0, 'avg' => 0.0, 'bookings' => 0, 'cancelled' => 0, 'customers' => 0,
                'refunds' => 0, 'refunded' => 0.0, 'write_offs' => 0, 'written_off' => 0.0];
            $cursor = $next;
        }

        $rows = DB::select("
            SELECT YEAR(x.event_date) AS y, QUARTER(x.event_date) AS q,
                   SUM(x.collected) AS revenue,
                   SUM(x.fell_through = 0) AS bookings,
                   SUM(x.fell_through) AS cancelled,
                   SUM(x.collected > 0) AS paid_bookings,
                   COUNT(DISTINCT IF(x.fell_through = 0, x.customer_id, NULL)) AS customers,
                   SUM(x.refunded > 0) AS refunds,
                   SUM(x.refunded) AS refunded,
                   SUM(x.written_off > 0) AS write_offs,
                   SUM(x.written_off) AS written_off
            FROM (" . self::base($offices) . ") x
            WHERE x.event_date IS NOT NULL" . ($halls === null ? '' : ' AND x.room_id IN (' . self::idList($halls) . ')') . "
            GROUP BY y, q
        ");
        foreach ($rows as $r) {
            $key = $r->y . '-' . $r->q;
            if (!isset($out[$key])) {
                continue;                       // outside the window we are charting
            }
            $out[$key] = array_merge($out[$key], [
                'revenue' => (float) $r->revenue,
                /* per PAID booking: dividing collected money by bookings that never paid
                   understates every quarter */
                'avg' => $r->paid_bookings > 0 ? round((float) $r->revenue / (int) $r->paid_bookings) : 0.0,
                'bookings' => (int) $r->bookings,
                'cancelled' => (int) $r->cancelled,
                'customers' => (int) $r->customers,
                'refunds' => (int) $r->refunds,
                'refunded' => (float) $r->refunded,
                'write_offs' => (int) $r->write_offs,
                'written_off' => (float) $r->written_off,
            ]);
        }
        return array_values($out);
    }

    /* One row per hall or hostel room of the offices, for the breakdown charts and tables.
       Pass a year and quarter to narrow it; omit both for all time.
       events: approved or completed only (a booking that fell through, is pending or whose
         room was closed is not an event held); days, hours and bed_nights count those too.
       EVERY ACTIVE ROOM APPEARS, even with nothing in it: a quiet room and a removed one
       must not look the same. An inactive one appears while it has bookings in the period,
       marked "(inactive)", so the rows still add up to the quarter's totals.
       ponytail: a stay's bed-nights all count in its check-in quarter, so a stay across a
       quarter's end can push occupancy past 100%; split by bed_reservation_nights if that matters. */
    public static function rooms(array $offices, ?int $year = null, ?int $quarter = null): array
    {
        $period = $year !== null && $quarter !== null
            ? 'AND YEAR(x.event_date) = ' . (int) $year . ' AND QUARTER(x.event_date) = ' . (int) $quarter
            : '';
        $rows = DB::select("
            SELECT r.is_active = 1 AND v.is_active = 1 AS active,
                   CASE WHEN r.deleted_at IS NOT NULL THEN CONCAT(r.name, ' (deleted)')
                        WHEN r.is_active = 1 AND v.is_active = 1 THEN r.name
                        ELSE CONCAT(r.name, ' (inactive)') END AS name,
                   v.name AS office, r.room_type AS kind, h.cr_type AS bathroom,
                   (SELECT COUNT(*) FROM hostel_beds hb WHERE hb.room_id = r.id AND hb.is_active = 1) AS beds,
                   COALESCE(SUM(x.held), 0) AS events,
                   COALESCE(SUM(x.discount_percent > 0), 0) AS discounted,
                   COALESCE(SUM(x.fell_through), 0) AS cancelled,
                   COALESCE(SUM(x.collected), 0) AS revenue,
                   COALESCE(SUM(IF(x.held, x.days, 0)), 0) AS days,
                   COALESCE(SUM(IF(x.held, x.hours, 0)), 0) AS hours,
                   COALESCE(SUM(IF(x.held, x.bed_nights, 0)), 0) AS bed_nights
            FROM rooms r
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN hostel_room_details h ON h.room_id = r.id
            LEFT JOIN (" . self::base($offices) . ") x ON x.room_id = r.id $period
            WHERE r.venue_id IN (" . self::idList($offices) . ")
            GROUP BY r.id, r.name, r.is_active, r.deleted_at, v.id, v.name, v.is_active, r.room_type, h.cr_type
            HAVING active = 1 OR COUNT(x.id) > 0
            ORDER BY v.id, r.id
        ");
        /* numbers as numbers: the page adds them up in JavaScript */
        return array_map(fn ($r) => [
            'name' => $r->name, 'office' => $r->office, 'kind' => $r->kind, 'bathroom' => $r->bathroom,
            'beds' => (int) $r->beds, 'events' => (int) $r->events, 'discounted' => (int) $r->discounted,
            'cancelled' => (int) $r->cancelled, 'revenue' => (float) $r->revenue, 'days' => (int) $r->days,
            'hours' => (float) $r->hours, 'bed_nights' => (int) $r->bed_nights,
        ], $rows);
    }

    /* One derived table every report query reads: each booking of the offices with its
       event date, the money that landed, and whether it fell through.
       days / hours: a venue booking's booked days and hours (a date move replaces its
       slots, so every slot it has is one of its days). bed_nights: a hostel stay's nights
       times its guests. */
    private static function base(array $offices): string
    {
        return "
            SELECT b.id, b.room_id, b.customer_id, b.discount_percent,
                   COALESCE(vd.start_date, hd.check_in_date) AS event_date,
                   IF(b.payment_status IN ('confirmed', 'paid_cash', 'refund_requested', 'refund_correction',
                                           'refund_await_or', 'refund_processing', 'refund_denied'),
                      b.total_amount, 0) AS collected,
                   IF(b.payment_status = 'refunded', b.total_amount, 0) AS refunded,
                   IF(b.payment_status = 'written_off', b.total_amount, 0) AS written_off,
                   b.reservation_status IN ('cancelled', 'rejected', 'released') AS fell_through,
                   b.reservation_status IN ('approved', 'completed') AS held,
                   (SELECT COUNT(*) FROM venue_booking_slots s WHERE s.booking_id = b.id) AS days,
                   (SELECT COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(s.end_time, s.start_time))), 0) / 3600
                      FROM venue_booking_slots s WHERE s.booking_id = b.id) AS hours,
                   DATEDIFF(hd.check_out_date, hd.check_in_date)
                     * (SELECT COUNT(*) FROM hostel_occupants o WHERE o.booking_id = b.id) AS bed_nights
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
            LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
            WHERE r.venue_id IN (" . self::idList($offices) . ")
        ";
    }

    /* Office ids for an IN (...): whole numbers only; none matches nothing. */
    private static function idList(array $ids): string
    {
        return implode(',', array_map('intval', $ids) ?: [0]);
    }
}
