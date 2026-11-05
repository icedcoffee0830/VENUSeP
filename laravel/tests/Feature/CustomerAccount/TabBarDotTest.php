<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* The red dot on the phone's Bookings tab: the bell's reply says needsYou when one of the
   customer's notified bookings is still waiting for them, read or not, and stops once it is
   done. The viewer has no bookings of their own, so each test sees only its own row. */
class TabBarDotTest extends TestCase
{
    use SeedsBookings;

    private const VIEWER = 'nikkaarena@gmail.com';

    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $id) {
            DB::delete('DELETE FROM notifications WHERE booking_id = ?', [$id]);
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
        parent::tearDown();
    }

    /* A booking in this state, with a bell row for the viewer. */
    private function notified(string $res, string $pay, ?string $readAt = null): int
    {
        [$id] = $this->seedVenueBooking('r1', $res, $pay);
        $this->made[] = $id;
        $user = (int) DB::table('users')->where('email', self::VIEWER)->value('id');
        DB::table('notifications')->insert(['user_id' => $user, 'kind' => 'approved', 'booking_id' => $id, 'read_at' => $readAt]);
        return $id;
    }

    private function needsYou(): bool
    {
        return $this->actingAsCustomer(self::VIEWER)->getJson(route('root.notifications'))->assertOk()->json('needsYou');
    }

    public function test_no_dot_with_nothing_waiting(): void
    {
        $this->assertFalse($this->needsYou());
    }

    public function test_a_payment_due_shows_the_dot(): void
    {
        foreach (['await_gcash', 'await_cash', 'await_pos', 'overdue'] as $pay) {
            $id = $this->notified('approved', $pay);
            $this->assertTrue($this->needsYou(), $pay);
            DB::delete('DELETE FROM notifications WHERE booking_id = ?', [$id]);
        }
    }

    public function test_a_read_notification_keeps_the_dot_until_it_is_paid(): void
    {
        $id = $this->notified('approved', 'await_gcash', now()->toDateTimeString());
        $this->assertTrue($this->needsYou());

        DB::update("UPDATE bookings SET payment_status = 'confirmed' WHERE id = ?", [$id]);
        $this->assertFalse($this->needsYou());
    }

    public function test_a_refund_returned_for_correction_shows_the_dot(): void
    {
        $this->notified('approved', 'refund_correction');
        $this->assertTrue($this->needsYou());
    }

    public function test_a_rejected_request_shows_the_dot_while_a_new_id_can_be_sent(): void
    {
        $id = $this->notified('rejected', 'locked');
        $this->assertTrue($this->needsYou());

        DB::update('UPDATE venue_booking_slots SET slot_date = CURDATE() WHERE booking_id = ?', [$id]);
        DB::update('UPDATE venue_booking_details SET start_date = CURDATE(), end_date = CURDATE() WHERE booking_id = ?', [$id]);
        $this->assertFalse($this->needsYou());
    }

    public function test_paid_waiting_on_staff_and_closed_bookings_show_no_dot(): void
    {
        foreach ([['approved', 'confirmed'], ['approved', 'under_review'], ['approved', 'await_event'], ['pending', 'locked'],
                  ['cancelled', 'await_gcash'], ['released', 'overdue']] as [$res, $pay]) {
            $this->notified($res, $pay);
            $this->assertFalse($this->needsYou(), "$res / $pay");
        }
    }

    public function test_a_booking_with_no_bell_row_for_this_customer_shows_no_dot(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->made[] = $id;
        $this->assertFalse($this->needsYou());
    }
}
