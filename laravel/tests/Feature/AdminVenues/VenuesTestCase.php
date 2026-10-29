<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/* Shared bits for the venues-area tests. Posts carry `_token` like the pages'
   scripts do (Laravel skips its CSRF check under phpunit; the expired-page
   reply is covered by SkeletonTest). Anything a test creates (rooms, venues,
   uploaded files under public/assets) is removed again. */
abstract class VenuesTestCase extends TestCase
{
    protected const TOKEN = 'venues-test-token';

    protected function withCsrf(): static
    {
        return $this->withSession(['_token' => self::TOKEN]);
    }

    /* POST as the current session, with the token. */
    protected function postForm(string $route, array $data = [])
    {
        return $this->withCsrf()->post(route($route), $data + ['_token' => self::TOKEN]);
    }

    /* Delete a room this test created, with its config rows and photo folder. */
    protected function dropRoom(string $code): void
    {
        $id = DB::table('rooms')->where('room_code', $code)->value('id');
        if ($id) {
            foreach (['maintenance_windows', 'room_media', 'event_room_details', 'hostel_room_details', 'hostel_beds'] as $t) {
                DB::table($t)->where('room_id', $id)->delete();
            }
            DB::table('rooms')->where('id', $id)->delete();
        }
        if (preg_match('/^[a-z]\d+$/', $code) && !in_array($code, ['r1', 'r2', 'r5', 'r7', 'h1', 'h2', 'h3'], true)) {
            File::deleteDirectory(public_path('assets/img/venues/rooms/' . $code));
        }
    }

    /* Delete a venue this test created, and its cover file. */
    protected function dropVenue(int $id): void
    {
        if ($id <= 3) {
            return;   // never the seeded venues
        }
        DB::table('staff')->where('venue_id', $id)->update(['venue_id' => 1]);
        DB::table('venues')->where('id', $id)->delete();
        foreach (['jpg', 'png', 'webp'] as $ext) {
            File::delete(public_path('assets/img/venues/covers/' . $id . '.' . $ext));
        }
    }

    protected function makeVenue(string $name, string $type = 'event'): int
    {
        return (int) DB::table('venues')->insertGetId([
            'venue_code' => strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name)),
            'name' => $name, 'venue_type' => $type, 'description' => 'test',
        ]);
    }

    /* A real 1x1 PNG (UploadedFile::fake()->image() needs GD, which this PHP lacks). */
    protected function pngUpload(string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vnp');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        return new UploadedFile($path, $name, 'image/png', null, true);
    }
}
