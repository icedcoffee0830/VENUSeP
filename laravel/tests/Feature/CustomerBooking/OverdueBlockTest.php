<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Aron, 2026-10-30 (post-pay): a customer with a booking whose payment is Overdue
   cannot make NEW bookings online until it is paid — booking-submit refuses, the
   booking pages say so up front, and it unblocks by itself once paid. At the
   counter staff see the same warning but may still book. */
class OverdueBlockTest extends TestCase
{
    private const EMAIL = 'aronerfe0830@gmail.com';     /* no overdue booking in the snapshot */
    private int $bookingId = 0;
    private int $customerId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->customerId = (int) DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')
            ->where('u.email', self::EMAIL)->value('c.id');
        $room = DB::table('rooms')->where('room_code', 'r5')->value('id');
        $this->bookingId = DB::table('bookings')->insertGetId([
            'customer_id' => $this->customerId, 'room_id' => $room, 'booking_type' => 'venue',
            'reservation_status' => 'completed', 'payment_status' => 'overdue', 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0,
            'room_price' => 1234.5, 'discount_percent' => 0, 'discount_amount' => 0, 'total_amount' => 1234.5,
            'refunds_allowed' => 0, 'submitted_at' => '2026-09-01 10:00:00', 'approved_at' => '2026-09-02 10:00:00',
            'current_deadline_at' => '2026-09-13 23:59:59',
        ]);
        DB::table('venue_booking_details')->insert([
            'booking_id' => $this->bookingId, 'event_name' => 'Overdue test', 'start_date' => '2026-09-10', 'end_date' => '2026-09-10', 'attendee_count' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        DB::delete('DELETE FROM bookings WHERE id = ?', [$this->bookingId]);
        parent::tearDown();
    }

    private function notice(): string
    {
        return 'You have an unpaid booking (USeP Gymnasium, Sep 10, 2026 — ₱1,234.50). Please settle it before making a new booking.';
    }

    private function submit()
    {
        $day = date('Y-m-d', strtotime('+' . random_int(400, 1400) . ' days'));
        return $this->actingAsCustomer(self::EMAIL)->withSession(['_token' => 'test-token'])
            ->post(route('customer.booking-submit'), [
                '_token' => 'test-token', 'type' => 'venue', 'room' => 'r5', 'event_name' => 'Blocked?',
                'date_start' => $day, 'date_end' => $day, 'attendees' => '50',
                'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
                'affiliated' => '0', 'method' => 'gcash', 'agree_no_refund' => '1',
                /* no ID: once unblocked, the next check answers instead (nothing is written) */
            ]);
    }

    public function test_booking_submit_refuses_until_the_overdue_booking_is_paid(): void
    {
        $before = DB::table('bookings')->count();
        $this->submit()->assertStatus(409)->assertExactJson(['ok' => false, 'error' => 'overdue', 'message' => $this->notice()]);
        $this->assertSame($before, DB::table('bookings')->count());

        DB::update("UPDATE bookings SET payment_status = 'confirmed' WHERE id = ?", [$this->bookingId]);
        $this->submit()->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'A valid ID is required to submit a booking.']);
    }

    public function test_the_booking_pages_show_the_notice_up_front(): void
    {
        $e = e($this->notice());
        foreach ([route('customer.venues'), route('customer.room-reservation', ['room' => 'r1']), route('customer.hostel-reservation', ['room' => 'h1'])] as $url) {
            $this->actingAsCustomer(self::EMAIL)->get($url)->assertOk()->assertSee($e, false)
                ->assertSee('href="' . route('customer.booking-history') . '"', false);
        }
        DB::update("UPDATE bookings SET payment_status = 'confirmed' WHERE id = ?", [$this->bookingId]);
        $this->actingAsCustomer(self::EMAIL)->get(route('customer.room-reservation', ['room' => 'r1']))->assertOk()
            ->assertDontSee('You have an unpaid booking');
    }

    /* Staff at the counter get every customer's warning for the "who" step, and may still book. */
    public function test_the_counter_warns_but_still_books(): void
    {
        $html = $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk()
            ->assertDontSee('You have an unpaid booking')->getContent();
        $this->assertSame(1, preg_match('/const COUNTER_OVERDUE = (\{.*?\});\n/', $html, $m));
        $this->assertSame('USeP Gymnasium, Sep 10, 2026 — ₱1,234.50', json_decode($m[1], true)[$this->customerId] ?? null);
        $this->assertStringContainsString('${COUNTER_OVERDUE[b.customerId] ?', $html);   /* counter-booker.php draws it */

        $day = now()->addDays(random_int(400, 4000))->toDateString();
        $res = $this->withSession(['_token' => 'tok', 'counter_proof' => [$this->customerId => time()]])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.booking-create'), [
                'type' => 'venue', 'room' => 'r1', 'method' => 'cash', 'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1',
                'booker_mode' => 'account', 'customer_id' => (string) $this->customerId,
                'event_name' => 'Counter despite overdue', 'date_start' => $day, 'date_end' => $day, 'attendees' => '40',
                'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
            ]);
        $res->assertOk()->assertJson(['ok' => true]);
        DB::delete('DELETE FROM bookings WHERE id = ?', [$res->json('bookingId')]);
    }
}
