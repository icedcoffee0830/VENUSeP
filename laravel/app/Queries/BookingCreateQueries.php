<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL that makes a new booking (online and at the counter), checks the rules before it,
   and lets a customer cancel or send back their own request.
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   bookings: one row per booking request, venue or hostel (notes in BookingQueries).
   venue_booking_details: the event; venue_booking_slots: one row per day it holds its room.
   hostel_booking_details: the stay's dates; hostel_occupants: one row per guest.
   bed_reservations, bed_reservation_nights: the bed each guest has, and each night of it.
   booking_timeline: the booking's History, one row per event.
   Read only: rooms, venues, event_room_details, hostel_room_details, hostel_beds.

   Triggers that fire here: trg_bookings_block_ins refuses a booking at an office that blocked
   the customer, trg_bookings_room_deleted_ins one on a deleted room; on a status change
   trg_bookings_after_update writes the History line.
   Procedures: sp_expire_due_bookings (the deadline housekeeping), sp_add_venue_slot (the real
   guard against a taken slot), sp_approve_booking; fn_payment_deadline works out a pay-by date,
   fn_room_hard_blocked tells whether a room is closed on a day. */
class BookingCreateQueries
{
    use SavesWithHistory;

    /* The room a booking is for, with its rate as this server knows it, or null. */
    public static function findRoom(string $roomCode): ?array
    {
        $row = DB::selectOne("
            SELECT r.id, r.venue_id, r.name, r.room_type, r.is_active, v.discount_percent,
                   erd.attendee_capacity, erd.fee_per_day,
                   hrd.rate_per_head_per_night
            FROM rooms r
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN event_room_details erd ON erd.room_id = r.id
            LEFT JOIN hostel_room_details hrd ON hrd.room_id = r.id
            WHERE r.room_code = ?
        ", [$roomCode]);
        return $row ? (array) $row : null;
    }

    /* How many beds of a hostel room are in use. */
    public static function countActiveBeds(int $roomId): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) AS n
            FROM hostel_beds
            WHERE room_id = ? AND is_active = 1
        ", [$roomId])->n;
    }

    /* Whether a room is closed for the whole of that day. */
    public static function isHardBlocked(int $roomId, string $day): bool
    {
        return (int) DB::selectOne('SELECT fn_room_hard_blocked(?, ?) AS blocked', [$roomId, $day])->blocked === 1;
    }

    /* How many unpaid bookings still to come a customer holds at one office. */
    public static function countUnpaidUpcoming(int $customerId, int $venueId): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) AS n
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            WHERE b.customer_id = ? AND r.venue_id = ?
              AND b.reservation_status IN ('pending', 'approved')
              AND b.payment_status IN ('locked', 'await_event', 'await_gcash', 'await_cash', 'await_pos')
              AND fn_booking_last_day(b.id) >= CURDATE()
        ", [$customerId, $venueId])->n;
    }

    /* Has the event begun? A venue booking at its first slot; a hostel stay once its first
       day has passed or the guest is in. */
    public static function eventStarted(int $bookingId): bool
    {
        return (bool) DB::selectOne("
            SELECT COALESCE(
                (SELECT TIMESTAMP(s.slot_date, s.start_time) FROM venue_booking_slots s
                  WHERE s.booking_id = ? ORDER BY s.slot_date, s.start_time LIMIT 1) <= NOW(),
                (SELECT h.checked_in = 1 OR h.check_in_date < CURDATE() FROM hostel_booking_details h
                  WHERE h.booking_id = ?),
                0) AS started
        ", [$bookingId, $bookingId])->started;
    }

    /* The Overdue bookings of one office, of one customer, or of everyone: oldest deadline first. */
    public static function overdueBookings(?string $roomCode, ?int $customerId): array
    {
        $rows = DB::select("
            SELECT b.customer_id, b.room_id, r.venue_id, r.name, v.name AS office,
                   fn_booking_first_day(b.id) AS first_day, b.total_amount
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN venues v ON v.id = r.venue_id
            WHERE b.payment_status = 'overdue'"
            . ($roomCode === null ? '' : ' AND r.venue_id = (SELECT venue_id FROM rooms WHERE room_code = ?)')
            . ($customerId === null ? '' : ' AND b.customer_id = ?') . "
            ORDER BY b.current_deadline_at, b.id
        ", array_values(array_filter([$roomCode, $customerId], fn ($v) => $v !== null)));
        return array_map(fn ($r) => (array) $r, $rows);
    }

    /* A new booking's save, all or nothing. The deadline housekeeping runs just BEFORE it,
       never inside (migration 15): its UPDATEs lock every bookings row they scan, so inside
       the transaction two submits a moment apart deadlocked on each other's new booking row. */
    public static function saveNew(callable $save): mixed
    {
        self::expireDue();
        return DB::transaction($save);
    }

    /* The deadline rules, applied before anyone looks. */
    public static function expireDue(): void
    {
        DB::statement('CALL sp_expire_due_bookings()');
    }

    /* The system-wide cleanup gap, as stored; null when it is not set. */
    public static function cleanupGapSetting(): ?string
    {
        return DB::selectOne("SELECT setting_value FROM system_settings WHERE setting_key = 'venue_cleanup_gap_minutes'")->setting_value ?? null;
    }

    /* The booking row, and what it keeps for later: the rate shown right now, and who it is
       for. Keys of $b: customer, room, type, method, affiliated, verified, price, percent,
       discount, total, refunds, actor (null online), quotedPercent, organization. */
    public static function addBooking(array $b): int
    {
        DB::insert("
            INSERT INTO bookings
                (customer_id, room_id, booking_type, reservation_status, payment_status,
                 payment_method, is_usep_affiliated, affiliation_verified,
                 room_price, discount_percent, discount_amount, total_amount,
                 refunds_allowed, updated_by_user_id)
            VALUES (?, ?, ?, 'pending', 'locked', ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$b['customer'], $b['room'], $b['type'], $b['method'], $b['affiliated'], $b['verified'],
            $b['price'], $b['percent'], $b['discount'], $b['total'], $b['refunds'], $b['actor']]);
        $id = (int) DB::getPdo()->lastInsertId();

        $organization = mb_substr(trim((string) $b['organization']), 0, 190);
        self::mustChange('addBooking', DB::update("
            UPDATE bookings
            SET quoted_discount_percent = ?,
                organization_name = ?
            WHERE id = ?
        ", [$b['quotedPercent'], $organization !== '' ? $organization : null, $id]));
        return $id;
    }

    /* A venue booking's event, and its days through sp_add_venue_slot: it takes a room+date
       lock, re-checks closures and overlapping holds, and SIGNALs if the slot is gone.
       Each of $slots: date, start, end. */
    public static function addEvent(int $bookingId, string $name, string $start, string $end, int $attendees, array $slots): void
    {
        DB::insert("
            INSERT INTO venue_booking_details (booking_id, event_name, start_date, end_date, attendee_count)
            VALUES (?, ?, ?, ?, ?)
        ", [$bookingId, $name, $start, $end, $attendees]);
        foreach ($slots as $s) {
            DB::statement('CALL sp_add_venue_slot(?, ?, ?, ?)', [$bookingId, $s['date'], $s['start'], $s['end']]);
        }
    }

    /* A hostel booking's stay and its guests (the first is the primary guest): the guests' ids. */
    public static function addStay(int $bookingId, string $checkIn, string $checkOut, array $guests): array
    {
        DB::insert("
            INSERT INTO hostel_booking_details (booking_id, check_in_date, check_out_date, checked_in)
            VALUES (?, ?, ?, 0)
        ", [$bookingId, $checkIn, $checkOut]);
        $ids = [];
        foreach ($guests as $i => $g) {
            DB::insert("
                INSERT INTO hostel_occupants (booking_id, full_name, gender, is_primary_guest)
                VALUES (?, ?, ?, ?)
            ", [$bookingId, $g['name'], $g['gender'], $i === 0 ? 1 : 0]);
            $ids[] = (int) DB::getPdo()->lastInsertId();
        }
        return $ids;
    }

    /* Work out the pay-by deadline, once the booking's days are in. */
    public static function setPayDeadline(int $bookingId): void
    {
        self::mustChange('setPayDeadline', DB::update("
            UPDATE bookings
            SET current_deadline_at = fn_payment_deadline(?)
            WHERE id = ?
        ", [$bookingId, $bookingId]));
    }

    /* A History line at the counter: each one is a judgement a person made, with their name on it. */
    public static function addHistory(int $bookingId, string $code, int $actor, string $note): void
    {
        self::writeHistory($bookingId, $code, $actor, $note);
    }

    /* Approved on the spot, through the one procedure that knows what "approved" means. */
    public static function approveNow(int $bookingId, int $actor): void
    {
        DB::statement('CALL sp_approve_booking(?, ?)', [$bookingId, $actor]);
    }

    /* Take away a booking that could not be finished. */
    public static function remove(int $bookingId): void
    {
        DB::delete('DELETE FROM bookings WHERE id = ?', [$bookingId]);
    }

    /* When the booking came in and its deadline, for the reply. */
    public static function findDates(int $bookingId): array
    {
        return (array) DB::selectOne("
            SELECT submitted_at, current_deadline_at
            FROM bookings
            WHERE id = ?
        ", [$bookingId]);
    }

    /* The customer's own booking, or null. */
    public static function findOwn(int $bookingId, int $customerId): ?array
    {
        $row = DB::selectOne("
            SELECT b.id, b.room_id, b.booking_type, b.reservation_status, fn_booking_first_day(b.id) AS first_day
            FROM bookings b
            WHERE b.id = ? AND b.customer_id = ?
        ", [$bookingId, $customerId]);
        return $row ? (array) $row : null;
    }

    /* The customer cancels a request nobody approved yet. False when it is no longer pending. */
    public static function cancelPending(int $bookingId, int $actor): bool
    {
        return self::saveAs($actor, 'Request cancelled by the customer.', fn () => DB::update("
            UPDATE bookings
            SET reservation_status = 'cancelled',
                cancelled_at = NOW()
            WHERE id = ? AND reservation_status = 'pending'
        ", [$bookingId])) > 0;
    }

    /* A rejected request goes back to staff. A venue booking takes its days again through
       sp_add_venue_slot, which refuses a day taken since. */
    public static function reopenRejected(int $bookingId, bool $isVenue, int $actor): void
    {
        self::saveNew(function () use ($bookingId, $isVenue, $actor) {
            self::signedAs($actor, 'A new ID was sent; the request is back with staff.', fn () => self::mustChange('reopenRejected', DB::update("
                UPDATE bookings
                SET reservation_status = 'pending',
                    payment_status = 'locked'
                WHERE id = ?
            ", [$bookingId])));
            if ($isVenue) {
                $days = DB::select("
                    SELECT slot_date, start_time, end_time
                    FROM venue_booking_slots
                    WHERE booking_id = ?
                ", [$bookingId]);
                DB::delete('DELETE FROM venue_booking_slots WHERE booking_id = ?', [$bookingId]);
                foreach ($days as $d) {
                    DB::statement('CALL sp_add_venue_slot(?, ?, ?, ?)', [$bookingId, $d->slot_date, $d->start_time, $d->end_time]);
                }
            }
            self::setPayDeadline($bookingId);
        });
    }

    /* A reopened stay leaves its old beds; BookingRules::assignBeds gives it new ones. */
    public static function clearBeds(int $bookingId): void
    {
        /* none is normal: a rejected stay has already let its beds go */
        DB::delete('DELETE FROM bed_reservation_nights WHERE booking_id = ?', [$bookingId]);
        DB::delete('DELETE FROM bed_reservations WHERE booking_id = ?', [$bookingId]);
    }

    /* The stay could not get its beds back: rejected again, as it was. */
    public static function putBackRejected(int $bookingId): void
    {
        self::mustChange('putBackRejected', DB::update("
            UPDATE bookings
            SET reservation_status = 'rejected',
                payment_status = 'locked'
            WHERE id = ?
        ", [$bookingId]));
    }
}
