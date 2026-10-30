<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
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

    /* "Today's Bookings · Rooms in use today": every booking that has today among
       its days, not only those that START today. Rows live in a rolled-back transaction. */
    public function test_todays_bookings_counts_stays_and_events_already_under_way(): void
    {
        $today = function (): int {
            preg_match("/Today's Bookings<\/h3>\s*<div class=\"number\">(\d+)</", $this->actingAsAdmin()->get(route('admin.dashboard'))->getContent(), $m);
            return (int) $m[1];
        };
        $add = function (string $type, string $code, string $from, string $to, string $res = 'approved'): void {
            $room = DB::table('rooms')->where('room_code', $code)->value('id');
            $id = DB::table('bookings')->insertGetId([
                'customer_id' => DB::table('customers')->value('id'), 'room_id' => $room, 'booking_type' => $type,
                'reservation_status' => $res, 'payment_status' => 'confirmed', 'payment_method' => 'gcash',
                'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => 100, 'discount_percent' => 0,
                'discount_amount' => 0, 'total_amount' => 100, 'refunds_allowed' => 0, 'submitted_at' => now()->subDays(5),
                'approved_at' => now()->subDays(4),
            ]);
            $type === 'venue'
                ? DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Dashboard test', 'start_date' => $from, 'end_date' => $to, 'attendee_count' => 10])
                : DB::table('hostel_booking_details')->insert(['booking_id' => $id, 'check_in_date' => $from, 'check_out_date' => $to, 'checked_in' => 1]);
        };
        $d = fn (int $days) => now()->addDays($days)->toDateString();

        DB::beginTransaction();
        try {
            $before = $today();
            $add('venue', 'r1', $d(-1), $d(1));                // a three-day event, day 2
            $add('hostel', 'h1', $d(-2), $d(1));               // a stay, third night
            $add('hostel', 'h1', $d(-2), $d(0));               // checks out this morning: not in use
            $add('venue', 'r1', $d(-1), $d(1), 'cancelled');   // fell through
            $this->assertSame($before + 2, $today());
        } finally {
            DB::rollBack();
        }
    }

    /* "Welcome, <who> | Managing: <what>": the signed-in person (the header's name
       rule), every active venue for an admin, only their own venue for staff. */
    public function test_welcome_line_names_the_signed_in_user_and_their_venues(): void
    {
        $name = fn (string $email) => DB::table('users as u')->leftJoin('staff as s', 's.user_id', '=', 'u.id')
            ->where('u.email', $email)->selectRaw('COALESCE(s.full_name, u.username, u.email) AS n')->value('n');
        $venues = DB::table('venues')->where('is_active', 1)->orderBy('id')->pluck('name')->all();
        $last = array_pop($venues);
        $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Welcome, ' . $name('admin@gmail.com') . ' | Managing: ' . implode(', ', $venues) . ' and ' . $last)
            ->assertDontSee('Welcome, Admin |');

        $this->flushSession();
        $own = DB::table('staff as s')->join('users as u', 'u.id', '=', 's.user_id')->join('venues as v', 'v.id', '=', 's.venue_id')
            ->where('u.email', 'staff@gmail.com')->value('v.name');
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Welcome, ' . $name('staff@gmail.com') . ' | Managing: ' . $own . '</p>', false);
    }

    public function test_staff_sees_the_dashboard(): void
    {
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()->assertSee('Admin Dashboard');
    }
}
