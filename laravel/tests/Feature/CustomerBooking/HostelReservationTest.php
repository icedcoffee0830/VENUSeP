<?php

namespace Tests\Feature\CustomerBooking;

use Tests\TestCase;

/* customer.hostel-reservation (was customer/hostel-reservation.php) — guarded by
   booking.access: a customer, or staff at the counter. */
class HostelReservationTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_customer_login(): void
    {
        $this->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertRedirect(route('customer.customer-login'));
    }

    public function test_staff_without_counter_mode_are_sent_to_the_customer_login(): void
    {
        $this->actingAsStaff()->get(route('customer.hostel-reservation', ['room' => 'h1']))
            ->assertRedirect(route('customer.customer-login'));
    }

    public function test_a_customer_gets_the_booking_page_with_its_data(): void
    {
        $res = $this->actingAsCustomer()->get(route('customer.hostel-reservation', ['room' => 'h1']))->assertOk()
            ->assertSee('<title>VENUSeP | Hostel Reservation</title>', false)
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('const DISCOUNT_PERCENT = 20;', false)
            ->assertSee('const GC_STORE_KEY', false)
            ->assertSee('const booker0', false)
            ->assertSee('const COUNTER = false;', false)
            ->assertSee('const HOSTEL   = ', false)
            ->assertSee('const ACCOUNT = {"name":"Brent Cajipoe"', false)
            ->assertSee("fetch(COUNTER ? '" . route('admin.booking-create') . "' : '" . route('customer.booking-submit') . "'", false)
            ->assertSee(asset('assets/js/confirm-modal.js'), false)
            ->assertSee('Hostel Room 2')                                 /* "More rooms" */
            ->assertSee('class="is-here"', false)
            ->assertDontSee('<?php', false);

        preg_match('/const ROOMS    = (\[.*?\]);\n/s', $res->getContent(), $m);
        $rooms = json_decode($m[1], true);
        $this->assertSame('h1', $rooms[0]['id']);
        $this->assertArrayHasKey('photoUrls', $rooms[0]);
    }

    public function test_staff_at_the_counter_get_counter_mode(): void
    {
        $this->actingAsStaff()->get(route('customer.hostel-reservation', ['room' => 'h1', 'counter' => 1]))->assertOk()
            ->assertSee('const COUNTER = true;', false)
            ->assertSee(json_encode(route('admin.customer-verify')), false)
            ->assertSee(route('admin.calendar'), false);
    }
}
