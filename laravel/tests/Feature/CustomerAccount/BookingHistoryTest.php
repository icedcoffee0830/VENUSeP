<?php

namespace Tests\Feature\CustomerAccount;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/booking-history — the session customer's own bookings only. */
class BookingHistoryTest extends TestCase
{
    use BookingRefs;

    public function test_guest_is_sent_to_the_customer_sign_in(): void
    {
        $this->get(route('customer.booking-history'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_staff_is_not_a_customer(): void
    {
        $this->actingAsStaff()->get(route('customer.booking-history'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_customer_sees_only_their_own_bookings(): void
    {
        $mine = $this->ref($this->firstBookingOf(1));
        $theirs = $this->ref($this->firstBookingOf(2));

        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.booking-history'))
            ->assertOk()
            ->assertSee('Previous Bookings')
            ->assertSee('data-booking-id="' . $mine . '"', false)
            ->assertDontSee('data-booking-id="' . $theirs . '"', false)
            ->assertSee(json_encode(route('customer.refund-submit')), false)
            ->assertSee('openBookingDetails', false)
            ->assertHeader('Pragma', 'no-cache');
    }

    /* The row's refund action is a placeholder the page script swaps for "Withdraw
       request" / "Fix and resubmit". A booking with a refund on it is no longer
       "refundable" (its payment reads Refund requested / action needed), so the
       placeholder must be there for those rows too, or neither button can appear. */
    public function test_a_booking_with_a_refund_returned_for_correction_keeps_its_refund_action(): void
    {
        $ref = $this->ref(31);   // msantos: returned_for_correction
        $html = $this->actingAsCustomer('msantos@usep.edu.ph')->get(route('customer.booking-history'))
            ->assertOk()->getContent();

        $row = substr($html, strpos($html, 'data-booking-id="' . $ref . '"'));
        $row = substr($row, 0, strpos($row, '</tr>'));
        $this->assertStringContainsString('booking-action-refund', $row);
        $this->assertStringNotContainsString("rec.status === 'approved'", $html);   // the server refuses to withdraw an approved refund
    }

    public function test_an_open_refund_request_keeps_its_refund_action(): void
    {
        $this->makeBooking36Refundable();
        DB::table('refunds')->insert([
            'booking_id' => 36, 'refund_status' => 'requested', 'reason_category' => 'other',
            'reason' => 'test', 'amount_requested' => 100,
        ]);
        DB::table('bookings')->where('id', 36)->update(['payment_status' => 'refund_requested']);
        try {
            $ref = $this->ref(36);
            $html = $this->actingAsCustomer('rafael.lim@gmail.com')->get(route('customer.booking-history'))
                ->assertOk()->getContent();
            $row = substr($html, strpos($html, 'data-booking-id="' . $ref . '"'));
            $row = substr($row, 0, strpos($row, '</tr>'));
            $this->assertStringContainsString('booking-action-refund', $row);
        } finally {
            $this->restoreBooking36();
        }
    }

    public function test_a_customer_with_no_bookings_gets_the_empty_table(): void
    {
        $this->actingAsCustomer('nikkaarena@gmail.com')->get(route('customer.booking-history'))
            ->assertOk()->assertSee('No booking history found.')
            ->assertDontSee('class="booking-id"', false);
    }
}
