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

    /* One venue booking on room $code, held from $from (Y-m-d). Callers roll back. */
    private function addBooking(string $code, string $from, string $res, string $pay, float $total = 1000, float $discount = 0): int
    {
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => DB::table('customers')->value('id'),
            'room_id' => DB::table('rooms')->where('room_code', $code)->value('id'), 'booking_type' => 'venue',
            'reservation_status' => $res, 'payment_status' => $pay, 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => $total, 'discount_percent' => $discount,
            'discount_amount' => 0, 'total_amount' => $total, 'refunds_allowed' => 1, 'submitted_at' => now()->subDays(5),
        ]);
        DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Reports test',
            'start_date' => $from, 'end_date' => $from, 'attendee_count' => 10]);
        return $id;
    }

    private function dashboardRevenue(): int
    {
        preg_match('/Revenue<\/h3>\s*<div class="number">₱([\d,]+)</u',
            $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(), $m);
        return (int) str_replace(',', '', $m[1]);
    }

    /* DB-DECISIONS #16: money stays collected until its refund payout is COMPLETE.
       A refund requested, in progress or denied still counts; `refunded` does not.
       The dashboard tile reads the same figure. */
    public function test_revenue_counts_paid_bookings_until_the_refund_is_paid_out(): void
    {
        legacy('bookings');
        DB::beginTransaction();
        try {
            $quarter = fn () => (float) report_quarters(1)[0]['revenue'];
            $before = $quarter();
            $tile = $this->dashboardRevenue();
            $this->assertSame((int) round($before), $tile, 'dashboard and report agree before');

            $today = now()->toDateString();
            foreach (['confirmed', 'paid_cash', 'refund_requested', 'refund_correction', 'refund_await_or',
                      'refund_processing', 'refund_denied'] as $pay) {
                $this->addBooking('r1', $today, 'approved', $pay);
            }
            $this->addBooking('r1', $today, 'cancelled', 'refunded');        // the money went back
            $this->addBooking('r1', $today, 'approved', 'await_gcash');      // never paid

            $this->assertSame($before + 7000, $quarter());
            $this->assertSame((int) round($before) + 7000, $this->dashboardRevenue(), 'dashboard follows the report');
        } finally {
            DB::rollBack();
        }
    }

    /* Events Held counts what the venue hosted: approved or completed. Fell-through
       (cancelled / rejected / released) and still-pending bookings are not events. */
    public function test_events_held_leaves_out_bookings_that_fell_through_or_are_pending(): void
    {
        legacy('bookings');
        $venue = DB::table('rooms as r')->join('venues as v', 'v.id', '=', 'r.venue_id')->where('r.room_code', 'r1')->value('v.name');
        $row = function () use ($venue) {
            $q = now()->quarter;
            foreach (report_by_venue((int) now()->year, $q) as $v) {
                if ($v['venue'] === $venue) {
                    return $v;
                }
            }
            $this->fail('no report row for ' . $venue);
        };
        DB::beginTransaction();
        try {
            $before = $row();
            $today = now()->toDateString();
            foreach (['approved' => 'confirmed', 'completed' => 'paid_cash', 'pending' => 'locked',
                      'cancelled' => 'locked', 'rejected' => 'locked', 'released' => 'expired'] as $res => $pay) {
                $this->addBooking('r1', $today, $res, $pay);
            }
            $after = $row();
            $this->assertSame((int) $before['events'] + 2, (int) $after['events'], 'Events Held');
            $this->assertSame((int) $before['cancelled'] + 3, (int) $after['cancelled'], 'Cancelled');
        } finally {
            DB::rollBack();
        }
    }

    public function test_staff_sees_the_reports(): void
    {
        $this->actingAsStaff()->get(route('admin.reports'))->assertOk()->assertSee('Quarterly Reports');
    }
}
