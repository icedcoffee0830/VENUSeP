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

    /* The four-quarter window ends at today's quarter whatever the day of the month:
       "-9 months" from March 31 overflowed to July 1 and skipped Q2. */
    public function test_the_quarter_window_ends_at_the_current_quarter_on_a_31st(): void
    {
        legacy('bookings');
        $labels = fn (string $day) => array_column(report_quarters(4, strtotime($day)), 'label');
        $this->assertSame(['Q2 2025', 'Q3 2025', 'Q4 2025', 'Q1 2026'], $labels('2026-03-31'));
        $this->assertSame(['Q3 2025', 'Q4 2025', 'Q1 2026', 'Q2 2026'], $labels('2026-05-31'));
        $this->assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'], $labels('2026-12-31'));
        $this->assertSame(['Q4 2025', 'Q1 2026', 'Q2 2026', 'Q3 2026'], $labels('2026-08-31'));
        $this->assertSame(['Q1 2026'], array_column(report_quarters(1, strtotime('2026-03-31')), 'label'));   // the dashboard's one quarter
    }

    /* The quarter totals count every booking, so a venue made inactive keeps its row
       (marked) while it has bookings in the period: the per-venue rows add up to the
       totals. A quiet inactive venue still stays out. */
    public function test_an_inactive_venue_with_bookings_keeps_its_row_so_the_venues_add_up(): void
    {
        legacy('bookings');
        DB::beginTransaction();
        try {
            $mk = function (string $code) {
                $v = DB::table('venues')->insertGetId(['venue_code' => $code, 'name' => 'Old Hall ' . $code,
                    'venue_type' => 'event', 'description' => 'test', 'is_active' => 1]);
                DB::table('rooms')->insert(['venue_id' => $v, 'room_code' => $code, 'name' => 'Old room', 'room_type' => 'event']);
                return $v;
            };
            $busy = $mk('ADMINA-OLD1');
            $quiet = $mk('ADMINA-OLD2');
            $this->addBooking('ADMINA-OLD1', now()->toDateString(), 'approved', 'confirmed', 4321);
            DB::table('venues')->whereIn('id', [$busy, $quiet])->update(['is_active' => 0]);

            $rows = report_by_venue((int) now()->year, now()->quarter);
            $names = array_column($rows, 'venue');
            $this->assertContains('Old Hall ADMINA-OLD1 (inactive)', $names);
            $this->assertNotContains('Old Hall ADMINA-OLD2 (inactive)', $names);
            $this->assertNotContains('Old Hall ADMINA-OLD2', $names);
            $this->assertEqualsWithDelta((float) report_quarters(1)[0]['revenue'],
                array_sum(array_map('floatval', array_column($rows, 'revenue'))), 0.001, 'venue revenues add up to the quarter');
            $this->assertSame((int) DB::table('venues')->where('is_active', 1)->count() + 1, count($rows));
        } finally {
            DB::rollBack();
        }
    }

    public function test_staff_sees_the_reports(): void
    {
        $this->actingAsStaff()->get(route('admin.reports'))->assertOk()->assertSee('Quarterly Reports');
    }
}
