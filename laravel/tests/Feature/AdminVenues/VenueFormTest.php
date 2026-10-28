<?php

namespace Tests\Feature\AdminVenues;

class VenueFormTest extends VenuesTestCase
{
    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.venue-form'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.venue-form'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_edits_a_venue_with_its_staff_and_the_picker(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-form', ['id' => 1]))->assertOk()
            ->assertSee('<title>VENUSeP | Venue Form</title>', false)
            ->assertSee('Edit Venue (Location)')
            ->assertSee('value="Bahay Alumni"', false)
            ->assertSee('Marites Robles')                     // handles venue 1
            ->assertSee('➕ Add Staff')
            ->assertSee('data-name="Jake Emerson"', false)     // handles venue 3 — in the picker
            ->assertSee('../assets/img/venues/covers/1.jpg', false)
            ->assertSee('admin\\/venue-save', false)
            ->assertSee('admin\\/venue-photo-api', false);
    }

    public function test_staff_see_the_list_read_only_and_an_unknown_id_is_add(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 999]))->assertOk()
            ->assertSee('Add Venue')
            ->assertSee('Photos can be added once the venue is saved and has an id.')
            ->assertSee('No staff handle this venue yet.')
            ->assertDontSee('➕ Add Staff');
    }
}
