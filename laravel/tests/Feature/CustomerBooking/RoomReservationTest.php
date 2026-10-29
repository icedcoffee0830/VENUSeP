<?php

namespace Tests\Feature\CustomerBooking;

use Tests\TestCase;

/* customer.room-reservation (was customer/room-reservation.php) — guarded by
   booking.access: a customer, or staff at the counter. */
class RoomReservationTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_customer_login(): void
    {
        $this->get(route('customer.room-reservation', ['room' => 'r1']))->assertRedirect(route('customer.customer-login'));
    }

    public function test_staff_without_counter_mode_are_sent_to_the_customer_login(): void
    {
        $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1']))
            ->assertRedirect(route('customer.customer-login'));
    }

    public function test_a_customer_gets_the_booking_page_with_its_data(): void
    {
        $res = $this->actingAsCustomer()->get(route('customer.room-reservation', ['room' => 'r1']))->assertOk()
            ->assertSee('<div id="app"></div>', false)
            ->assertSee('const DISCOUNT_PERCENT = 20;', false)           /* legacy pricing.php's block */
            ->assertSee('const GC_STORE_KEY', false)                     /* legacy gcash-checker.php's block */
            ->assertSee('const booker0', false)                          /* legacy counter-booker.php's block */
            ->assertSee('const PAY_POLICY = { prepay: false', false)     /* refunds off -> post-pay */
            ->assertSee('const COUNTER = false;', false)
            ->assertSee('const ACCOUNT = {"name":"Brent Cajipoe"', false)
            ->assertSee("fetch(COUNTER ? '" . route('admin.booking-create') . "' : '" . route('customer.booking-submit') . "'", false)
            ->assertSee("fd.append('_token', CSRF);", false)
            ->assertSee('Heritage Function Room')                        /* "More rooms": same venue */
            ->assertSee('class="is-here"', false)                        /* nav marks the room's venue */
            ->assertDontSee('<?php', false);

        /* the room list reaches the script with its amenities (legacy amenities.php globals) */
        preg_match('/const ROOMS = (\[.*?\]);\n/s', $res->getContent(), $m);
        $rooms = json_decode($m[1], true);
        $r1 = collect($rooms)->firstWhere('id', 'r1');
        $this->assertNotEmpty($r1['amenities']);
        $this->assertArrayHasKey('photoUrls', $r1);
        $this->assertNotSame('', $r1['bestFor']);
    }

    public function test_staff_at_the_counter_get_counter_mode(): void
    {
        $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk()
            ->assertSee('const COUNTER = true;', false)
            ->assertSee('const COUNTER_STAFF = ', false)
            ->assertSee(json_encode(route('admin.customer-search')), false)   /* counter-booker.php's search */
            ->assertSee(route('admin.calendar'), false);                 /* footer: staff calendar */
    }
}
