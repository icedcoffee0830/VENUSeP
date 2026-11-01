<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;

class RoomToggleActiveTest extends VenuesTestCase
{
    use SeedsBookings;

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

    /* r2 has no upcoming bookings (the booking tests seed theirs on r1 / r5) */
    public function test_staff_flip_a_room_off_and_on_again(): void
    {
        $this->actingAsStaff();
        $this->postForm('admin.room-toggle-active', ['room_id' => 'r2'])->assertOk()
            ->assertJson(['ok' => true, 'active' => false, 'notes' => [], 'message' => 'Heritage Function Room is hidden from customers now.']);
        $this->assertSame(0, (int) DB::table('rooms')->where('room_code', 'r2')->value('is_active'));

        $this->postForm('admin.room-toggle-active', ['room_id' => 'r2'])->assertOk()
            ->assertJson(['ok' => true, 'active' => true, 'message' => 'Heritage Function Room is visible to customers again.']);
        $this->assertSame(1, (int) DB::table('rooms')->where('room_code', 'r2')->value('is_active'));
    }

    /* Aron 2026-10-30: disabling a room keeps its upcoming bookings, and the
       reply names them (like room-save does for a lowered capacity). */
    public function test_disabling_a_room_names_its_upcoming_bookings_and_keeps_them(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->actingAsStaff();
        try {
            $res = $this->postForm('admin.room-toggle-active', ['room_id' => 'r1'])->assertOk()
                ->assertJson(['ok' => true, 'active' => false]);
            $this->assertCount(1, $res->json('notes'));
            $this->assertStringStartsWith('These upcoming bookings are kept as booked: ', $res->json('notes.0'));
            $this->assertStringContainsString($ref, $res->json('notes.0'));
            $this->assertStringStartsWith("Alumni Grand Ballroom is hidden from customers now.\n\nThese upcoming bookings are kept as booked: ", $res->json('message'));
            $this->assertSame('approved', $this->booking($id)->reservation_status);

            /* turning it back on has nothing to warn about */
            $this->postForm('admin.room-toggle-active', ['room_id' => 'r1'])->assertOk()
                ->assertJson(['ok' => true, 'active' => true, 'notes' => [], 'message' => 'Alumni Grand Ballroom is visible to customers again.']);
        } finally {
            DB::table('rooms')->where('room_code', 'r1')->update(['is_active' => 1]);
        }
    }

    public function test_bad_and_unknown_rooms(): void
    {
        $this->actingAsStaff();
        $this->postForm('admin.room-toggle-active', ['room_id' => '../x'])->assertStatus(400)->assertJson(['error' => 'bad_room']);
        $this->postForm('admin.room-toggle-active', ['room_id' => 'zz99'])->assertStatus(404)->assertJson(['error' => 'not_found']);
    }
}
