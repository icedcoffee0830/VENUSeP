<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

class RoomToggleActiveTest extends VenuesTestCase
{
    public function test_guest_gets_the_old_401_json(): void
    {
        $this->post(route('admin.room-toggle-active'), ['room_id' => 'r1'])
            ->assertStatus(401)->assertJson(['ok' => false, 'error' => 'not_logged_in']);
    }

    public function test_get_is_refused_with_405(): void
    {
        $this->actingAsStaff()->get(route('admin.room-toggle-active'))
            ->assertStatus(405)->assertHeader('Allow', 'POST')->assertJson(['error' => 'method']);
    }

    public function test_a_bad_token_gets_the_old_expired_page_json(): void
    {
        $this->actingAsStaff()->withCsrf()->post(route('admin.room-toggle-active'), ['_token' => 'wrong', 'room_id' => 'r1'])
            ->assertStatus(400)->assertJson(['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
    }

    public function test_staff_flip_a_room_off_and_on_again(): void
    {
        $this->actingAsStaff();
        $this->postForm('admin.room-toggle-active', ['room_id' => 'r1'])->assertOk()
            ->assertJson(['ok' => true, 'active' => false, 'message' => 'Alumni Grand Ballroom is hidden from customers now.']);
        $this->assertSame(0, (int) DB::table('rooms')->where('room_code', 'r1')->value('is_active'));

        $this->postForm('admin.room-toggle-active', ['room_id' => 'r1'])->assertOk()
            ->assertJson(['ok' => true, 'active' => true, 'message' => 'Alumni Grand Ballroom is visible to customers again.']);
        $this->assertSame(1, (int) DB::table('rooms')->where('room_code', 'r1')->value('is_active'));
    }

    public function test_bad_and_unknown_rooms(): void
    {
        $this->actingAsStaff();
        $this->postForm('admin.room-toggle-active', ['room_id' => '../x'])->assertStatus(400)->assertJson(['error' => 'bad_room']);
        $this->postForm('admin.room-toggle-active', ['room_id' => 'zz99'])->assertStatus(404)->assertJson(['error' => 'not_found']);
    }
}
