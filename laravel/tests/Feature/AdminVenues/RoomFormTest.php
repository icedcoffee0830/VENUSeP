<?php

namespace Tests\Feature\AdminVenues;

class RoomFormTest extends VenuesTestCase
{
    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.room-form'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.room-form'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_edit_an_event_room(): void
    {
        $this->actingAsStaff()->get(route('admin.room-form', ['id' => 'r1']))->assertOk()
            ->assertSee('<title>VENUSeP | Room Form</title>', false)
            ->assertSee('Edit Room Details')
            ->assertSee('id="vmRoomId" value="r1"', false)
            ->assertSee('value="Alumni Grand Ballroom"', false)
            ->assertSee('id="roomLoc" class="vm-control" type="text" value="Bahay Alumni" readonly', false)   // staff never move a room
            ->assertDontSee('<li class="vm-dd-option">', false)
            ->assertSee('pannellum.js', false)
            ->assertSee('admin\\/room-save', false)
            ->assertSee('admin\\/room-photos-api', false)
            ->assertSee('var AMENITY_POOL = [{"group":"Audio-visual"', false)
            ->assertDontSee('"group":"Hostel"', false);
    }

    public function test_an_unknown_or_hostel_id_behaves_like_add(): void
    {
        $this->actingAsAdmin()->get(route('admin.room-form', ['id' => 'h1']))->assertOk()
            ->assertSee('Add Room')->assertSee('id="vmRoomId" value=""', false)
            ->assertSee('Photos can be added once the room is saved and has an id.');
    }

    /* USeP Hostel was in this list, and saving there was refused (2026-11-05). */
    public function test_the_event_forms_location_lists_event_venues_only(): void
    {
        $this->actingAsAdmin()->get(route('admin.room-form'))->assertOk()
            ->assertSee('<li class="vm-dd-option">Bahay Alumni</li>', false)
            ->assertSee('<li class="vm-dd-option">USeP Venues</li>', false)
            ->assertDontSee('<li class="vm-dd-option">USeP Hostel</li>', false)
            ->assertDontSee('data-office="USeP Hostel"', false);
    }

    public function test_staff_cannot_open_the_add_form(): void
    {
        $this->actingAsStaff()->get(route('admin.room-form'))->assertRedirect(route('admin.venue-management'));
    }
}
