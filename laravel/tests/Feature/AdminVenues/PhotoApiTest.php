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

    /* A real PNG padded to $bytes (getimagesize reads only the header). */
    private function bigPng(string $name, int $bytes): UploadedFile
    {
        $file = $this->pngUpload($name);
        $fh = fopen($file->getPathname(), 'r+');
        ftruncate($fh, $bytes);
        fclose($fh);
        clearstatcache();
        return new UploadedFile($file->getPathname(), $name, 'image/png', null, true);
    }

    /* Aron 2026-10-30: every upload is capped at 7 MB, with a clear message. */
    public function test_room_and_venue_photos_over_7_mb_are_refused(): void
    {
        $over = 7 * 1024 * 1024 + 1;
        $msg = 'This file is over 7 MB. Choose a smaller photo or a screenshot.';
        $venueId = $this->makeVenue('Big Photo Venue');
        $roomId = DB::table('rooms')->insertGetId(['venue_id' => 1, 'room_code' => 'r903', 'name' => 'Big Photo Room', 'room_type' => 'event']);
        try {
            $this->actingAsAdmin();
            $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => 'r903', 'files' => [$this->bigPng('big.png', $over)],
            ])->assertStatus(400)->assertJson(['ok' => false, 'error' => 'upload_failed', 'message' => $msg]);
            $this->withCsrf()->post(route('admin.room-photos-api'), [
                '_token' => self::TOKEN, 'action' => 'upload_pano', 'room_id' => 'r903', 'file' => $this->bigPng('pano.png', $over),
            ])->assertStatus(400)->assertJson(['ok' => false, 'message' => $msg]);
            $this->assertSame(0, DB::table('room_media')->where('room_id', $roomId)->count());

            $this->withCsrf()->post(route('admin.venue-photo-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'venue_id' => (string) $venueId, 'file' => $this->bigPng('c.png', $over),
            ])->assertStatus(400)->assertJson(['ok' => false, 'error' => 'upload_failed', 'message' => $msg]);
            $this->assertNull(DB::table('venues')->where('id', $venueId)->value('cover_photo'));

            /* exactly 7 MB is still fine */
            $this->withCsrf()->post(route('admin.venue-photo-api'), [
                '_token' => self::TOKEN, 'action' => 'upload', 'venue_id' => (string) $venueId, 'file' => $this->bigPng('c.png', 7 * 1024 * 1024),
            ])->assertOk()->assertJson(['ok' => true]);
        } finally {
            $this->dropRoom('r903');
            $this->dropVenue($venueId);
        }
    }

    public function test_venue_cover_upload_and_delete(): void
    {
        $venueId = $this->makeVenue('Photo Test Venue');
        try {
            $this->actingAsAdmin();
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
