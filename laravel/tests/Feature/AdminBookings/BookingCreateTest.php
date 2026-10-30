<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/booking-create — the walk-in counter booking (JSON). */
class BookingCreateTest extends TestCase
{
    use SeedsBookings;

    private function create(array $data)
    {
        return $this->postWithToken(route('admin.booking-create'), $data);
    }

    /* A walk-in venue booking far in the future (the 12-hour rule, no clashes). */
    private function walkInVenue(string $room = 'r1', array $extra = []): array
    {
        $day = now()->addDays(random_int(400, 4000))->toDateString();
        return $extra + [
            'type' => 'venue', 'room' => $room, 'method' => 'gcash', 'id_checked' => '1', 'usep_id' => '1',
            'agree_no_refund' => '1', 'booker_mode' => 'walkin',
            'walkin_name' => 'Laravel Walk-in', 'walkin_phone' => '09171234567', 'walkin_email' => 'walkin@example.test',
            'event_name' => 'Counter test', 'date_start' => $day, 'date_end' => $day, 'attendees' => '40',
            'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
        ];
    }

    public function test_get_is_405_and_non_staff_403(): void
    {
        $this->get(route('admin.booking-create'))->assertStatus(405)->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->actingAsCustomer()->post(route('admin.booking-create'), $this->walkInVenue())
            ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Only staff can take a counter booking.']);
    }

    public function test_no_id_check_is_refused(): void
    {
        $this->actingAsStaff()->create(['id_checked' => ''] + $this->walkInVenue())
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Confirm you have seen a valid ID before creating the booking.']);
    }

    public function test_staff_cannot_book_another_venues_room(): void
    {
        $this->actingAsStaff()->create($this->walkInVenue('r5'))->assertStatus(403)
            ->assertJson(['message' => 'This room belongs to another venue. Only that venue\'s staff or an administrator can book it at the counter.']);
    }

    public function test_an_account_booking_needs_the_password_proof(): void
    {
        $data = ['booker_mode' => 'account', 'customer_id' => '1'] + $this->walkInVenue();
        $this->actingAsStaff()->create($data)->assertStatus(403)
            ->assertExactJson(['ok' => false, 'message' => 'Ask the customer to confirm their password again before booking.']);
    }

    public function test_account_booking_with_a_fresh_proof_spends_it(): void
    {
        $data = ['booker_mode' => 'account', 'customer_id' => '1'] + $this->walkInVenue();
        $res = $this->actingAsStaff()->withSession(['counter_proof' => [1 => time()]])->create($data);
        $res->assertOk()->assertJson(['ok' => true, 'message' => 'Booking created and approved.']);
        $this->assertSame([], session('counter_proof'));
        $this->assertSame(1, (int) $this->booking($res->json('bookingId'))->customer_id);
    }

    public function test_walk_in_venue_booking_is_approved_discounted_and_emailed_to_the_log(): void
    {
        $res = $this->actingAsStaff()->create($this->walkInVenue());
        $res->assertOk()->assertJson(['ok' => true, 'message' => 'Booking created and approved.']);
        $id = $res->json('bookingId');
        $this->assertMatchesRegularExpression('/^VB-\d{4}-\d+$/', $res->json('reference'));
        $b = $this->booking($id);
        $this->assertSame('approved', $b->reservation_status);
        $this->assertSame('cash', $b->payment_method);                // a walk-in pays at the counter
        $this->assertEquals(20, $b->discount_percent);                 // staff saw the USeP ID
        $this->assertEquals(5000 * 0.8, (float) $b->total_amount);
        $this->assertSame(1, DB::table('venue_booking_slots')->where('booking_id', $id)->count());
        $this->assertSame(2, DB::table('booking_timeline')->where('booking_id', $id)->where('action_code', 'like', 'counter_%')->count());
        $mail = DB::table('email_outbox')->where('booking_id', $id)->first();
        $this->assertSame('walkin_booking', $mail->kind);
        $this->assertSame('sent', $mail->status);                      // the 'log' transport: nothing left the machine
        @unlink(getenv('VENUSEP_DOC_ROOT') . '/mail-log/' . $mail->id . '.eml');
    }

    public function test_walk_in_without_email_must_say_so(): void
    {
        $this->actingAsStaff()->create(['walkin_email' => ''] + $this->walkInVenue())->assertStatus(400)
            ->assertJson(['message' => 'Enter the guest&rsquo;s email address, or tick &ldquo;Guest has no email&rdquo; and print the receipt.']);
    }

    public function test_hostel_stay_needs_the_cash_first_then_is_paid(): void
    {
        $in = now()->addDays(random_int(400, 4000));
        /* a far, free date: lift the hostel advance limit for this test only */
        $limit = DB::table('system_settings')->where('setting_key', 'hostel_advance_booking_max_days')->value('setting_value');
        DB::update("UPDATE system_settings SET setting_value = '5000' WHERE setting_key = 'hostel_advance_booking_max_days'");
        $this->beforeApplicationDestroyed(fn () => DB::update(
            "UPDATE system_settings SET setting_value = ? WHERE setting_key = 'hostel_advance_booking_max_days'", [$limit]));
        $data = [
            'type' => 'hostel', 'room' => 'h1', 'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1',
            'booker_mode' => 'walkin', 'walkin_name' => 'Hostel Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
            'check_in' => $in->toDateString(), 'check_out' => $in->copy()->addDays(2)->toDateString(),
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']]),
        ];
        $this->actingAsAdmin()->create($data)->assertStatus(400)
            ->assertExactJson(['ok' => false, 'message' => 'Take the payment first: a stay is only booked once the cash is received.']);
        $res = $this->create(['cash_received' => '1'] + $data);
        $res->assertOk()->assertJson(['ok' => true, 'paid' => true, 'message' => 'Stay booked and paid.']);
        $b = $this->booking($res->json('bookingId'));
        $this->assertSame('paid_cash', $b->payment_status);
        $this->assertEquals(700, (float) $b->total_amount);            // 350 x 1 bed x 2 nights
        $this->assertSame(1, DB::table('system_receipts')->where('booking_id', $b->id)->count());
        $this->assertSame(1, DB::table('booking_timeline')->where('booking_id', $b->id)->where('action_code', 'counter_no_email')->count());
    }
}
