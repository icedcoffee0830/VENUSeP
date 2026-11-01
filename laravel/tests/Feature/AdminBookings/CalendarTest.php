<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* admin/calendar — every booking on FullCalendar. */
class CalendarTest extends TestCase
{
    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.calendar'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.calendar'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_see_the_calendar_with_events(): void
    {
        $this->actingAsStaff()->get(route('admin.calendar'))->assertOk()
            ->assertSee('<title>VENUSeP | Calendar</title>', false)
            ->assertSee('class="calendar-page"', false)
            ->assertSee('events: [{', false)
            ->assertSee('fullcalendar@6.1.20', false);
    }

    public function test_admin_sees_the_calendar(): void
    {
        $this->actingAsAdmin()->get(route('admin.calendar'))->assertOk();
    }
}
