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
            ->assertSee('id="roomLoc" class="vm-control" type="text" value="USeP Hostel" readonly', false)   // fixed: no list to pick from
            ->assertDontSee('<li class="vm-dd-option">Bahay Alumni</li>', false)
            ->assertSee('admin\/room-save', false)
            ->assertSee('admin\/room-photos-api', false)
            ->assertSee('"group":"Hostel"', false);
    }

    public function test_an_event_room_id_behaves_like_add(): void
    {
        $this->actingAsAdmin()->get(route('admin.hostel-room-form', ['id' => 'r1']))->assertOk()
            ->assertSee('Add Hostel Room')->assertSee('id="vmRoomId" value=""', false)
            ->assertSee('value="350.00"', false)
            ->assertSee('type="text" value="USeP Hostel" readonly', false);
    }

    public function test_the_cedu_admin_can_open_the_add_form(): void
    {
        $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.hostel-room-form'))->assertOk()->assertSee('Add Hostel Room');
    }

    public function test_hostel_staff_cannot_open_the_add_form(): void
    {
        $this->actingAsStaff('jakemerson@gmail.com')->get(route('admin.hostel-room-form'))->assertRedirect(route('admin.venue-management'));
    }

    public function test_an_admin_of_another_group_cannot_open_the_add_form(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.hostel-room-form'))->assertRedirect(route('admin.venue-management'));
    }
}
