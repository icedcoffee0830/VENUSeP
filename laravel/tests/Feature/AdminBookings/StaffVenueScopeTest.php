<?php

namespace Tests\Feature\AdminBookings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* The three departments never see each other's bookings (2026-10-31): a staff
   member's lists, figures and booking pages cover their own venue only; admins
   see every venue. staff@gmail.com = venue 1 (Bahay Alumni). */
class StaffVenueScopeTest extends TestCase
{
    private function signIn(string $email, string $type): void
    {
        session(['user_id' => (int) DB::table('users')->where('email', $email)->value('id'), 'account_type' => $type]);
        legacy('bookings');
    }

    private function venuesOf(array $bookings): array
    {
        $ids = array_column($bookings, 'id');
        return DB::table('bookings as b')->join('rooms as r', 'r.id', '=', 'b.room_id')
            ->whereIn('b.id', $ids ?: [0])->distinct()->pluck('r.venue_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
    }

    public function test_staff_lists_cover_their_own_venue_only(): void
    {
        $this->signIn('staff@gmail.com', 'staff');
        $this->assertSame([1], $this->venuesOf(bookings_all()));               // queue, calendar, transactions, detail page
        $this->assertSame(['Bahay Alumni'], array_column(report_by_venue(), 'venue'));
    }

    public function test_admins_see_every_venue(): void
    {
        $this->signIn('admin@gmail.com', 'admin');
        $this->assertGreaterThan(1, count($this->venuesOf(bookings_all())));
        $this->assertGreaterThan(1, count(report_by_venue()));
    }

    public function test_staff_revenue_is_their_venues_share(): void
    {
        $this->signIn('admin@gmail.com', 'admin');
        $all = array_sum(array_column(report_quarters(4), 'bookings'));
        $this->signIn('staff@gmail.com', 'staff');
        $mine = array_sum(array_column(report_quarters(4), 'bookings'));
        $this->assertLessThan($all, $mine);
    }

    /* Aron 2026-10-31: staff see only their designated venue — the Dashboard's
       venue box and Payment Settings' GCash cards too. */
    public function test_staff_dashboard_and_payment_cards_show_their_venue_only(): void
    {
        $this->actingAsStaff()->get(route('admin.payment-settings'))->assertOk()
            ->assertSee('data-venue="Bahay Alumni"', false)
            ->assertDontSee('data-venue="USeP Venues"', false)
            ->assertDontSee('data-venue="USeP Hostel"', false);
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()
            ->assertSee('Managing: Bahay Alumni')
            ->assertDontSee('Dormitory rooms booked per bed.');    // USeP Hostel's description in the venue box
    }

    /* Aron 2026-11-01: the offices are fully separate. Every page a staff member can open,
       with a booking of another office on file: not its event, room or reference. */
    public function test_no_staff_page_shows_another_offices_records(): void
    {
        $room = DB::table('rooms')->where('room_code', 'r5')->value('id');
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => DB::table('customers')->value('id'), 'room_id' => $room, 'booking_type' => 'venue',
            'reservation_status' => 'approved', 'payment_status' => 'confirmed', 'payment_method' => 'gcash',
            'is_usep_affiliated' => 0, 'affiliation_verified' => 0, 'room_price' => 100, 'discount_percent' => 0,
            'discount_amount' => 0, 'total_amount' => 100, 'refunds_allowed' => 0, 'submitted_at' => now(), 'approved_at' => now(),
        ]);
        $day = now()->addDays(random_int(400, 4000))->toDateString();
        DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Other office secret',
            'start_date' => $day, 'end_date' => $day, 'attendee_count' => 10]);
        $ref = 'VB-' . now()->year . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
        $otherRooms = DB::table('rooms as r')->join('venues as v', 'v.id', '=', 'r.venue_id')
            ->where('v.name', '<>', 'Bahay Alumni')->pluck('r.name')->all();
        try {
            foreach (['admin.dashboard', 'admin.reports', 'admin.calendar', 'admin.transaction-history', 'admin.booking-requests',
                      'admin.venue-management', 'admin.payment-settings', 'admin.room-form', 'admin.hostel-room-form', 'admin.venue-form'] as $name) {
                $html = $this->actingAsStaff()->get(route($name))->getContent();   // venue-form sends staff to their own venue
                $html = preg_replace('/placeholder="[^"]*"/', '', $html);   // an empty form's example text is not a record
                foreach (array_merge(['Other office secret', $ref], $otherRooms) as $word) {
                    $this->assertStringNotContainsString(e($word), $html, "$name shows \"$word\"");
                }
            }
            $html = $this->actingAsStaff()->get(route('admin.booking-request', ['id' => $ref]))->getContent();
            $this->assertStringNotContainsString('Other office secret', $html);
            $html = $this->actingAsStaff()->get(route('admin.room-form', ['id' => 'r5']))->getContent();
            $this->assertStringNotContainsString(e(DB::table('rooms')->where('room_code', 'r5')->value('name')), $html);
        } finally {
            DB::delete('DELETE FROM bookings WHERE id = ?', [$id]);
        }
    }

    public function test_a_customers_own_lists_are_not_narrowed(): void
    {
        $this->signIn('customer@gmail.com', 'customer');
        $this->assertNull(staff_list_venue());
    }
}
