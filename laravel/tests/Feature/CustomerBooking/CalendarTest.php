<?php

namespace Tests\Feature\CustomerBooking;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer.calendar (was customer/calendar.php) — customers only, read-only. */
class CalendarTest extends TestCase
{
    public function test_a_guest_is_sent_to_the_customer_login(): void
    {
        $this->get(route('customer.calendar'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_staff_are_sent_to_the_customer_login(): void
    {
        $this->actingAsStaff()->get(route('customer.calendar'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_a_customer_sees_their_bookings_on_the_calendar(): void
    {
        $res = $this->actingAsCustomer()->get(route('customer.calendar'))->assertOk()
            ->assertSee('<title>VENUSeP | My Calendar</title>', false)
            ->assertSee('class="calendar-page"', false)
            ->assertSee('My Calendar')->assertSee('Your venue bookings at a glance')
            ->assertSee('fullcalendar@6.1.20', false)
            ->assertSee('Brent Cajipoe');                                      /* header chip */

        preg_match('/events: (\[.*?\]),\n/s', $res->getContent(), $m);
        $events = json_decode($m[1], true);
        $this->assertIsArray($events);
        $mine = DB::table('bookings')->where('customer_id', 1)->count();
        $this->assertGreaterThan(0, $mine);
        $this->assertNotEmpty($events);
    }

    /* It opened on September 2026 for everyone (a mockup leftover), so a
       customer's upcoming bookings were a month or more away from view. */
    public function test_the_calendar_opens_on_the_current_month(): void
    {
        $this->actingAsCustomer()->get(route('customer.calendar'))->assertOk()
            ->assertDontSee("initialDate: '", false);
    }
}
