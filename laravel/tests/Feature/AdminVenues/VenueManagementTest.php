<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

class VenueManagementTest extends VenuesTestCase
{
    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.venue-management'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.venue-management'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_see_their_venue_and_every_room_including_disabled_ones_but_no_delete(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('<title>VENUSeP | Venue Management</title>', false)
            ->assertSee('Manage locations and the rooms available under them.')
            ->assertSee('Bahay Alumni')
            ->assertSee('Alumni Grand Ballroom')
            ->assertSee('Alumni Boardroom')                       // r3 is disabled — still listed here
            ->assertSee('Hidden from customers')
            ->assertSee(route('admin.venue-form', ['id' => 1]), false)
            ->assertSee(route('admin.room-form', ['id' => 'r1']), false)
            ->assertSee('admin\\/room-toggle-active', false)
            ->assertSee('data-toggle-id="r3"', false)
            ->assertDontSee('data-delete-entity="', false)           // delete is admin-only
            ->assertSee('const DISCOUNT_PERCENT = ', false)          // pricing.php's script, now inside the page
            ->assertSee('Change history');
    }

    /* Aron 2026-10-31: staff (venue 1, Bahay Alumni) see only their own venue —
       other venues' cards and rooms are not listed at all. */
    public function test_staff_see_only_their_own_venues_rooms(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('data-toggle-id="r1"', false)
            ->assertSee(route('admin.room-form', ['id' => 'r1']), false)
            ->assertDontSee('USeP Gymnasium')                                    // r5 = USeP Venues
            ->assertDontSee('Hostel Room 1')
            ->assertDontSee('data-toggle-id="r5"', false)
            ->assertDontSee(route('admin.room-form', ['id' => 'r5']), false)
            ->assertDontSee('data-toggle-id="h1"', false)
            ->assertDontSee(route('admin.hostel-room-form', ['id' => 'h1']), false);
    }

    /* Aron 2026-10-30 (round 3): staff see "Edit Details" only on their own venue's
       card and the long-closure "Reopen" reminder only on their own venue's rooms
       (r4 is venue 1's, closed since July); admins see every one. */
    /* The legacy room lists load once per PHP process, so staff and admin views
       are checked in separate tests (each test is its own process). */
    private function withLongClosures(callable $check): void
    {
        $from = date('Y-m-d', strtotime('-30 days'));
        $ids = [];
        foreach (['r5', 'h1'] as $code) {
            $ids[] = DB::table('maintenance_windows')->insertGetId([
                'room_id' => DB::table('rooms')->where('room_code', $code)->value('id'),
                'from_date' => $from, 'until_date' => null, 'reason' => 'test closure', 'blocks_booking' => 1,
            ]);
        }
        try {
            $check(fn (string $form, string $code) => '<a href="' . route('admin.' . $form, ['id' => $code])
                . '" title="[SIM] opens the room\'s maintenance window">Reopen</a>');
        } finally {
            DB::table('maintenance_windows')->whereIn('id', $ids)->delete();
        }
    }

    public function test_staff_get_edit_details_and_reopen_only_on_their_own_venue(): void
    {
        $this->withLongClosures(fn ($reopen) => $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertSee(route('admin.venue-form', ['id' => 1]), false)
            ->assertDontSee(route('admin.venue-form', ['id' => 2]), false)
            ->assertDontSee(route('admin.venue-form', ['id' => 3]), false)
            ->assertSee($reopen('room-form', 'r4'), false)
            ->assertDontSee($reopen('room-form', 'r5'), false)
            ->assertDontSee($reopen('hostel-room-form', 'h1'), false));
    }

    public function test_admins_get_edit_details_and_reopen_on_every_venue(): void
    {
        $this->withLongClosures(fn ($reopen) => $this->actingAsAdmin()->get(route('admin.venue-management'))->assertOk()
            ->assertSee(route('admin.venue-form', ['id' => 2]), false)
            ->assertSee(route('admin.venue-form', ['id' => 3]), false)
            ->assertSee($reopen('room-form', 'r4'), false)
            ->assertSee($reopen('room-form', 'r5'), false)
            ->assertSee($reopen('hostel-room-form', 'h1'), false));
    }

    /* A hostel rate with centavos shows them (was rounded to the peso); a whole
       rate stays without ".00". */
    public function test_hostel_rate_keeps_its_centavos_on_the_card(): void
    {
        $h2 = (int) DB::table('rooms')->where('room_code', 'h2')->value('id');
        $old = DB::table('hostel_room_details')->where('room_id', $h2)->value('rate_per_head_per_night');
        DB::table('hostel_room_details')->where('room_id', $h2)->update(['rate_per_head_per_night' => 350.50]);
        try {
            $this->actingAsStaff('jakemerson@gmail.com')->get(route('admin.venue-management'))->assertOk()   // USeP Hostel staff
                ->assertSee('₱350.50/head')
                ->assertDontSee('₱351/head');
        } finally {
            DB::table('hostel_room_details')->where('room_id', $h2)->update(['rate_per_head_per_night' => $old]);
        }
    }

    public function test_admins_get_the_delete_buttons(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('data-toggle-id="r5"', false)
            ->assertSee('data-toggle-id="h1"', false)
            ->assertSee(route('admin.hostel-room-form', ['id' => 'h1']), false)
            ->assertSee('data-delete-entity="venue"', false)
            ->assertSee('data-delete-entity="room"', false)
            ->assertSee('admin\\/catalog-delete', false);
    }
}
