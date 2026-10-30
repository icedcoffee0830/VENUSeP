<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

/* DB-DECISIONS #24 — staff handle only their own venue. staff@gmail.com handles
   Bahay Alumni (venue 1: r1–r4); USeP Venues (venue 2: r5–r8) and the hostel
   (venue 3) belong to other staff. The catalog writes refuse another venue's
   rooms and venue the way booking-action refuses its bookings; admins pass. */
class StaffVenueScopeTest extends VenuesTestCase
{
    private function roomName(string $code): string
    {
        return (string) DB::table('rooms')->where('room_code', $code)->value('name');
    }

    public function test_staff_cannot_edit_create_or_move_rooms_outside_their_venue(): void
    {
        $this->actingAsStaff();
        $r5 = $this->roomName('r5');
        $this->postForm('admin.room-save', ['room_id' => 'r5', 'name' => 'Scope Test Rename', 'venue' => 'USeP Venues', 'room_type' => 'event'])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden']);
        $this->assertSame($r5, $this->roomName('r5'));

        $this->postForm('admin.room-save', ['room_id' => 'h1', 'name' => 'Scope Test Dorm', 'venue' => 'USeP Hostel', 'room_type' => 'hostel',
            'beds' => '6', 'rate' => '1', 'cr_type' => 'communal', 'check_in_time' => '14:00', 'check_out_time' => '11:00'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->assertNotSame('Scope Test Dorm', $this->roomName('h1'));

        $before = DB::table('rooms')->count();
        $this->postForm('admin.room-save', ['room_id' => '', 'name' => 'Scope Test New', 'venue' => 'USeP Venues', 'room_type' => 'event',
            'capacity' => '10', 'fee' => '100'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->assertSame($before, DB::table('rooms')->count());

        /* their own room cannot be moved out of their venue either */
        $r2 = DB::table('rooms')->where('room_code', 'r2')->first();
        $this->postForm('admin.room-save', ['room_id' => 'r2', 'name' => $r2->name, 'venue' => 'USeP Venues', 'room_type' => 'event'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->assertSame((int) $r2->venue_id, (int) DB::table('rooms')->where('room_code', 'r2')->value('venue_id'));

        /* their own venue's room still saves */
        $r1 = $this->roomName('r1');
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => $r1, 'venue' => 'Bahay Alumni', 'room_type' => 'event'])
            ->assertOk()->assertJson(['ok' => true]);
    }

    public function test_staff_cannot_disable_another_venues_room_but_an_admin_can(): void
    {
        $active = (int) DB::table('rooms')->where('room_code', 'r5')->value('is_active');
        $this->actingAsStaff();
        $this->postForm('admin.room-toggle-active', ['room_id' => 'r5'])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden']);
        $this->assertSame($active, (int) DB::table('rooms')->where('room_code', 'r5')->value('is_active'));

        $this->actingAsAdmin();
        try {
            $this->postForm('admin.room-toggle-active', ['room_id' => 'r5'])->assertOk()->assertJson(['ok' => true]);
            $this->assertSame(1 - $active, (int) DB::table('rooms')->where('room_code', 'r5')->value('is_active'));
        } finally {
            DB::table('rooms')->where('room_code', 'r5')->update(['is_active' => $active]);
        }
    }

    public function test_staff_cannot_change_another_venues_photos_but_can_list_them(): void
    {
        $r5 = (int) DB::table('rooms')->where('room_code', 'r5')->value('id');
        $media = DB::table('room_media')->where('room_id', $r5)->count();
        $this->actingAsStaff();
        $this->withCsrf()->post(route('admin.room-photos-api'), [
            '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => 'r5', 'files' => [$this->pngUpload('a.png')],
        ])->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->postForm('admin.room-photos-api', ['action' => 'delete_pano', 'room_id' => 'r5'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->assertSame($media, DB::table('room_media')->where('room_id', $r5)->count());

        $this->get(route('admin.room-photos-api', ['action' => 'list', 'room_id' => 'r5']))->assertOk()->assertJson(['ok' => true]);

        $cover = DB::table('venues')->where('id', 2)->value('cover_photo');
        $this->postForm('admin.venue-photo-api', ['action' => 'delete', 'venue_id' => '2'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->assertSame($cover, DB::table('venues')->where('id', 2)->value('cover_photo'));
    }

    /* These JSON routes have no page guard, so the account is re-read here: a
       suspended account's open session is refused, and an admin demoted to
       staff is held to their venue on the next request. */
    public function test_a_suspended_or_demoted_account_is_re_read_from_the_database(): void
    {
        $active = (int) DB::table('rooms')->where('room_code', 'r1')->value('is_active');
        DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 0]);
        try {
            $this->actingAsStaff();
            $this->postForm('admin.room-toggle-active', ['room_id' => 'r1'])
                ->assertStatus(401)->assertJson(['ok' => false, 'error' => 'not_logged_in']);
            $this->assertSame($active, (int) DB::table('rooms')->where('room_code', 'r1')->value('is_active'));
        } finally {
            DB::table('users')->where('email', 'staff@gmail.com')->update(['is_active' => 1]);
        }

        /* the session still says "admin", the database says "staff" */
        $id = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        $this->withSession(['user_id' => (int) $id, 'account_type' => 'admin', 'tfa_passed' => true]);
        $this->postForm('admin.room-toggle-active', ['room_id' => 'r5'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
    }

    public function test_staff_cannot_rename_another_venue(): void
    {
        $this->actingAsStaff();
        $name = DB::table('venues')->where('id', 2)->value('name');
        $this->postForm('admin.venue-save', ['venue_id' => '2', 'name' => 'Scope Test Venue', 'description' => 'x'])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden']);
        $this->assertSame($name, DB::table('venues')->where('id', 2)->value('name'));

        /* their own venue still saves */
        $own = DB::table('venues')->where('id', 1)->first();
        $this->postForm('admin.venue-save', ['venue_id' => '1', 'name' => $own->name, 'description' => (string) $own->description])
            ->assertOk()->assertJson(['ok' => true]);
    }
}
