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
}
