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

    /* Office details are for admins only (DB-DECISIONS 28): staff are sent back from every form. */
    public function test_staff_are_kept_out_of_every_venue_form(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 1]))->assertRedirect(route('admin.venue-management'));
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 2]))->assertRedirect(route('admin.venue-management'));
    }

    /* The departments never see each other's things (Aron 2026-10-31). */
    public function test_a_group_admin_edits_its_own_offices_only_and_sees_only_their_people(): void
    {
        $this->actingAsAdmin('francishubero@gmail.com');
        $this->get(route('admin.venue-form', ['id' => 1]))->assertOk()->assertSee('Marites Robles')->assertDontSee('Jake Emerson');
        $this->get(route('admin.venue-form', ['id' => 2]))->assertRedirect(route('admin.venue-management'));
    }

    /* Add Venue is admin-only (Aron 2026-10-30): staff opening the Add page (no id,
       or an unknown id, which behaves like Add) go back to Venue Management. */
    public function test_staff_opening_add_venue_are_sent_back_to_venue_management(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-form'))->assertRedirect(route('admin.venue-management'));
        $this->actingAsStaff()->get(route('admin.venue-form', ['id' => 999]))->assertRedirect(route('admin.venue-management'));
    }

    /* The offices are fixed (DB-DECISIONS 28): the super admin has no Add page either. */
    public function test_the_super_admin_opening_add_venue_is_sent_back_too(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-form'))->assertRedirect(route('admin.venue-management'));
        $this->actingAsAdmin()->get(route('admin.venue-form', ['id' => 999]))->assertRedirect(route('admin.venue-management'));
    }
}
