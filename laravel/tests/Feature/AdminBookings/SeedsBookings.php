<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;

/* Fresh bookings for tests that write — the same rows the database's seed
   procedures (sp_seed_booking_venue / _hostel) insert, written here because
   those procedures fail on this schema (collation mix between their VARCHAR
   parameters and the utf8mb4_unicode_ci columns). Far in the future, so they
   never collide with the snapshot's rows or each other, and no test depends
   on what another one wrote. */
trait SeedsBookings
{
    private function customerId(): int
    {
        return (int) DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.email', 'customer@gmail.com')->value('c.id');
    }

    /** @return array{0:int,1:string} [id, VB-reference] */
    protected function seedVenueBooking(string $room = 'r1', string $res = 'pending', string $pay = 'locked',
                                        string $method = 'gcash', bool $usep = true, bool $refunds = true): array
    {
        $r = DB::table('rooms as r')->join('event_room_details as d', 'd.room_id', '=', 'r.id')
            ->where('r.room_code', $room)->select('r.id', 'd.fee_per_day')->first();
        $day = now()->addDays(random_int(400, 4000))->toDateString();
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => $this->customerId(), 'room_id' => $r->id, 'booking_type' => 'venue',
            'reservation_status' => $res, 'payment_status' => $pay, 'payment_method' => $method,
            'is_usep_affiliated' => $usep ? 1 : 0, 'affiliation_verified' => 0,
            'room_price' => $r->fee_per_day, 'discount_percent' => 0, 'discount_amount' => 0, 'total_amount' => $r->fee_per_day,
            'refunds_allowed' => $refunds ? 1 : 0, 'submitted_at' => now()->subDays(3),
            'approved_at' => in_array($res, ['approved', 'completed'], true) ? now()->subDays(2) : null,
        ]);
        DB::table('venue_booking_details')->insert([
            'booking_id' => $id, 'event_name' => 'Laravel test event', 'start_date' => $day, 'end_date' => $day, 'attendee_count' => 30,
        ]);
        DB::table('venue_booking_slots')->insert([
            'booking_id' => $id, 'room_id' => $r->id, 'slot_date' => $day, 'start_time' => '08:00:00', 'end_time' => '12:00:00',
        ]);
        DB::update('UPDATE bookings SET current_deadline_at = fn_payment_deadline(?) WHERE id = ?', [$id, $id]);

        return $this->bookingRef($id);
    }

    /** @return array{0:int,1:string} [id, VB-reference] */
    protected function seedHostelBooking(string $res = 'approved', string $pay = 'await_pos', string $method = 'gcash',
                                         bool $refunds = true, bool $payToUse = false): array
    {
        $r = DB::table('rooms as r')->join('hostel_room_details as d', 'd.room_id', '=', 'r.id')
            ->where('r.room_code', 'h1')->select('r.id', 'd.rate_per_head_per_night')->first();
        $in = now()->addDays(random_int(400, 4000));
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => $this->customerId(), 'room_id' => $r->id, 'booking_type' => 'hostel',
            'reservation_status' => $res, 'payment_status' => $pay, 'payment_method' => $method,
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0,
            'room_price' => $r->rate_per_head_per_night, 'discount_percent' => 0, 'discount_amount' => 0,
            'total_amount' => $r->rate_per_head_per_night, 'refunds_allowed' => $refunds ? 1 : 0, 'pay_to_use' => $payToUse ? 1 : 0,
            'submitted_at' => now()->subDays(2), 'approved_at' => $res === 'approved' ? now()->subDay() : null,
        ]);
        DB::table('hostel_booking_details')->insert([
            'booking_id' => $id, 'check_in_date' => $in->toDateString(), 'check_out_date' => $in->copy()->addDay()->toDateString(), 'checked_in' => 0,
        ]);
        DB::update('UPDATE bookings SET current_deadline_at = fn_payment_deadline(?) WHERE id = ?', [$id, $id]);

        return $this->bookingRef($id);
    }

    protected function bookingRef(int $id): array
    {
        $year = DB::table('bookings')->where('id', $id)->value(DB::raw('YEAR(submitted_at)'));

        return [$id, 'VB-' . $year . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT)];
    }

    protected function booking(int $id): object
    {
        return DB::table('bookings')->where('id', $id)->first();
    }

    /* A staff-side POST with a valid CSRF token (header, as the page's fetch sends it). */
    protected function postWithToken(string $url, array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')->post($url, $data);
    }
}
