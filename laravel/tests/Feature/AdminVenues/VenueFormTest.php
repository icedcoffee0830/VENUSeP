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

    public function test_staff_see_the_list_read_only(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 1]))->assertOk()
            ->assertSee('Edit Venue (Location)')
            ->assertSee('Marites Robles')
            ->assertDontSee('➕ Add Staff');
    }

    /* Add Venue is admin-only (Aron 2026-10-30): staff opening the Add page (no id,
       or an unknown id, which behaves like Add) go back to Venue Management. */
    public function test_staff_opening_add_venue_are_sent_back_to_venue_management(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-form'))->assertRedirect(route('admin.venue-management'));
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 999]))->assertRedirect(route('admin.venue-management'));
    }

    public function test_for_an_admin_an_unknown_id_is_add(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-form'))->assertOk()->assertSee('Add Venue');
        $this->actingAsAdmin()->get(route('admin.venue-form', ['id' => 999]))->assertOk()
            ->assertSee('Add Venue')
            ->assertSee('Photos can be added once the venue is saved and has an id.');
    }
}
