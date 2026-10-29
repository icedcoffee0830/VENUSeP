<?php

namespace Tests\Feature\CustomerAccount;

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

    public function test_a_customer_with_no_bookings_gets_the_empty_table(): void
    {
        $this->actingAsCustomer('nikkaarena@gmail.com')->get(route('customer.booking-history'))
            ->assertOk()->assertSee('No booking history found.')
            ->assertDontSee('class="booking-id"', false);
    }
}
