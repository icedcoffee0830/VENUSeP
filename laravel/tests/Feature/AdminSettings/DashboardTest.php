<?php

namespace Tests\Feature\AdminSettings;

use Tests\TestCase;

/* admin/Admin_Dashboard.php -> route admin.dashboard (admin + staff). */
class DashboardTest extends TestCase
{
    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.dashboard'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_sees_the_dashboard(): void
    {
        $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Admin Dashboard')->assertSee('Pending ID Review')->assertSee("Today's Bookings", false)
            ->assertSee('Bahay Alumni')                                             // a venue row from the DB
            ->assertSee(route('admin.booking-requests', ['tab' => 'confirm']), false)
            ->assertSee(route('customer.venues', ['counter' => 1]), false)
            ->assertSee('const BR = [', false);
    }

    public function test_staff_sees_the_dashboard(): void
    {
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()->assertSee('Admin Dashboard');
    }
}
