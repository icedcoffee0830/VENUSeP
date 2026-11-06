<?php

namespace App\Queries;

use App\Support\Status;
use Illuminate\Support\Facades\DB;

/* The SQL that reads and changes a booking for the staff actions (BookingActionController).
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   bookings: one row per booking request, venue or hostel.
     reservation_status: pending, approved, rejected, cancelled, released, completed, disrupted
     payment_status: see the payment_statuses table, and app/Support/Status.php
   hostel_booking_details: one row per hostel booking: the stay's dates, POS, Official Receipt,
     check-in and check-out.
   venue_booking_details: one row per venue booking: the event and its first and last day.
   venue_booking_slots: one row per day a venue booking holds its room.
   bed_reservations, bed_reservation_nights: the bed each hostel guest has, and each night of it.
   booking_timeline: the booking's History, one row per event.
   Read only: rooms, venues, customers, hostel_occupants, and gcash_receipts (counted).
   notifications are written by the legacy notify_* helpers (legacy/includes/notifications.php).

   Triggers that fire here:
     trg_bookings_after_update: on a status change, writes the booking's History line
       and frees its dates when the booking closes.
     trg_bookings_type_upd: refuses a booking whose type does not match its room.
     trg_booking_timeline_notify: a new History line may notify the customer or staff.
   Procedures: sp_approve_booking, sp_add_venue_slot; fn_payment_deadline works out a pay-by date,
   fn_customer_blocked tells whether the customer is blocked at the booking's office. */
class BookingQueries
{
    /* The bookings this session must act on: the sidebar's count, the queue's "need action" and
       its tabs (booking_queue_category()). Narrowed like every staff list. */
    public static function needsAction(): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) AS n FROM bookings b JOIN rooms r ON r.id = b.room_id
            WHERE (b.reservation_status = 'pending'
                   OR b.payment_status IN ('await_pos', 'overdue', 'refund_requested', 'refund_await_or', 'under_review'))
        " . staff_venue_sql('r.venue_id IN (%s)', 'AND') . staff_hall_sql('r.id'))->n;
    }

    use SavesWithHistory;

    /* The booking a staff action is about, as it is right now, or null. */
    public static function findForStaffAction(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT b.id, b.booking_type, b.reservation_status, b.payment_status, b.payment_method,
                   b.is_usep_affiliated, b.room_price, b.total_amount, b.refunds_allowed, b.pay_to_use,
                   b.approved_at, b.write_off_reason, b.deadline_extensions, b.date_moves,
                   b.room_id, r.venue_id,
                   c.user_id AS customer_user_id,
                   COALESCE(b.quoted_discount_percent, v.discount_percent) AS quoted_discount,
                   fn_customer_blocked(b.customer_id, b.room_id) AS office_blocked
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            JOIN venues v ON v.id = r.venue_id
            LEFT JOIN customers c ON c.id = b.customer_id
            WHERE b.id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }

    /* A hostel booking's stay: its dates, whether it starts after today, check-in and check-out. */
    public static function findHostelStay(int $id): ?array
    {
        $row = DB::selectOne("
            SELECT h.check_in_date, h.check_out_date,
                   h.check_in_date > CURDATE() AS early,
                   h.checked_in, h.checked_out
            FROM hostel_booking_details h
            WHERE h.booking_id = ?
        ", [$id]);
        return $row ? (array) $row : null;
    }

    /* How many GCash receipts the booking may upload, and how many it has. */
    public static function findReceiptAttempts(int $id): array
    {
        return (array) DB::selectOne("
            SELECT b.receipt_attempts_allowed AS allowed,
                   (SELECT COUNT(*) FROM gcash_receipts g WHERE g.booking_id = b.id) AS used
            FROM bookings b
            WHERE b.id = ?
        ", [$id]);
    }

    /* How many days a venue booking holds now (its slots not yet released). */
    public static function countHeldVenueDays(int $id): int
    {
        return (int) DB::selectOne("
            SELECT COUNT(*) AS days
            FROM venue_booking_slots s
            WHERE s.booking_id = ?
              AND s.released_at IS NULL
        ", [$id])->days;
    }

    /* The guests on a hostel booking, in the order they were added. */
    public static function findOccupantIds(int $id): array
    {
        return array_map(fn ($row) => (int) $row->id, DB::select("
            SELECT o.id
            FROM hostel_occupants o
            WHERE o.booking_id = ?
            ORDER BY o.id
        ", [$id]));
    }

    /* The database's time 48 hours from now: deadlines run on the database's clock. */
    public static function timeIn48Hours(): string
    {
        return DB::selectOne('SELECT DATE_ADD(NOW(), INTERVAL 48 HOUR) AS until')->until;
    }

    /* Approve a pending request: save the discount, then let sp_approve_booking
       set its status and payment deadline. */
    public static function approve(int $id, bool $idVerified, int $percent, float $discount, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $idVerified, $percent, $discount, $actor) {
            self::mustChange('approve', DB::update("
                UPDATE bookings
                SET affiliation_verified = ?,
                    discount_percent = ?,
                    discount_amount = ?,
                    total_amount = room_price - ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$idVerified ? 1 : 0, $percent, $discount, $discount, $actor, $id]));
            DB::statement('CALL sp_approve_booking(?, ?)', [$id, $actor]);
        });
    }

    /* Turn down a pending request. The History trigger frees its held dates. */
    public static function markRejected(int $id, string $staffNote, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $staffNote, $actor) {
            self::mustChange('markRejected', DB::update("
                UPDATE bookings
                SET reservation_status = 'rejected',
                    payment_status = 'locked',
                    staff_notes = ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$staffNote, $actor, $id]));
        });
    }

    /* Record a hostel stay's POS from CEDU. On a pre-pay stay waiting for it, payment opens
       (a status change: the trigger writes the History line); otherwise it is paperwork. */
    public static function savePosNumber(int $id, string $pos, bool $opensPayment, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $pos, $opensPayment, $actor, $note) {
            self::mustChange('savePosNumber', DB::update("
                UPDATE hostel_booking_details
                SET pos_number = ?,
                    pos_recorded_at = NOW()
                WHERE booking_id = ?
            ", [$pos, $id]));
            if ($opensPayment) {
                self::mustChange('savePosNumber', DB::update("
                    UPDATE bookings
                    SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                        current_deadline_at = fn_payment_deadline(?),
                        updated_by_user_id = ?
                    WHERE id = ?
                ", [$id, $actor, $id]));
            } else {
                self::writeHistory($id, 'pos_recorded', $actor, $note);
            }
        });
    }

    /* Record the Official Receipt number the University Cashier issued for a hostel stay. */
    public static function saveOfficialReceiptNumber(int $id, string $orNumber, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $orNumber, $actor, $note) {
            self::mustChange('saveOfficialReceiptNumber', DB::update("
                UPDATE hostel_booking_details
                SET official_receipt_no = ?,
                    official_receipt_recorded_at = NOW()
                WHERE booking_id = ?
            ", [$orNumber, $id]));
            self::writeHistory($id, 'or_recorded', $actor, $note);
        });
    }

    /* Mark a hostel guest as arrived. */
    public static function markCheckedIn(int $id, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $actor, $note) {
            self::mustChange('markCheckedIn', DB::update("
                UPDATE hostel_booking_details
                SET checked_in = 1,
                    checked_in_at = NOW()
                WHERE booking_id = ?
            ", [$id]));
            self::writeHistory($id, 'checked_in', $actor, $note);
        });
    }

    /* Mark a hostel guest as gone: their bed is free again from today, however early they left. */
    public static function markCheckedOut(int $id, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $actor, $note) {
            self::mustChange('markCheckedOut', DB::update("
                UPDATE hostel_booking_details
                SET checked_out = 1,
                    checked_out_at = NOW()
                WHERE booking_id = ?
            ", [$id]));
            /* none left is normal: the stay may have no bed, or its last night is past */
            DB::update("
                UPDATE bed_reservation_nights
                SET released_at = NOW()
                WHERE booking_id = ?
                  AND night_date >= CURDATE()
                  AND released_at IS NULL
            ", [$id]);
            self::writeHistory($id, 'checked_out', $actor, $note);
        });
    }

    /* Reopen an overdue payment until $until (one more extension counted). */
    public static function extendPayByDeadline(int $id, string $until, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $until, $actor) {
            self::mustChange('extendPayByDeadline', DB::update("
                UPDATE bookings
                SET payment_status = IF(payment_method = 'cash', 'await_cash', 'await_gcash'),
                    deadline_extensions = deadline_extensions + 1,
                    current_deadline_at = ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$until, $actor, $id]));
        });
    }

    /* Stop waiting for an overdue payment: the booking is released, and the History
       trigger frees its held dates or beds. */
    public static function releaseOverdue(int $id, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $actor) {
            self::mustChange('releaseOverdue', DB::update("
                UPDATE bookings
                SET reservation_status = 'released',
                    payment_status = 'expired',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $id]));
        });
    }

    /* Raise how many GCash receipts the booking may upload; the History line keeps both numbers. */
    public static function allowMoreReceiptAttempts(int $id, int $allowedBefore, int $allowedAfter, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $allowedBefore, $allowedAfter, $actor, $note) {
            self::mustChange('allowMoreReceiptAttempts', DB::update("
                UPDATE bookings
                SET receipt_attempts_allowed = ?,
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$allowedAfter, $actor, $id]));
            self::writeHistory($id, 'receipt_attempts_allowed', $actor, $note,
                ['allowed_before' => $allowedBefore, 'allowed_after' => $allowedAfter]);
        });
    }

    /* Cancel an approved booking that owes nothing yet; the History trigger frees its dates.
       The WHERE repeats that rule (the same list as Status::UNPAID), so a payment arriving a
       moment ago wins: false then, and nothing is saved. The customer and the admins are told. */
    public static function markForceCancelled(int $id, string $reason, int $actor, string $note): bool
    {
        return self::saveAs($actor, $note, function () use ($id, $reason, $actor) {
            $cancelled = DB::update("
                UPDATE bookings
                SET reservation_status = 'cancelled',
                    payment_status = 'locked',
                    cancelled_at = NOW(),
                    staff_notes = ?,
                    updated_by_user_id = ?
                WHERE id = ?
                  AND reservation_status = 'approved'
                  AND payment_status IN ('locked', 'await_event', 'await_gcash', 'await_cash', 'await_pos')
            ", [$reason, $actor, $id]);
            if ($cancelled === 0) {
                return false;
            }
            legacy('notifications');
            notify_booking_parties($id, 'booking_cancelled', $actor, ['note' => $reason]);
            return true;
        });
    }

    /* Staff ask an administrator to write off an overdue debt; the administrators are told. */
    public static function saveWriteOffRequest(int $id, string $reason, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $reason, $actor, $note) {
            self::mustChange('saveWriteOffRequest', DB::update("
                UPDATE bookings
                SET write_off_reason = ?,
                    write_off_requested_by = ?,
                    write_off_requested_at = NOW()
                WHERE id = ?
            ", [$reason, $actor, $id]));
            self::writeHistory($id, 'write_off_requested', $actor, $note);
            legacy('notifications');
            notify_refund_needs_admin($id, $actor, 'write_off_requested');
        });
    }

    /* An administrator writes the debt off: the booking is completed and owes nothing more. */
    public static function markWrittenOff(int $id, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $actor) {
            self::mustChange('markWrittenOff', DB::update("
                UPDATE bookings
                SET reservation_status = 'completed',
                    payment_status = 'written_off',
                    updated_by_user_id = ?
                WHERE id = ?
            ", [$actor, $id]));
        });
    }

    /* An administrator turns a write-off down: the request is cleared, the debt stays. */
    public static function clearWriteOffRequest(int $id, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $actor, $note) {
            self::mustChange('clearWriteOffRequest', DB::update("
                UPDATE bookings
                SET write_off_reason = NULL,
                    write_off_requested_by = NULL,
                    write_off_requested_at = NULL
                WHERE id = ?
            ", [$id]));
            self::writeHistory($id, 'write_off_declined', $actor, $note);
        });
    }

    /* Put a venue booking on new dates: its old slots go, sp_add_venue_slot adds one per new
       day (it refuses, SIGNAL 45000, a day the room or the office cannot take), and the
       booking's dates follow. Runs inside the transaction the caller opened. */
    public static function moveVenueSlots(int $id, string $from, string $to, string $start, string $end): void
    {
        self::mustChange('moveVenueSlots', DB::delete("
            DELETE FROM venue_booking_slots
            WHERE booking_id = ?
        ", [$id]));
        for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime($day . ' +1 day'))) {
            DB::statement('CALL sp_add_venue_slot(?, ?, ?, ?)', [$id, $day, $start . ':00', $end . ':00']);
        }
        self::mustChange('moveVenueSlots', DB::update("
            UPDATE venue_booking_details
            SET start_date = ?,
                end_date = ?
            WHERE booking_id = ?
        ", [$from, $to, $id]));
    }

    /* Take a hostel stay off its beds and put it on new dates. The beds are assigned
       again afterwards, outside any transaction (BookingRules::assignBeds). */
    public static function clearBedsAndSetStayDates(int $id, string $checkIn, string $checkOut, int $actor, string $note): void
    {
        self::saveAs($actor, $note, function () use ($id, $checkIn, $checkOut) {
            /* none is normal: a stay may have no guests on beds yet */
            DB::delete("
                DELETE FROM bed_reservation_nights
                WHERE booking_id = ?
            ", [$id]);
            DB::delete("
                DELETE FROM bed_reservations
                WHERE booking_id = ?
            ", [$id]);
            self::mustChange('clearBedsAndSetStayDates', DB::update("
                UPDATE hostel_booking_details
                SET check_in_date = ?,
                    check_out_date = ?
                WHERE booking_id = ?
            ", [$checkIn, $checkOut, $id]));
        });
    }

    /* Record a date move: one more move counted (an office closure does not count), the
       pay-by deadline worked out again unless it is paid, the History line, and the customer
       and admins told. Runs inside the transaction the caller opened. */
    public static function saveDateMove(int $id, bool $officeClosed, int $actor, string $note): void
    {
        self::mustChange('saveDateMove', DB::update("
            UPDATE bookings
            SET date_moves = date_moves + ?,
                current_deadline_at = IF(payment_status IN ('confirmed', 'paid_cash'), current_deadline_at, fn_payment_deadline(?))
            WHERE id = ?
        ", [$officeClosed ? 0 : 1, $id, $id]));
        self::writeHistory($id, 'date_moved', $actor, $note);
        legacy('notifications');
        notify_booking_parties($id, 'booking_moved', $actor, ['note' => $note]);
    }

    /* The words customers see for a status, in order. $table: reservation_statuses or payment_statuses. */
    public static function customerLabels(string $table): array
    {
        return array_column(DB::select("SELECT customer_label FROM {$table} ORDER BY sort_order"), 'customer_label');
    }

    /* The days the other halls of these offices are taken, for a staff member's calendar:
       dates only, so nothing else can reach the page. end_date is exclusive, as the calendar
       wants it: a venue's last day + 1, a hostel's check-out morning. */
    public static function busyDates(array $offices, array $halls): array
    {
        $ids = fn (array $list) => implode(',', array_map('intval', $list) ?: [0]);
        return DB::select("
            SELECT DISTINCT COALESCE(vd.start_date, hd.check_in_date) AS start_date,
                   COALESCE(vd.end_date + INTERVAL 1 DAY, hd.check_out_date) AS end_date
            FROM bookings b
            JOIN rooms r ON r.id = b.room_id
            LEFT JOIN venue_booking_details vd ON vd.booking_id = b.id
            LEFT JOIN hostel_booking_details hd ON hd.booking_id = b.id
            WHERE b.reservation_status IN (?, ?, ?)
              AND r.venue_id IN (" . $ids($offices) . ") AND r.id NOT IN (" . $ids($halls) . ")
              AND COALESCE(vd.start_date, hd.check_in_date) IS NOT NULL
        ", [Status::PENDING, Status::APPROVED, Status::COMPLETED]);
    }
}
