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
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'thing'])
            ->assertStatus(400)->assertJson(['error' => 'bad_entity']);
        $this->assertTrue(DB::table('rooms')->where('room_code', 'r1')->exists());
    }

    /* The offices are fixed (DB-DECISIONS 28): the super admin cannot delete one either, empty or not. */
    public function test_nobody_deletes_an_office(): void
    {
        $venueId = $this->makeVenue('Delete Test Venue');
        try {
            $this->actingAsAdmin();
            $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'venue', 'venue_id' => (string) $venueId])
                ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden', 'message' => 'The offices are fixed: an office cannot be deleted.']);
            $this->assertTrue(DB::table('venues')->where('id', $venueId)->exists());
            $this->get(route('admin.venue-management'))->assertOk()->assertDontSee('Delete Venue')->assertDontSee('+ Add Venue');
        } finally {
            $this->dropVenue($venueId);
        }
    }

    public function test_admin_deletes_an_unused_room(): void
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
        } finally {
            $this->dropRoom('r901');
            $this->dropVenue($venueId);
        }
    }

    /* The rows go, and so do the files they pointed at: a deleted room's photo
       folder used to stay on disk for good. */
    public function test_deleting_a_room_removes_its_photo_files(): void
    {
        $venueId = $this->makeVenue('Delete Files Venue');
        $roomId = DB::table('rooms')->insertGetId([
            'venue_id' => $venueId, 'room_code' => 'r902', 'name' => 'Delete Files Room', 'room_type' => 'event',
        ]);
        DB::table('event_room_details')->insert(['room_id' => $roomId, 'attendee_capacity' => 10, 'fee_per_day' => 100]);
        $roomDir = public_path('assets/img/venues/rooms/r902');
        try {
            $this->actingAsAdmin();
            $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => 'r902', 'files' => [$this->pngUpload('a.png')],
            ])->assertOk();
            $this->assertCount(1, glob($roomDir . '/photos/*.png'));

            $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r902'])->assertOk();
            $this->assertDirectoryDoesNotExist($roomDir);
        } finally {
            $this->dropRoom('r902');
            $this->dropVenue($venueId);
        }
    }
}
