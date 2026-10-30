<?php

namespace Tests\Feature\CustomerBooking;

use Tests\TestCase;

/* customer.venues — the PUBLIC landing page (was customer/venusep_venue_booking.php). */
class VenueBookingTest extends TestCase
{
    public function test_a_guest_sees_the_landing_page_with_full_prices_and_the_nudge(): void
    {
        $this->get(route('customer.venues'))->assertOk()
            ->assertSee('Reserve a campus venue in')
            ->assertSee('Alumni Grand Ballroom')                       /* an active venue room */
            ->assertDontSee('Alumni Boardroom')                        /* an inactive one */
            ->assertSee('Hostel Room 1')
            ->assertSee('USeP student, faculty or staff?')             /* the nudge, not the USeP rate */
            ->assertSee(route('customer.room-reservation') . '?room=r1', false)
            ->assertSee(route('customer.hostel-reservation') . '?room=h1', false)
            ->assertSee(route('customer.customer-login'), false)       /* the nav: Log in */
            ->assertSee('All bookings are non-refundable.')            /* refunds off in the seed */
            ->assertSee('20% off for USeP students', false)
            ->assertDontSee('&counter=1', false);
    }

    public function test_a_usep_account_sees_the_usep_rate(): void
    {
        $this->actingAsCustomer('jmdelacruz@usep.edu.ph')->get(route('customer.venues'))->assertOk()
            ->assertSee('You are signed in with a USeP account')
            ->assertSee('· 20% off</span>', false)                      /* usep_price_html() — the rate reaches its global */
            ->assertSee(route('customer.booking-history'), false);
    }

    public function test_a_gmail_customer_sees_full_prices(): void
    {
        $this->actingAsCustomer()->get(route('customer.venues'))->assertOk()
            ->assertSee('USeP student, faculty or staff?')
            ->assertDontSee('You are signed in with a USeP account');
    }

    public function test_staff_at_the_counter_keep_counter_mode_on_every_link(): void
    {
        $this->actingAsStaff()->get(route('customer.venues') . '?counter=1')->assertOk()   // Bahay Alumni staff
            ->assertSee(route('customer.room-reservation') . '?room=r1&amp;counter=1', false)
            ->assertDontSee(route('customer.hostel-reservation') . '?room=h1&amp;counter=1', false)   // own venue only
            ->assertSee(route('admin.calendar'), false)
            ->assertDontSee(route('customer.transaction-history'), false)
            ->assertSee('Leave counter mode');
    }

    public function test_hostel_staff_at_the_counter_get_only_hostel_rooms(): void
    {
        $this->actingAsStaff('jakemerson@gmail.com')->get(route('customer.venues') . '?counter=1')->assertOk()
            ->assertSee(route('customer.hostel-reservation') . '?room=h1&amp;counter=1', false)
            ->assertDontSee(route('customer.room-reservation') . '?room=r1&amp;counter=1', false);
    }
}
