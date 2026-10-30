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

    public function test_a_customers_own_lists_are_not_narrowed(): void
    {
        $this->signIn('customer@gmail.com', 'customer');
        $this->assertNull(staff_list_venue());
    }
}
