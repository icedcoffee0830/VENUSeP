<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

class VenueSaveTest extends VenuesTestCase
{
    public function test_guest_401_and_get_405(): void
    {
        $this->post(route('admin.venue-save'), ['name' => 'x'])->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->actingAsStaff()->get(route('admin.venue-save'))->assertStatus(405);
    }

    public function test_a_missing_name_is_refused(): void
    {
        $this->actingAsAdmin();
        $this->postForm('admin.venue-save', ['venue_id' => '1', 'name' => '   '])
            ->assertStatus(400)->assertJson(['ok' => false, 'error' => 'bad_name', 'message' => 'Enter a venue name (up to 150 characters).']);
        $this->postForm('admin.venue-save', ['venue_id' => '999999', 'name' => 'Nowhere'])
            ->assertStatus(404)->assertJson(['error' => 'not_found']);
    }

    /* The offices are fixed (DB-DECISIONS 28): nobody adds one, the super admin included;
       staff and admins still edit their own. */
    public function test_nobody_adds_an_office_and_the_buttons_are_gone(): void
    {
        $before = DB::table('venues')->count();
        foreach (['actingAsAdmin', 'actingAsStaff'] as $as) {
            $this->flushSession();
            $this->{$as}();
            $this->postForm('admin.venue-save', ['venue_id' => '0', 'name' => 'Port Test Hall'])
                ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden', 'message' => 'The offices are fixed: no office can be added.']);
            $this->postForm('admin.venue-save', ['name' => 'Port Test Hall', 'venue_type' => 'hostel'])->assertStatus(403);
            $this->get(route('admin.venue-management'))->assertOk()->assertDontSee('+ Add Venue');
        }
        $this->assertSame($before, DB::table('venues')->count());
        $v1 = DB::table('venues')->where('id', 1)->first();
        $this->postForm('admin.venue-save', ['venue_id' => '1', 'name' => $v1->name, 'description' => (string) $v1->description])
            ->assertOk()->assertJson(['ok' => true, 'created' => false]);
        /* a duplicate name is the old 409 */
        $this->flushSession();
        $this->actingAsAdmin();
        $this->postForm('admin.venue-save', ['venue_id' => '1', 'name' => 'USeP Venues'])
            ->assertStatus(409)->assertJson(['error' => 'duplicate']);
    }

    public function test_only_an_admin_moves_staff_and_only_staff_accounts_move(): void
    {
        $this->actingAsStaff();
        $this->postForm('admin.venue-save', ['venue_id' => '1', 'name' => 'Bahay Alumni', 'move_staff' => '[74]'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->flushSession();

        $this->actingAsAdmin();
        $this->postForm('admin.venue-save', ['venue_id' => '2', 'name' => 'USeP Venues', 'move_staff' => '[1]'])
            ->assertStatus(400)->assertJson(['error' => 'bad_staff']);   // user 1 is an admin
        $this->assertSame(1, (int) DB::table('staff')->where('user_id', 74)->value('venue_id'));
        try {
            $this->postForm('admin.venue-save', ['venue_id' => '2', 'name' => 'USeP Venues', 'move_staff' => '[74]'])
                ->assertOk()->assertJson(['ok' => true, 'moved' => ['Maco Case']]);
            $this->assertSame(2, (int) DB::table('staff')->where('user_id', 74)->value('venue_id'));
        } finally {
            DB::table('staff')->where('user_id', 74)->update(['venue_id' => 1]);
        }
    }
}
