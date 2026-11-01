<?php

namespace Tests\Feature\AdminVenues;

class HostelRoomFormTest extends VenuesTestCase
{
    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.hostel-room-form'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.hostel-room-form'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_edit_a_hostel_room(): void
    {
        $this->actingAsStaff('jakemerson@gmail.com')->get(route('admin.hostel-room-form', ['id' => 'h1']))->assertOk()   // USeP Hostel staff
            ->assertSee('<title>VENUSeP | Hostel Room Form</title>', false)
            ->assertSee('Edit Hostel Room')
            ->assertSee('id="vmRoomId" value="h1"', false)
            ->assertSee('value="Hostel Room 1"', false)
            ->assertSee('<li class="vm-dd-option">USeP Hostel</li>', false)
            ->assertDontSee('<li class="vm-dd-option">Bahay Alumni</li>', false)   // staff list their own venue only
            ->assertSee('admin\/room-save', false)
            ->assertSee('admin\/room-photos-api', false)
            ->assertSee('"group":"Hostel"', false);
    }

    public function test_an_event_room_id_behaves_like_add(): void
    {
        $this->actingAsStaff()->get(route('admin.hostel-room-form', ['id' => 'r1']))->assertOk()
            ->assertSee('Add Hostel Room')->assertSee('id="vmRoomId" value=""', false)
            ->assertSee('value="350.00"', false);
    }
}
