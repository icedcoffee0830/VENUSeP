<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Post-pay wording (Aron, 2026-10-30): a post-pay booking (refunds off) tells the
   customer "pay by the day after your event". The stored deadline
   (fn_payment_deadline: last day + postpay_grace_days, 3) is unchanged and is
   still what marks the booking Overdue. Booking 20: customer@gmail.com, post-pay
   venue, last day Oct 11 2026, stored deadline Oct 14 2026, overdue. */
class PostPayWordingTest extends TestCase
{
    use BookingRefs;

    public function test_post_pay_asks_for_the_day_after_and_keeps_the_grace_deadline(): void
    {
        legacy('refund-policy');
        $post = payment_policy_for(false, '2026-10-09', '2026-10-11', strtotime('2026-10-13 12:00:00'));
        $this->assertSame('Oct 12, 2026', customer_pay_by_label($post));
        $this->assertSame('Oct 14, 2026', $post['payByLabel']);     // the enforced deadline (3 grace days)
        $this->assertFalse($post['overdue']);                       // past the day after, still inside the grace days

        $pre = payment_policy_for(true, '2026-10-09', '2026-10-11', strtotime('2026-10-01'));
        $this->assertSame($pre['payByLabel'], customer_pay_by_label($pre));
        $hostel = payment_policy_for('pay_to_use', '2026-10-09', '2026-10-11');
        $this->assertSame($hostel['payByLabel'], customer_pay_by_label($hostel));
    }

    public function test_the_stored_deadline_is_still_last_day_plus_grace(): void
    {
        $this->assertSame('2026-10-14 23:59:59', DB::table('bookings')->where('id', 20)->value('current_deadline_at'));
    }

    public function test_pay_page_says_the_day_after_and_no_release_threat_for_post_pay(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.pay', ['booking' => $this->ref(20)]))
            ->assertOk()
            ->assertSeeInOrder(['Was due', 'Oct 12, 2026'])
            ->assertSee('It was due Oct 12, 2026. Please pay it now', false)
            ->assertDontSee('Oct 14, 2026')
            ->assertDontSee('staff may release the slot');
    }

    public function test_booking_history_says_the_day_after(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.booking-history'))
            ->assertOk()
            ->assertSee('was due Oct 12, 2026', false)
            ->assertDontSee('was due Oct 14, 2026', false);
    }

    public function test_awaiting_the_event_the_pay_page_says_pay_by_the_day_after(): void
    {
        DB::table('venue_booking_details')->where('booking_id', 20)->update(['start_date' => '2027-06-14', 'end_date' => '2027-06-15']);
        DB::table('bookings')->where('id', 20)->update(['payment_status' => 'await_event', 'current_deadline_at' => '2027-06-18 23:59:59']);
        try {
            $this->actingAsCustomer('customer@gmail.com')->get(route('customer.pay', ['booking' => $this->ref(20)]))
                ->assertOk()
                ->assertSee('Payment opens the day after your event — pay by Jun 16, 2027.', false)
                ->assertSeeInOrder(['Pay by', 'Jun 16, 2027'])
                ->assertDontSee('Jun 18, 2027');
        } finally {
            DB::table('bookings')->where('id', 20)->update(['payment_status' => 'overdue', 'current_deadline_at' => '2026-10-14 23:59:59']);
            DB::table('venue_booking_details')->where('booking_id', 20)->update(['start_date' => '2026-10-09', 'end_date' => '2026-10-11']);
        }
    }
}
