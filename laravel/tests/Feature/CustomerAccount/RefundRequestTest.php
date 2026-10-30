<?php

namespace Tests\Feature\CustomerAccount;

use Tests\TestCase;

/* customer/refund-request — eligibility re-checked on the server. */
class RefundRequestTest extends TestCase
{
    use BookingRefs;

    public function test_guest_is_sent_to_the_customer_sign_in(): void
    {
        $this->get(route('customer.refund-request', ['booking' => 'VB-2026-036']))->assertRedirect(route('customer.customer-login'));
    }

    public function test_no_booking_selected_and_hostile_shapes_are_refused_politely(): void
    {
        $this->actingAsCustomer('customer@gmail.com')->get(route('customer.refund-request'))
            ->assertOk()->assertSee('Refund not available')
            ->assertSee('No booking was selected. Open a booking from your history and choose &quot;Request Refund&quot;.', false);
        $this->get(route('customer.refund-request') . '?booking[]=x')
            ->assertOk()->assertSee('No booking was selected.');
    }

    public function test_an_unpaid_or_foreign_booking_is_refused(): void
    {
        $this->actingAsCustomer('customer@gmail.com')
            ->get(route('customer.refund-request', ['booking' => $this->ref(10)]))   // rejected, never paid
            ->assertOk()->assertSee('has not been paid, so there is nothing to refund.');
        $this->get(route('customer.refund-request', ['booking' => $this->ref(36)]))   // rafael's booking
            ->assertOk()->assertSee('We could not find booking ' . $this->ref(36) . ' on your account.');
    }

    /* Booking 31 (msantos): staff returned its refund for correction, so its payment
       reads "Refund - action needed" — not refundable, but the customer must reach
       the form to fix and resubmit it ("Fix and resubmit" in Booking History). */
    public function test_a_refund_returned_for_correction_opens_the_form_to_resubmit(): void
    {
        $this->actingAsCustomer('msantos@usep.edu.ph')
            ->get(route('customer.refund-request', ['booking' => $this->ref(31)]))
            ->assertOk()
            ->assertDontSee('Refund not available')
            ->assertSee('id="rr-form"', false)
            ->assertSee('const RR_RESUBMIT = true;', false)
            ->assertSee('"status":"fix"', false);
    }

    public function test_a_refundable_booking_shows_the_form(): void
    {
        $this->makeBooking36Refundable();
        try {
            $this->actingAsCustomer('rafael.lim@gmail.com')
                ->get(route('customer.refund-request', ['booking' => $this->ref(36)]))
                ->assertOk()
                ->assertSee('Your refund request')
                ->assertSee('<option value="schedule_conflict">', false)
                ->assertSee('GCash Payment Receipt')
                ->assertSee('id="rr-gcash"', false)
                ->assertSee('const RR_EXISTING = null;', false)        // the last refund was withdrawn
                ->assertSee('const RR_RESUBMIT = false;', false)
                ->assertSee(json_encode(route('customer.refund-submit')), false)
                ->assertDontSee('Refund not available');
        } finally {
            $this->restoreBooking36();
        }
    }
}
