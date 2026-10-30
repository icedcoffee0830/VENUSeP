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

    public function test_staff_see_venues_and_every_room_including_disabled_ones_but_no_delete(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('<title>VENUSeP | Venue Management</title>', false)
            ->assertSee('Manage locations and the rooms available under them.')
            ->assertSee('Bahay Alumni')->assertSee('USeP Hostel')
            ->assertSee('Alumni Grand Ballroom')
            ->assertSee('Alumni Boardroom')                       // r3 is disabled — still listed here
            ->assertSee('Hidden from customers')
            ->assertSee('Hostel Room 1')
            ->assertSee(route('admin.venue-form', ['id' => 1]), false)
            ->assertSee(route('admin.room-form', ['id' => 'r1']), false)
            ->assertSee('admin\\/room-toggle-active', false)
            ->assertSee('data-toggle-id="r3"', false)
            ->assertDontSee('data-delete-entity="', false)           // delete is admin-only
            ->assertSee('const DISCOUNT_PERCENT = ', false)          // pricing.php's script, now inside the page
            ->assertSee('Change history');
    }

    /* Aron 2026-10-30: staff (venue 1, Bahay Alumni) see Edit/Disable only on
       their own venue's rooms; the endpoints already refuse the others. */
    public function test_staff_get_edit_and_disable_only_on_their_own_venues_rooms(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('data-toggle-id="r1"', false)
            ->assertSee(route('admin.room-form', ['id' => 'r1']), false)
            ->assertSee('USeP Gymnasium')                                        // r5 still listed
            ->assertDontSee('data-toggle-id="r5"', false)
            ->assertDontSee(route('admin.room-form', ['id' => 'r5']), false)
            ->assertDontSee('data-toggle-id="h1"', false)
            ->assertDontSee(route('admin.hostel-room-form', ['id' => 'h1']), false);
    }

    /* A hostel rate with centavos shows them (was rounded to the peso); a whole
       rate stays without ".00". */
    public function test_hostel_rate_keeps_its_centavos_on_the_card(): void
    {
        $h2 = (int) DB::table('rooms')->where('room_code', 'h2')->value('id');
        $old = DB::table('hostel_room_details')->where('room_id', $h2)->value('rate_per_head_per_night');
        DB::table('hostel_room_details')->where('room_id', $h2)->update(['rate_per_head_per_night' => 350.50]);
        try {
            $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
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
