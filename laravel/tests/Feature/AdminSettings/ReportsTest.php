<?php

namespace Tests\Feature\AdminSettings;

use Tests\TestCase;

/* admin/Quarterly_Reports.php -> route admin.reports (admin + staff). */
class ReportsTest extends TestCase
{
    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->get(route('admin.reports'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_customer_is_sent_to_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.reports'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_sees_the_reports(): void
    {
        $q = 'Q' . (int) ceil((int) date('n') / 3) . ' ' . date('Y');
        $this->actingAsAdmin()->get(route('admin.reports'))->assertOk()
            ->assertSee('Quarterly Reports')->assertSee('Venue Report Details')
            ->assertSee('<option value="' . $q . '" selected>', false)       // newest quarter first, selected
            ->assertSee(asset('assets/img/reports/print-header.png'), false)
            ->assertSee('const venueByQuarter = [', false)
            ->assertSee('const currentQuarter = "' . $q . '"', false);
    }

    public function test_staff_sees_the_reports(): void
    {
        $this->actingAsStaff()->get(route('admin.reports'))->assertOk()->assertSee('Quarterly Reports');
    }
}
