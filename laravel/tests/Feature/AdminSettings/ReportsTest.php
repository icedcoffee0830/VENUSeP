<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
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

    /* Venue names are typed by admins; the details table builds its rows with
       innerHTML, so a name must arrive as data and be written as text. */
    public function test_a_venue_name_is_written_into_the_table_as_text(): void
    {
        DB::beginTransaction();
        try {
            DB::table("venues")->insert(["venue_code" => "ADMINA-XSS", "name" => "Hall <img src=x onerror=alert(1)> & Co",
                "venue_type" => "event", "description" => "test", "is_active" => 1]);
            $html = $this->actingAsAdmin()->get(route("admin.reports"))->assertOk()->getContent();
        } finally {
            DB::rollBack();
        }
        $this->assertStringContainsString("Hall \u003Cimg", $html);                     // the venue is in the data...
        $this->assertStringNotContainsString("<img src=x", $html);                       // @json keeps it inert in the script
        $this->assertStringContainsString("<td><strong>\${escHtml(item.venue)}</strong></td>", $html);
        $this->assertStringNotContainsString("<td><strong>\${item.venue}</strong></td>", $html);
    }

    public function test_staff_sees_the_reports(): void
    {
        $this->actingAsStaff()->get(route('admin.reports'))->assertOk()->assertSee('Quarterly Reports');
    }
}
