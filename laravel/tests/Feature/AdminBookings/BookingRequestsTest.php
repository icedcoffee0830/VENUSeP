<?php

namespace Tests\Feature\AdminBookings;

use Tests\TestCase;

/* admin/booking-requests — the staff queue. */
class BookingRequestsTest extends TestCase
{
    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.booking-requests'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.booking-requests'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_sees_the_queue_with_real_rows(): void
    {
        $this->actingAsStaff()->get(route('admin.booking-requests'))->assertOk()
            ->assertSee('Booking Requests')
            ->assertSee('const BR = [{', false)
            ->assertSee(route('admin.booking-request') . '?id=${r.id}', false)
            ->assertSee('class="active"', false);
    }

    public function test_admin_sees_the_queue(): void
    {
        $this->actingAsAdmin()->get(route('admin.booking-requests'))->assertOk()->assertSee('Receipts to confirm');
    }
}
