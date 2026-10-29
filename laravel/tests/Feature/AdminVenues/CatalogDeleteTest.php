<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

class CatalogDeleteTest extends VenuesTestCase
{
    public function test_guest_gets_401_and_staff_get_403(): void
    {
        $this->post(route('admin.catalog-delete'))->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->actingAsStaff();
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r1'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden', 'message' => 'Only administrators can delete rooms or venues.']);
    }

    public function test_unconfirmed_and_rooms_with_history_are_refused(): void
    {
        $this->actingAsAdmin();
        $this->postForm('admin.catalog-delete', ['entity' => 'room', 'room_id' => 'r1'])
            ->assertStatus(400)->assertJson(['error' => 'confirmation']);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r1'])
            ->assertStatus(409)->assertJson(['error' => 'booking_history']);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'venue', 'venue_id' => '1'])
            ->assertStatus(409)->assertJson(['error' => 'has_rooms']);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'thing'])
            ->assertStatus(400)->assertJson(['error' => 'bad_entity']);
        $this->assertTrue(DB::table('rooms')->where('room_code', 'r1')->exists());
    }

    public function test_admin_deletes_an_unused_room_and_an_empty_venue(): void
    {
        $venueId = $this->makeVenue('Delete Test Venue');
        $roomId = DB::table('rooms')->insertGetId([
            'venue_id' => $venueId, 'room_code' => 'r901', 'name' => 'Delete Test Room', 'room_type' => 'event',
        ]);
        DB::table('event_room_details')->insert(['room_id' => $roomId, 'attendee_capacity' => 10, 'fee_per_day' => 100]);
        try {
            $this->actingAsAdmin();
            $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r901'])
                ->assertOk()->assertJson(['ok' => true, 'entity' => 'room', 'message' => 'Delete Test Room was deleted.']);
            $this->assertFalse(DB::table('rooms')->where('id', $roomId)->exists());
            $this->assertFalse(DB::table('event_room_details')->where('room_id', $roomId)->exists());

            $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'venue', 'venue_id' => (string) $venueId])
                ->assertOk()->assertJson(['ok' => true, 'entity' => 'venue', 'message' => 'Delete Test Venue was deleted.']);
            $this->assertFalse(DB::table('venues')->where('id', $venueId)->exists());
        } finally {
            $this->dropRoom('r901');
            $this->dropVenue($venueId);
        }
    }
}
