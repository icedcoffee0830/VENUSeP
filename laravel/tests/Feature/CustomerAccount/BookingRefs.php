<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;

/* Booking references are computed (VB-<year>-<id>, legacy bookings.php), not stored. */
trait BookingRefs
{
    protected function ref(int $bookingId): string
    {
        $at = DB::table('bookings')->where('id', $bookingId)->value('submitted_at');

        return 'VB-' . date('Y', strtotime($at)) . '-' . str_pad((string) $bookingId, 3, '0', STR_PAD_LEFT);
    }

    protected function firstBookingOf(int $customerId, array $where = []): ?int
    {
        $id = DB::table('bookings')->where('customer_id', $customerId)->where($where)->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
    }
    /* Booking 36 (rafael.lim@gmail.com: venue, GCash, paid, sold refundable, its
       last refund withdrawn) is the test DB's only refundable-shaped booking,
       but its event is past. Move it to a future date for a test; undo after. */
    protected function makeBooking36Refundable(): void
    {
        DB::table('venue_booking_details')->where('booking_id', 36)->update(['start_date' => '2027-06-15', 'end_date' => '2027-06-15']);
        DB::table('venue_booking_slots')->where('booking_id', 36)->update(['slot_date' => '2027-06-15']);
    }

    protected function restoreBooking36(): void
    {
        DB::table('refunds')->where('booking_id', 36)->where('id', '>', 7)->delete();
        DB::table('booking_documents')->where('booking_id', 36)->where('document_type', 'refund_support')->delete();
        DB::table('bookings')->where('id', 36)->update(['payment_status' => 'confirmed']);
        DB::table('venue_booking_details')->where('booking_id', 36)->update(['start_date' => '2026-10-15', 'end_date' => '2026-10-15']);
        DB::table('venue_booking_slots')->where('booking_id', 36)->update(['slot_date' => '2026-10-15']);
    }
}