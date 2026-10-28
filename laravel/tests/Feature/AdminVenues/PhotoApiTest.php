<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/* room-photos-api + venue-photo-api. Uploads go to a room / venue the test
   creates, and the files are deleted again afterwards. */
class PhotoApiTest extends VenuesTestCase
{
    public function test_guests_get_401_from_both_apis(): void
    {
        $this->get(route('admin.room-photos-api', ['action' => 'list', 'room_id' => 'r1']))->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->get(route('admin.venue-photo-api', ['action' => 'list', 'venue_id' => '1']))->assertStatus(401);
    }

    public function test_listing_and_bad_requests(): void
    {
        $this->actingAsStaff();
        $this->get(route('admin.room-photos-api', ['action' => 'list', 'room_id' => 'r1']))->assertOk()
            ->assertJson(['ok' => true, 'maxPhotos' => 5])->assertJsonStructure(['photos', 'pano']);
        $this->get(route('admin.room-photos-api', ['action' => 'list', 'room_id' => '../etc']))->assertStatus(400)->assertJson(['error' => 'bad_room']);
        $this->get(route('admin.room-photos-api', ['action' => 'zap', 'room_id' => 'r1']))->assertStatus(400)->assertJson(['error' => 'bad_action']);
        $this->postForm('admin.room-photos-api', ['action' => 'upload', 'room_id' => 'r1'])
            ->assertStatus(400)->assertJson(['error' => 'no_file', 'message' => 'Choose at least one photo.']);
        $this->get(route('admin.venue-photo-api', ['action' => 'list', 'venue_id' => '1']))->assertOk()
            ->assertJson(['ok' => true, 'cover' => '../assets/img/venues/covers/1.jpg']);
        $this->get(route('admin.venue-photo-api', ['action' => 'list', 'venue_id' => 'x']))->assertStatus(400)->assertJson(['error' => 'bad_venue']);
    }

    public function test_room_gallery_and_panorama_upload_reorder_delete(): void
    {
        $venueId = 1;
        $roomId = DB::table('rooms')->insertGetId(['venue_id' => $venueId, 'room_code' => 'r902', 'name' => 'Photo Test Room', 'room_type' => 'event']);
        try {
            $this->actingAsStaff();
            $res = $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => 'r902',
                'files' => [$this->pngUpload('a.png'), $this->pngUpload('b.png')],
            ])->assertOk()->assertJson(['ok' => true, 'warning' => null]);
            $photos = $res->json('photos');
            $this->assertCount(2, $photos);
            $this->assertStringStartsWith('../assets/img/venues/rooms/r902/photos/p_', $photos[0]['url']);
            $this->assertFileExists(public_path('assets/img/venues/rooms/r902/photos/' . $photos[0]['file']));
            $this->assertSame(2, DB::table('room_media')->where('room_id', $roomId)->where('media_type', 'photo')->count());

            $res = $this->postForm('admin.room-photos-api', ['action' => 'reorder', 'room_id' => 'r902', 'order' => [$photos[1]['file'], $photos[0]['file']]])
                ->assertOk();
            $this->assertSame($photos[1]['file'], $res->json('photos.0.file'));

            $this->postForm('admin.room-photos-api', ['action' => 'delete', 'room_id' => 'r902', 'file' => $photos[0]['file']])
                ->assertOk()->assertJsonCount(1, 'photos');
            $this->assertFileDoesNotExist(public_path('assets/img/venues/rooms/r902/photos/' . $photos[0]['file']));

            $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload_pano', 'room_id' => 'r902', 'file' => $this->pngUpload('pano.png'),
            ])->assertOk()->assertJson(['pano' => '../assets/img/venues/rooms/r902/pano.png']);
            $this->assertFileExists(public_path('assets/img/venues/rooms/r902/pano.png'));

            $this->postForm('admin.room-photos-api', ['action' => 'delete_pano', 'room_id' => 'r902'])->assertOk()->assertJson(['pano' => null]);
            $this->assertFileDoesNotExist(public_path('assets/img/venues/rooms/r902/pano.png'));

            /* not an image */
            $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => 'r902', 'files' => [UploadedFile::fake()->createWithContent('x.pdf', '%PDF-1.4 not an image')],
            ])->assertStatus(400)->assertJson(['error' => 'upload_failed', 'message' => 'Only JPG, PNG, and WEBP photos are allowed.']);
        } finally {
            $this->dropRoom('r902');
        }
        $this->assertDirectoryDoesNotExist(public_path('assets/img/venues/rooms/r902'));
    }

    public function test_venue_cover_upload_and_delete(): void
    {
        $venueId = $this->makeVenue('Photo Test Venue');
        try {
            $this->actingAsStaff();
            $this->withCsrf()->post(route('admin.venue-photo-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'venue_id' => (string) $venueId, 'file' => $this->pngUpload('c.png'),
            ])->assertOk()->assertJson(['ok' => true, 'cover' => '../assets/img/venues/covers/' . $venueId . '.png']);
            $this->assertFileExists(public_path('assets/img/venues/covers/' . $venueId . '.png'));
            $this->assertSame('../assets/img/venues/covers/' . $venueId . '.png', DB::table('venues')->where('id', $venueId)->value('cover_photo'));

            $this->postForm('admin.venue-photo-api', ['action' => 'delete', 'venue_id' => (string) $venueId])
                ->assertOk()->assertJson(['ok' => true, 'cover' => null]);
            $this->assertFileDoesNotExist(public_path('assets/img/venues/covers/' . $venueId . '.png'));
            $this->assertNull(DB::table('venues')->where('id', $venueId)->value('cover_photo'));

            $this->postForm('admin.venue-photo-api', ['action' => 'upload', 'venue_id' => (string) $venueId])
                ->assertStatus(400)->assertJson(['error' => 'no_file', 'message' => 'Choose a photo.']);
        } finally {
            $this->dropVenue($venueId);
        }
    }
}
