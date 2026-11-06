<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* The testing round's logic pass: what a booking ends up as, end to end, at the edges no single
   feature's tests reach. */
class LogicPassTest extends TestCase
{
    use SeedsBookings;

    private array $restore = [];
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM system_receipts WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM payments WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        foreach ($this->restore as $undo) {
            $undo();
        }
        parent::tearDown();
    }

    private function counter(array $data)
    {
        $res = $this->actingAsAdmin()->postWithToken(route('admin.booking-create'), $data + [
            'id_checked' => '1', 'usep_id' => '1', 'agree_no_refund' => '1', 'booker_mode' => 'walkin',
            'walkin_name' => 'Rounding Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1']);
        $res->assertOk();
        $this->made[] = (int) $res->json('bookingId');

        return DB::table('bookings')->where('id', $res->json('bookingId'))->first();
    }

    /* ---- money: the counter ends on the same rounding rule as an online approval ---- */

    public function test_a_counter_stay_ends_on_the_rounded_price_and_is_paid_that_amount(): void
    {
        $id = (int) DB::table('rooms')->where('room_code', 'h1')->value('id');
        $rate = DB::table('hostel_room_details')->where('room_id', $id)->value('rate_per_head_per_night');
        $this->restore[] = fn () => DB::table('hostel_room_details')->where('room_id', $id)->update(['rate_per_head_per_night' => $rate]);
        DB::table('hostel_room_details')->where('room_id', $id)->update(['rate_per_head_per_night' => 333]);
        $percent = (float) DB::table('venues as v')->join('rooms as r', 'r.venue_id', '=', 'v.id')->where('r.id', $id)->value('v.discount_percent');
        $in = now()->addDays(random_int(20, 170));

        $b = $this->counter(['type' => 'hostel', 'room' => 'h1', 'cash_received' => '1', 'check_in' => $in->toDateString(),
            'check_out' => $in->copy()->addDay()->toDateString(), 'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']])]);

        $exact = 333 - round(333 * $percent) / 100;
        $this->assertNotEquals(floor($exact), $exact, 'the rate must give centavos, or this proves nothing');
        $this->assertEquals(ceil($exact), (float) $b->total_amount);
        $this->assertEquals(333 - ceil($exact), (float) $b->discount_amount);
        $this->assertEquals(ceil($exact), (float) DB::table('payments')->where('booking_id', $b->id)->value('amount'));
        $receipt = json_decode(DB::table('system_receipts')->where('booking_id', $b->id)->value('snapshot_json'), true);
        $this->assertEquals(ceil($exact), (float) $receipt['total']);
    }

    public function test_a_counter_hall_booking_ends_on_the_rounded_price(): void
    {
        $id = (int) DB::table('rooms')->where('room_code', 'r1')->value('id');
        $fee = DB::table('event_room_details')->where('room_id', $id)->value('fee_per_day');
        $this->restore[] = fn () => DB::table('event_room_details')->where('room_id', $id)->update(['fee_per_day' => $fee]);
        DB::table('event_room_details')->where('room_id', $id)->update(['fee_per_day' => 1333]);
        $percent = (float) DB::table('venues as v')->join('rooms as r', 'r.venue_id', '=', 'v.id')->where('r.id', $id)->value('v.discount_percent');
        do {
            $day = now()->addDays(random_int(20, 170))->toDateString();
        } while (DB::table('venue_booking_slots')->where('room_id', $id)->where('slot_date', $day)->whereNull('released_at')->exists());

        $b = $this->counter(['type' => 'venue', 'room' => 'r1', 'method' => 'gcash', 'event_name' => 'Rounding', 'date_start' => $day, 'date_end' => $day,
            'attendees' => '10', 'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']])]);

        $exact = 1333 - round(1333 * $percent) / 100;
        $this->assertNotEquals(floor($exact), $exact, 'the fee must give centavos, or this proves nothing');
        $this->assertEquals(ceil($exact), (float) $b->total_amount);
        $this->assertEquals(1333 - ceil($exact), (float) $b->discount_amount);
    }

    /* ---- roles: what changes while a session is open ---- */

    public function test_a_customer_suspended_with_the_page_open_can_no_longer_pay(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->made[] = $id;
        $user = DB::table('users')->where('email', 'customer@gmail.com');
        $this->restore[] = fn () => DB::table('users')->where('email', 'customer@gmail.com')->update(['is_active' => 1]);
        $this->actingAsCustomer()->withSession(['_token' => 'tok']);
        $user->update(['is_active' => 0]);
        $this->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'cash'])->assertStatus(401);
        $this->assertSame('await_gcash', DB::table('bookings')->where('id', $id)->value('payment_status'));
    }

    public function test_staff_suspended_with_the_page_open_can_no_longer_act(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->made[] = $id;
        $this->restore[] = fn () => DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 1]);
        $this->actingAsStaff();
        DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 0]);
        $code = $this->postWithToken(route('admin.booking-action'), ['action' => 'reject', 'booking' => $ref, 'note' => 'Not valid'])->getStatusCode();
        $this->assertContains($code, [401, 403]);
        $this->assertSame('pending', DB::table('bookings')->where('id', $id)->value('reservation_status'));
    }

    public function test_staff_moved_to_another_office_with_the_page_open_can_no_longer_act_on_the_old_one(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->made[] = $id;
        $staff = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        $halls = DB::table('staff_halls')->where('user_id', $staff)->get()->map(fn ($r) => (array) $r)->all();
        $this->restore[] = function () use ($staff, $halls) {
            DB::table('staff_halls')->where('user_id', $staff)->delete();
            DB::table('staff_halls')->insert($halls);
        };
        $this->actingAsStaff();
        DB::table('staff_halls')->where('user_id', $staff)->delete();
        $this->postWithToken(route('admin.booking-action'), ['action' => 'reject', 'booking' => $ref, 'note' => 'Not valid'])->assertStatus(403);
        $this->assertSame('pending', DB::table('bookings')->where('id', $id)->value('reservation_status'));
    }

    /* A block stops new bookings at that office, not the money owed on old ones. */
    public function test_a_blocked_customer_can_still_pay_what_they_owe(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->made[] = $id;
        $venue = (int) DB::table('rooms')->where('room_code', 'r1')->value('venue_id');
        $customer = (int) DB::table('bookings')->where('id', $id)->value('customer_id');
        $block = DB::table('customer_office_blocks')->insertGetId(['customer_id' => $customer, 'venue_id' => $venue, 'reason' => 'Test', 'blocked_by_user_id' => 1]);
        $this->restore[] = fn () => DB::table('customer_office_blocks')->where('id', $block)->delete();
        $this->actingAsCustomer()->withSession(['_token' => 'tok'])
            ->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'cash'])->assertOk();
    }
}
