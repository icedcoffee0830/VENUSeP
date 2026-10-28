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

    public function test_admin_creates_a_venue_then_edits_it(): void
    {
        $this->actingAsAdmin();
        $res = $this->postForm('admin.venue-save', ['venue_id' => '0', 'name' => 'Port Test Hall', 'description' => 'A hall', 'venue_type' => 'hostel'])
            ->assertOk()->assertJson(['ok' => true, 'created' => true, 'code' => 'PORT-TEST-HALL',
                'message' => 'Venue created. You can add its photo and rooms now.']);
        $id = (int) $res->json('venue_id');
        try {
            $row = DB::table('venues')->where('id', $id)->first();
            $this->assertSame('hostel', $row->venue_type);
            $this->assertSame('A hall', $row->description);

            $this->postForm('admin.venue-save', ['venue_id' => (string) $id, 'name' => 'Port Test Hall 2', 'description' => 'Renamed'])
                ->assertOk()->assertJson(['ok' => true, 'created' => false, 'name' => 'Port Test Hall 2', 'moved' => []]);
            $this->assertSame('Port Test Hall 2', DB::table('venues')->where('id', $id)->value('name'));

            /* a duplicate name is the old 409 */
            $this->postForm('admin.venue-save', ['venue_id' => (string) $id, 'name' => 'Bahay Alumni'])
                ->assertStatus(409)->assertJson(['error' => 'duplicate']);
        } finally {
            $this->dropVenue($id);
        }
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
