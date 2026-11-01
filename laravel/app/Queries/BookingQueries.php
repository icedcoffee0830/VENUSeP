<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL that reads and changes a booking for the staff actions (BookingActionController).
   Columns: venusep_schema.sql (search "CREATE TABLE <name>").

   bookings: one row per booking request, venue or hostel.
     reservation_status: pending, approved, rejected, cancelled, released, completed, disrupted
     payment_status: see the payment_statuses table, and app/Support/Status.php
   hostel_booking_details: one row per hostel booking: the stay's dates, POS, Official Receipt,
     check-in and check-out.
   booking_timeline: the booking's History, one row per event.
   Read only: rooms, venues, customers.

   Triggers that fire here:
     trg_bookings_after_update: on a status change, writes the booking's History line
       and frees its dates when the booking closes.
     trg_bookings_type_upd: refuses a booking whose type does not match its room.
     trg_booking_timeline_notify: a new History line may notify the customer or staff. */
class BookingQueries
{
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
                   COALESCE(b.quoted_discount_percent, v.discount_percent) AS quoted_discount
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
}
