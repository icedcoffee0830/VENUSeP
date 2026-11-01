<?php

namespace Tests\Feature\AdminSettings;

use App\Http\Controllers\Customer\BookingRules;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\TestCase;

/* Aron, 2026-11-01: each office runs itself, and only admins change its settings. */
class OfficeSettingsTest extends TestCase
{
    use SeedsBookings;

    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->saved = DB::table('venues')->get()->map(fn ($v) => (array) $v)->all();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $v) {
            DB::table('venues')->where('id', $v['id'])->update($v);
        }
        DB::table('venue_closures')->delete();
        parent::tearDown();
    }

    private function send(array $data)
    {
        return $this->postWithToken(route('admin.office-save'), $data);
    }

    private function settings(array $change = []): array
    {
        return $change + ['venue_id' => 1, 'action' => 'save', 'opens' => '07:00', 'closes' => '22:00', 'lead' => '12', 'gap' => '60',
            'discount' => '20', 'grace' => '3', 'days' => []];
    }

    private function venue(int $id = 1): object
    {
        return DB::table('venues')->where('id', $id)->first();
    }

    public function test_staff_are_kept_out(): void
    {
        $this->actingAsStaff()->get(route('admin.office-settings'))->assertRedirect(route('admin.dashboard'));
        $this->send($this->settings(['discount' => '5', 'reason' => 'x']))->assertStatus(403)->assertJson(['error' => 'not_admin']);
        $this->assertSame(20, (int) $this->venue()->discount_percent);
    }

    public function test_changing_one_office_leaves_the_others_alone_and_keeps_a_history(): void
    {
        $others = (array) $this->venue(2);
        $this->actingAsAdmin();
        $this->get(route('admin.office-settings'))->assertOk()->assertSee('Bahay Alumni')->assertSee('USeP Hostel');
        $this->send($this->settings(['opens' => '08:00', 'closes' => '17:00', 'days' => ['0'], 'lead' => '24']))->assertOk();
        $v = $this->venue();
        $this->assertSame('08:00:00', $v->opens_at);
        $this->assertSame('0', $v->closed_weekdays);
        $this->assertSame(24, (int) $v->lead_hours);
        $this->assertSame($others, (array) $this->venue(2));
        $this->assertSame(4, DB::table('venue_settings_history')->where('venue_id', 1)->whereIn('field', ['opens', 'closes', 'days', 'lead'])
            ->where('changed_at', '>=', now()->subMinute())->count());
    }

    public function test_money_settings_need_a_reason_and_bad_values_are_refused(): void
    {
        $this->actingAsAdmin();
        $this->send($this->settings(['discount' => '30']))->assertStatus(400)->assertJson(['message' => 'A reason is required when the discount or grace days change.']);
        $this->send($this->settings(['discount' => '101', 'reason' => 'x']))->assertStatus(400);
        $this->send($this->settings(['opens' => '17:00', 'closes' => '08:00']))->assertStatus(400);
        $this->send($this->settings(['days' => ['0', '1', '2', '3', '4', '5', '6']]))->assertStatus(400);
        $this->send($this->settings())->assertStatus(400)->assertJson(['message' => 'Nothing has changed.']);
        $this->send($this->settings(['discount' => '30', 'reason' => 'New policy']))->assertOk();
        $this->assertSame(30, (int) $this->venue()->discount_percent);
    }

    public function test_the_hostel_setting_ignores_hours(): void
    {
        $hostel = DB::table('venues')->where('venue_type', 'hostel')->first();
        $this->actingAsAdmin();
        $this->send(['venue_id' => $hostel->id, 'action' => 'save', 'discount' => '15', 'grace' => '3', 'days' => [], 'opens' => '01:00', 'closes' => '02:00', 'reason' => 'Hostel rate'])->assertOk();
        $this->assertSame('07:00:00', $this->venue($hostel->id)->opens_at);
        $this->assertSame(15, (int) $this->venue($hostel->id)->discount_percent);
    }

    public function test_a_closure_lists_the_bookings_inside_it_and_changes_none(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $day = DB::table('venue_booking_slots')->where('booking_id', $id)->value('slot_date');
        $this->actingAsAdmin();
        $this->send(['venue_id' => 1, 'action' => 'closure_add', 'from' => $day, 'until' => $day, 'reason' => ''])->assertStatus(400);
        $out = $this->send(['venue_id' => 1, 'action' => 'closure_add', 'from' => $day, 'until' => $day, 'reason' => 'Repairs'])->assertOk();
        $this->assertContains($ref, $out->json('affected'));
        $this->assertSame('approved', $this->booking($id)->reservation_status);
        $this->assertSame(1, (int) DB::selectOne('SELECT fn_room_hard_blocked((SELECT id FROM rooms WHERE room_code = ?), ?) AS x', ['r1', $day])->x);
        $this->assertSame(0, (int) DB::selectOne('SELECT fn_room_hard_blocked((SELECT id FROM rooms WHERE room_code = ?), ?) AS x', ['r5', $day])->x);

        $closure = DB::table('venue_closures')->where('venue_id', 1)->value('id');
        $this->send(['venue_id' => 1, 'action' => 'closure_remove', 'closure_id' => $closure])->assertOk();
        $this->assertSame(0, DB::table('venue_closures')->where('venue_id', 1)->count());
    }

    public function test_a_closed_weekday_blocks_only_that_office(): void
    {
        DB::table('venues')->where('id', 1)->update(['closed_weekdays' => '0,6']);
        $sunday = date('Y-m-d', strtotime('next sunday'));
        $this->assertSame(1, (int) DB::selectOne('SELECT fn_office_closed(1, ?) AS x', [$sunday])->x);
        $this->assertSame(0, (int) DB::selectOne('SELECT fn_office_closed(2, ?) AS x', [$sunday])->x);
    }

    /* A payment deadline never lands on a day the office is closed. */
    public function test_a_post_pay_deadline_moves_past_a_closed_day(): void
    {
        [$id] = $this->seedVenueBooking('r1', 'approved', 'await_event', 'gcash', false, false);
        $friday = date('Y-m-d', strtotime('next friday'));      // + 1 grace day = Saturday, then Sunday, so Monday
        DB::table('venue_booking_details')->where('booking_id', $id)->update(['start_date' => $friday, 'end_date' => $friday]);
        DB::table('venue_booking_slots')->where('booking_id', $id)->update(['slot_date' => $friday]);
        DB::table('venues')->where('id', 1)->update(['closed_weekdays' => '0,6', 'postpay_grace_days' => 1]);
        $deadline = DB::selectOne('SELECT fn_payment_deadline(?) AS d', [$id])->d;
        $this->assertSame(date('Y-m-d', strtotime($friday . ' +3 days')), substr($deadline, 0, 10));
    }

    /* The rate a customer saw when booking is the rate they get. */
    public function test_approving_keeps_the_rate_shown_at_booking(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked', 'gcash', true, false);
        DB::table('bookings')->where('id', $id)->update(['quoted_discount_percent' => 20]);
        DB::table('venues')->where('id', 1)->update(['discount_percent' => 10]);
        $this->actingAsStaff();
        $this->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => 'approve', 'discount' => '1'])->assertOk();
        $this->assertSame(20.0, (float) $this->booking($id)->discount_percent);
    }

    public function test_a_booking_is_limited_to_the_year_ahead(): void
    {
        config(['venusep.max_days_ahead' => 365]);
        $this->assertNull(BookingRules::venueTooFarAhead(date('Y-m-d', strtotime('+365 days'))));
        $this->assertStringContainsString('1 year ahead', (string) BookingRules::venueTooFarAhead(date('Y-m-d', strtotime('+366 days'))));
    }
}
