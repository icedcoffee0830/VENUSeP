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
            ->assertSee('data-live="br-rows">[{', false)
            ->assertSee(route('admin.booking-request') . '?id=${r.id}', false)
            ->assertSee('class="active"', false);
    }

    public function test_admin_sees_the_queue(): void
    {
        $this->actingAsAdmin()->get(route('admin.booking-requests'))->assertOk()->assertSee('Receipts to confirm');
    }

    /* "Awaiting payment" = payment still awaited, never overdue (its own tab); every row
       carries its booking id so the list can put the newest request first (the flow
       hostel-and-counter checks both in the browser). */
    public function test_the_awaiting_payment_tab_and_newest_first_order(): void
    {
        $res = $this->actingAsAdmin()->get(route('admin.booking-requests'))->assertOk()
            ->assertSee("{ k: 'awaiting', t: 'Awaiting payment' }", false)
            ->assertSee("const AWAITING = ['await_gcash', 'await_cash', 'await_pos'];", false)
            ->assertSee(': b.seq - a.seq);', false);
        $rows = $res->viewData('brRows');
        $this->assertNotEmpty($rows);
        foreach ($rows as $r) {
            $this->assertIsInt($r['seq']);
        }
    }
}
