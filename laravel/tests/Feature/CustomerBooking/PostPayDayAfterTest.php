<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\CustomerAccount\BookingRefs;
use Tests\TestCase;

/* Post-pay wording everywhere else (Aron, 2026-10-30): the customer is told
   "pay by the day after your event"; the booking turns Overdue only after
   postpay_grace_days (3), and only staff see that grace-end date. Booking 20:
   customer@gmail.com, post-pay, last day Oct 11 2026, stored deadline Oct 14. */
class PostPayDayAfterTest extends TestCase
{
    use BookingRefs, SeedsBookings;

    public function test_the_booking_page_rule_labels_the_day_after(): void
    {
        legacy('refund-policy');
        $js = payment_policy_js();
        $this->assertStringContainsString("label:fmt(over), overdueAfter:fmt(payBy)", $js);
        $this->assertStringNotContainsString('label:fmt(payBy)', $js);
    }

    public function test_room_page_no_longer_says_within_n_days(): void
    {
        $this->actingAsCustomer('aronerfe0830@gmail.com')->get(route('customer.room-reservation', ['room' => 'r1']))
            ->assertOk()
            ->assertDontSee('days after your last day', false)
            ->assertDontSee('days of your last day', false)
            ->assertDontSee("is due within <strong>'+PAY_POLICY.graceDays", false);
    }

    public function test_landing_page_says_the_day_after(): void
    {
        $this->get(route('customer.venues'))->assertOk()
            ->assertSee('pay by GCash or over the counter by the day after it.', false);
    }

    public function test_details_modal_customer_gets_the_day_after_staff_also_the_overdue_date(): void
    {
        $ref = $this->ref(20);
        $this->actingAsCustomer('customer@gmail.com')->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJsonPath('details.money.payBy', 'Oct 12, 2026')
            ->assertJsonPath('details.money.overdueAfter', '');
        $this->actingAsAdmin()->get(route('root.booking-details', ['booking' => $ref]))
            ->assertOk()->assertJsonPath('details.money.payBy', 'Oct 12, 2026')
            ->assertJsonPath('details.money.overdueAfter', 'Oct 14, 2026');
    }

    public function test_an_extended_deadline_is_what_the_customer_is_told(): void
    {
        DB::table('bookings')->where('id', 20)->update(['current_deadline_at' => '2026-11-20 23:59:59']);
        try {
            $this->actingAsCustomer('customer@gmail.com')->get(route('root.booking-details', ['booking' => $this->ref(20)]))
                ->assertOk()->assertJsonPath('details.money.payBy', 'Nov 20, 2026');
        } finally {
            DB::table('bookings')->where('id', 20)->update(['current_deadline_at' => '2026-10-14 23:59:59']);
        }
    }

    public function test_counter_reply_and_walk_in_email_say_the_day_after_plus_overdue_after(): void
    {
        legacy('refund-policy');
        $this->assertFalse(refunds_enabled(), 'the test DB is post-pay (refunds off)');
        do {
            $day = now()->addDays(random_int(400, 4000))->toDateString();
        } while (DB::table('venue_booking_slots as s')->join('rooms as r', 'r.id', '=', 's.room_id')
            ->where('r.room_code', 'r1')->where('s.slot_date', $day)->whereNull('s.released_at')->exists());
        $res = $this->actingAsStaff()->postWithToken(route('admin.booking-create'), [
            'type' => 'venue', 'room' => 'r1', 'method' => 'cash', 'id_checked' => '1', 'usep_id' => '0',
            'agree_no_refund' => '1', 'booker_mode' => 'walkin',
            'walkin_name' => 'Postpay Walk-in', 'walkin_phone' => '09171234567', 'walkin_email' => 'postpay@example.test',
            'event_name' => 'Post-pay wording', 'date_start' => $day, 'date_end' => $day, 'attendees' => '40',
            'times' => json_encode([$day => ['start' => '08:00', 'end' => '12:00']]),
        ]);
        $askBy = date('M j, Y', strtotime($day . ' +1 day'));
        $graceEnd = date('M j, Y', strtotime($day . ' +' . postpay_grace_days() . ' days'));
        $res->assertOk()->assertJson(['ok' => true, 'payBy' => $askBy, 'overdueAfter' => $graceEnd]);

        $id = $res->json('bookingId');
        $mail = DB::table('email_outbox')->where('booking_id', $id)->first();
        try {
            $this->assertStringContainsString('pay by ' . $askBy, $mail->body_text);
            $this->assertStringNotContainsString($graceEnd, $mail->body_text);
        } finally {
            @unlink(getenv('VENUSEP_DOC_ROOT') . '/mail-log/' . $mail->id . '.eml');
        }
    }
}
