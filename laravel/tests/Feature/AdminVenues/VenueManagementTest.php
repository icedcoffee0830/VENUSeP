<?php

namespace Tests\Feature\AdminVenues;

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
            ->assertSee(route('admin.hostel-room-form', ['id' => 'h1']), false)
            ->assertSee('admin\\/room-toggle-active', false)
            ->assertSee('data-toggle-id="r3"', false)
            ->assertDontSee('data-delete-entity="', false)           // delete is admin-only
            ->assertSee('Change history');
    }

    public function test_admins_get_the_delete_buttons(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-management'))->assertOk()
            ->assertSee('data-delete-entity="venue"', false)
            ->assertSee('data-delete-entity="room"', false)
            ->assertSee('admin\\/catalog-delete', false);
    }
}
