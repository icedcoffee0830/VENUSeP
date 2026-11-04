<?php

namespace Tests\Feature\AdminSettings;

use App\Queries\ReportQueries;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* admin/Quarterly_Reports.php -> route admin.reports (admin + staff), with the office
   choice of DB-DECISIONS 28. Offices: 1 Bahay Alumni, 2 USeP Venues, 3 USeP Hostel. */
class ReportsTest extends TestCase
{
    use MakesAccounts;

    private const BAHAY = 1, VENUES = 2, HOSTEL = 3;

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
            ->assertSee('Quarterly Reports')->assertSee('Halls')->assertSee('Hostel Rooms')
            ->assertSee('<option value="' . $q . '" selected>', false)       // newest quarter first, selected
            ->assertSee(asset('assets/img/reports/print-header.png'), false)
            ->assertSee('const venueByQuarter = [', false)
            ->assertSee('const currentQuarter = "' . $q . '"', false);
    }

    /* Hall and office names are typed by admins; the tables build their rows with
       innerHTML, so a name must arrive as data and be written as text. */
    public function test_a_hall_name_is_written_into_the_table_as_text(): void
    {
        DB::beginTransaction();
        try {
            $v = DB::table('venues')->insertGetId(['venue_code' => 'ADMINA-XSS', 'name' => 'Hall <img src=x onerror=alert(1)> & Co',
                'venue_type' => 'event', 'description' => 'test', 'is_active' => 1]);
            DB::table('rooms')->insert(['venue_id' => $v, 'room_code' => 'ADMINA-XSS', 'name' => 'Room <img src=x onerror=alert(2)>', 'room_type' => 'event']);
            $html = $this->actingAsAdmin()->get(route('admin.reports', ['office' => 'all']))->assertOk()->getContent();
        } finally {
            DB::rollBack();
        }
        $this->assertStringContainsString('Room \u003Cimg', $html);                     // the hall is in the data...
        $this->assertStringContainsString('Hall \u003Cimg', $html);                     // ...and its office
        $this->assertStringNotContainsString("<img src=x", $html);                       // @json and {{ }} keep both inert
        $this->assertStringContainsString("<td><strong>\${escHtml(item.name)}</strong></td>", $html);
        $this->assertStringContainsString("<td>\${escHtml(item.office)}</td>", $html);
    }

    /* One booking on room $code, held from $from (Y-m-d): a venue booking with one
       08:00-12:00 day, or a hostel stay of $nights nights for $guests guests. Callers roll back. */
    private function addBooking(string $code, string $from, string $res, string $pay, float $total = 1000, float $discount = 0,
                                ?int $customer = null, int $nights = 2, int $guests = 2): int
    {
        $room = DB::table('rooms')->where('room_code', $code)->first();
        $hostel = $room->room_type === 'hostel';
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => $customer ?? DB::table('customers')->value('id'),
            'room_id' => $room->id, 'booking_type' => $hostel ? 'hostel' : 'venue',
            'reservation_status' => $res, 'payment_status' => $pay, 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => $total, 'discount_percent' => $discount,
            'discount_amount' => 0, 'total_amount' => $total, 'refunds_allowed' => 1, 'submitted_at' => now()->subDays(5),
        ]);
        if ($hostel) {
            DB::table('hostel_booking_details')->insert(['booking_id' => $id, 'check_in_date' => $from,
                'check_out_date' => date('Y-m-d', strtotime("$from +$nights days"))]);
            for ($g = 0; $g < $guests; $g++) {
                DB::table('hostel_occupants')->insert(['booking_id' => $id, 'full_name' => "Guest $g", 'gender' => 'other', 'is_primary_guest' => $g === 0]);
            }
        } else {
            DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Reports test',
                'start_date' => $from, 'end_date' => $from, 'attendee_count' => 10]);
            DB::table('venue_booking_slots')->insert(['booking_id' => $id, 'room_id' => $room->id, 'slot_date' => $from,
                'start_time' => '08:00:00', 'end_time' => '12:00:00', 'released_at' => null]);
        }
        return $id;
    }

    private function allOffices(): array
    {
        return DB::table('venues')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function dashboardRevenue(): int
    {
        preg_match('/Revenue<\/h3>\s*<div class="number">₱([\d,]+)</u',
            $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent(), $m);
        return (int) str_replace(',', '', $m[1]);
    }

    /* DB-DECISIONS #16: money stays collected until its refund payout is COMPLETE.
       A refund requested, in progress or denied still counts; `refunded` does not, and
       shows as refunded instead. The dashboard tile reads the same figure. */
    public function test_revenue_counts_paid_bookings_until_the_refund_is_paid_out(): void
    {
        DB::beginTransaction();
        try {
            $quarter = fn () => ReportQueries::quarters($this->allOffices(), 1)[0];
            $before = $quarter();
            $tile = $this->dashboardRevenue();
            $this->assertSame((int) round($before['revenue']), $tile, 'dashboard and report agree before');

            $today = now()->toDateString();
            foreach (['confirmed', 'paid_cash', 'refund_requested', 'refund_correction', 'refund_await_or',
                      'refund_processing', 'refund_denied'] as $pay) {
                $this->addBooking('r1', $today, 'approved', $pay);
            }
            $this->addBooking('r1', $today, 'cancelled', 'refunded', 600);   // the money went back
            $this->addBooking('r1', $today, 'approved', 'await_gcash');      // never paid
            $this->addBooking('r1', $today, 'approved', 'written_off', 250); // never will be

            $after = $quarter();
            $this->assertSame($before['revenue'] + 7000, $after['revenue']);
            $this->assertSame($before['refunds'] + 1, $after['refunds']);
            $this->assertSame($before['refunded'] + 600, $after['refunded']);
            $this->assertSame($before['write_offs'] + 1, $after['write_offs']);
            $this->assertSame($before['written_off'] + 250, $after['written_off']);
            $this->assertSame((int) round($before['revenue']) + 7000, $this->dashboardRevenue(), 'dashboard follows the report');
        } finally {
            DB::rollBack();
        }
    }

    /* Events Held counts what the hall hosted: approved or completed. Fell-through
       (cancelled / rejected / released) and still-pending bookings are not events, and
       only held events add days and hours. */
    public function test_events_held_leaves_out_bookings_that_fell_through_or_are_pending(): void
    {
        $name = DB::table('rooms')->where('room_code', 'r1')->value('name');
        $row = function () use ($name) {
            foreach (ReportQueries::rooms($this->allOffices(), (int) now()->year, now()->quarter) as $r) {
                if ($r['name'] === $name) {
                    return $r;
                }
            }
            $this->fail('no report row for ' . $name);
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
            $this->assertSame((int) $before['days'] + 2, (int) $after['days'], 'Days');
            $this->assertEqualsWithDelta((float) $before['hours'] + 8, (float) $after['hours'], 0.001, 'Hours');
        } finally {
            DB::rollBack();
        }
    }

    /* A hostel stay: nights x guests bed-nights, for held stays only. */
    public function test_a_hostel_room_counts_bed_nights_of_held_stays(): void
    {
        $name = DB::table('rooms')->where('room_code', 'h1')->value('name');
        $row = fn () => collect(ReportQueries::rooms([self::HOSTEL], (int) now()->year, now()->quarter))->firstWhere('name', $name);
        DB::beginTransaction();
        try {
            $before = $row();
            $this->assertSame('communal', $before['bathroom']);
            $this->assertGreaterThan(0, (int) $before['beds']);
            $today = now()->toDateString();
            $this->addBooking('h1', $today, 'approved', 'confirmed', 900, 0, null, 3, 2);   // 6 bed-nights
            $this->addBooking('h1', $today, 'cancelled', 'locked', 900, 0, null, 3, 2);     // fell through: none
            $after = $row();
            $this->assertSame((int) $before['bed_nights'] + 6, (int) $after['bed_nights']);
            $this->assertSame((int) $before['events'] + 1, (int) $after['events']);
        } finally {
            DB::rollBack();
        }
    }

    /* The four-quarter window ends at today's quarter whatever the day of the month:
       "-9 months" from March 31 overflowed to July 1 and skipped Q2. */
    public function test_the_quarter_window_ends_at_the_current_quarter_on_a_31st(): void
    {
        $labels = fn (string $day) => array_column(ReportQueries::quarters([], 4, strtotime($day)), 'label');
        $this->assertSame(['Q2 2025', 'Q3 2025', 'Q4 2025', 'Q1 2026'], $labels('2026-03-31'));
        $this->assertSame(['Q3 2025', 'Q4 2025', 'Q1 2026', 'Q2 2026'], $labels('2026-05-31'));
        $this->assertSame(['Q1 2026', 'Q2 2026', 'Q3 2026', 'Q4 2026'], $labels('2026-12-31'));
        $this->assertSame(['Q4 2025', 'Q1 2026', 'Q2 2026', 'Q3 2026'], $labels('2026-08-31'));
        $this->assertSame(['Q1 2026'], array_column(ReportQueries::quarters([], 1, strtotime('2026-03-31')), 'label'));   // the dashboard's one quarter
        $this->assertSame([90, 91, 92, 92], array_column(ReportQueries::quarters([], 4, strtotime('2026-12-31')), 'days'));
    }

    /* The quarter totals count every booking, so a room made inactive keeps its row
       (marked) while it has bookings in the period: the rows add up to the totals.
       A quiet inactive room still stays out. */
    public function test_an_inactive_room_with_bookings_keeps_its_row_so_the_rooms_add_up(): void
    {
        DB::beginTransaction();
        try {
            $v = DB::table('venues')->insertGetId(['venue_code' => 'ADMINA-OLD', 'name' => 'Old Hall',
                'venue_type' => 'event', 'description' => 'test', 'is_active' => 1]);
            foreach (['ADMINA-OLD1', 'ADMINA-OLD2'] as $code) {
                DB::table('rooms')->insert(['venue_id' => $v, 'room_code' => $code, 'name' => 'Old room ' . $code, 'room_type' => 'event']);
            }
            $this->addBooking('ADMINA-OLD1', now()->toDateString(), 'approved', 'confirmed', 4321);
            DB::table('rooms')->whereIn('room_code', ['ADMINA-OLD1', 'ADMINA-OLD2'])->update(['is_active' => 0]);

            $ids = $this->allOffices();
            $rows = ReportQueries::rooms($ids, (int) now()->year, now()->quarter);
            $names = array_column($rows, 'name');
            $this->assertContains('Old room ADMINA-OLD1 (inactive)', $names);
            $this->assertNotContains('Old room ADMINA-OLD2 (inactive)', $names);
            $this->assertEqualsWithDelta((float) ReportQueries::quarters($ids, 1)[0]['revenue'],
                array_sum(array_map('floatval', array_column($rows, 'revenue'))), 0.001, 'room revenues add up to the quarter');
        } finally {
            DB::rollBack();
        }
    }

    public function test_staff_sees_the_reports(): void
    {
        $this->actingAsStaff()->get(route('admin.reports'))->assertOk()->assertSee('Quarterly Reports');
    }

    /* ---- The office choice (DB-DECISIONS 28) ---- */

    private function staffAt(int $venue, bool $both = false): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue, 'both_cedu_offices' => $both]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        return $acct;
    }

    private function signInRole(string $role): static
    {
        return match ($role) {
            'super'      => $this->actingAsAdmin(),
            'bahay_na'   => $this->actingAsAdmin('francishubero@gmail.com'),
            'cedu_na'    => $this->actingAsAdmin('cedu.admin@gmail.com'),
            'venues'     => $this->signInAs($this->staffAt(self::VENUES), 'staff'),
            'hostel'     => $this->signInAs($this->staffAt(self::HOSTEL), 'staff'),
            'two_office' => $this->signInAs($this->staffAt(self::VENUES, true), 'staff'),
            'bahay'      => $this->signInAs($this->staffAt(self::BAHAY), 'staff'),
        };
    }

    /* role => [its choices, narrowest first; the one it opens on] */
    public static function roles(): array
    {
        $cedu = ['usep-venues', 'usep-hostel', 'cedu'];
        return [
            'super admin'        => ['super', ['bahay-alumni', 'usep-venues', 'usep-hostel', 'cedu', 'all'], 'all'],
            'Bahay Alumni admin' => ['bahay_na', ['bahay-alumni'], 'bahay-alumni'],
            'USeP CEDU admin'    => ['cedu_na', $cedu, 'cedu'],
            'Venues staff'       => ['venues', ['usep-venues'], 'usep-venues'],
            'Hostel staff'       => ['hostel', ['usep-hostel'], 'usep-hostel'],
            'two-office staff'   => ['two_office', $cedu, 'cedu'],
            'Bahay Alumni staff' => ['bahay', ['bahay-alumni'], 'bahay-alumni'],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_gets_its_own_office_choices(string $role, array $choices, string $default): void
    {
        $res = $this->signInRole($role)->get(route('admin.reports'))->assertOk();
        $this->assertSame($choices, array_keys($res->viewData('rpChoices')));
        $this->assertSame($default, $res->viewData('rpOffice'));
        $html = $res->getContent();
        if (count($choices) > 1) {
            $this->assertStringContainsString('class="rp-office-tabs"', $html, 'a toggle');
            $this->assertStringContainsString('aria-current="page">' . e($res->viewData('rpChoices')[$default]['label']) . '</a>', $html);
        } else {
            $this->assertStringNotContainsString('class="rp-office-tabs"', $html, 'no toggle for one office');
        }
        /* staff and admins alike see the money and can print */
        $this->assertStringContainsString('id="totalRevenue"', $html);
        $this->assertStringContainsString('onclick="printReport()"', $html);
    }

    /* The URL never widens the choice: anything outside it opens the person's default. */
    public function test_a_url_office_outside_the_choices_falls_back_to_the_default(): void
    {
        $hostelRoom = DB::table('rooms')->where('room_code', 'h1')->value('name');
        $res = $this->signInRole('bahay')->get(route('admin.reports', ['office' => 'usep-hostel']))->assertOk();
        $this->assertSame('bahay-alumni', $res->viewData('rpOffice'));
        $res->assertDontSee($hostelRoom)->assertDontSee('Hostel Rooms');

        foreach (['all', 'bahay-alumni', 'nonsense'] as $asked) {
            $this->assertSame('cedu', $this->signInRole('cedu_na')->get(route('admin.reports', ['office' => $asked]))->viewData('rpOffice'), $asked);
        }
        $this->assertSame('cedu', $this->signInRole('cedu_na')->get('/admin/reports?office[]=all')->assertOk()->viewData('rpOffice'));
        $this->assertSame('usep-hostel', $this->signInRole('cedu_na')->get(route('admin.reports', ['office' => 'usep-hostel']))->viewData('rpOffice'));
    }

    /* Venue-only and hostel-only sections follow the choice. */
    public function test_the_sections_follow_the_office_kind(): void
    {
        $this->signInRole('hostel')->get(route('admin.reports'))->assertOk()
            ->assertSee('Hostel Rooms')->assertDontSee('<h3>Halls</h3>', false)->assertSee('Events Held by Room');
        $this->signInRole('venues')->get(route('admin.reports'))->assertOk()
            ->assertSee('<h3>Halls</h3>', false)->assertDontSee('Hostel Rooms')->assertSee('Events Held by Hall');
        $this->signInRole('cedu_na')->get(route('admin.reports'))->assertOk()
            ->assertSee('<h3>Halls</h3>', false)->assertSee('Hostel Rooms')->assertSee('Events Held by Office');
    }

    /* A combined report adds up the offices' money, but counts a customer who booked at
       both offices once; the printout says so. */
    public function test_a_combined_report_adds_money_but_counts_a_customer_once(): void
    {
        $quarter = function (string $office) {
            $res = $this->actingAsAdmin()->get(route('admin.reports', ['office' => $office]))->assertOk();
            $q = $res->viewData('rpQuarters');
            return end($q) + ['html' => $res->getContent()];
        };
        DB::beginTransaction();
        try {
            $before = array_map($quarter, ['v' => 'usep-venues', 'h' => 'usep-hostel', 'c' => 'cedu']);
            $customer = DB::table('customers')->insertGetId(['full_name' => 'Two Office Customer']);
            $today = now()->toDateString();
            $this->addBooking('r5', $today, 'approved', 'confirmed', 1500, 0, $customer);
            $this->addBooking('h1', $today, 'approved', 'confirmed', 700, 0, $customer);
            $after = array_map($quarter, ['v' => 'usep-venues', 'h' => 'usep-hostel', 'c' => 'cedu']);
        } finally {
            DB::rollBack();
        }
        foreach (['v' => 1500, 'h' => 700, 'c' => 2200] as $k => $money) {
            $this->assertSame($before[$k]['revenue'] + $money, $after[$k]['revenue'], "revenue $k");
        }
        $this->assertSame($before['v']['customers'] + 1, $after['v']['customers']);
        $this->assertSame($before['h']['customers'] + 1, $after['h']['customers']);
        $this->assertSame($before['c']['customers'] + 1, $after['c']['customers'], 'counted once when combined');
        $this->assertSame($after['v']['revenue'] + $after['h']['revenue'], $after['c']['revenue']);
        $note = "Customers are counted once in this combined report";
        $this->assertStringContainsString($note, $after['c']['html']);
        $this->assertStringNotContainsString($note, $after['v']['html']);
    }
}
