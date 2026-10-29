<?php

namespace Tests\Feature\AdminVenues;

use Illuminate\Support\Facades\DB;

class RoomSaveTest extends VenuesTestCase
{
    public function test_guest_401_and_get_405(): void
    {
        $this->post(route('admin.room-save'))->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->actingAsStaff()->get(route('admin.room-save'))->assertStatus(405);
    }

    public function test_validation_failures_answer_as_before(): void
    {
        $this->actingAsAdmin();
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => '', 'venue' => 'Bahay Alumni', 'room_type' => 'event'])
            ->assertStatus(400)->assertJson(['error' => 'bad_name', 'message' => 'Enter a room name (up to 150 characters).']);
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => 'X', 'venue' => '', 'room_type' => 'event'])
            ->assertStatus(400)->assertJson(['error' => 'bad_venue', 'message' => 'Choose a location.']);
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => 'X', 'venue' => 'Bahay Alumni', 'room_type' => 'event', 'capacity' => '0'])
            ->assertStatus(400)->assertJson(['error' => 'bad_capacity']);
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => 'X', 'venue' => 'Nowhere', 'room_type' => 'event'])
            ->assertStatus(400)->assertJson(['error' => 'bad_venue', 'message' => 'Unknown location.']);
        $this->postForm('admin.room-save', ['room_id' => 'h1', 'name' => 'X', 'venue' => 'USeP Hostel', 'room_type' => 'event'])
            ->assertStatus(409)->assertJson(['error' => 'wrong_type']);
        $this->postForm('admin.room-save', ['room_id' => '', 'name' => 'X', 'venue' => 'USeP Hostel', 'room_type' => 'event'])
            ->assertStatus(409)->assertJson(['error' => 'type_mismatch', 'message' => 'Event rooms can only be added to an event venue.']);
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => 'X', 'venue' => 'Bahay Alumni', 'room_type' => 'event',
            'maintenance' => json_encode(['on' => true, 'tier' => 'hard', 'from' => '2030-01-01', 'until' => null, 'reason' => ''])])
            ->assertStatus(400)->assertJson(['error' => 'bad_maintenance']);
    }

    public function test_create_then_edit_an_event_room(): void
    {
        $this->actingAsStaff();
        $next = 'r' . ((int) DB::selectOne("SELECT COALESCE(MAX(CAST(SUBSTRING(room_code, 2) AS UNSIGNED)), 0) + 1 AS n FROM rooms WHERE room_code REGEXP '^r[0-9]+$'")->n);
        try {
            $this->postForm('admin.room-save', [
                'room_id' => '', 'name' => 'Port Test Room', 'venue' => 'Bahay Alumni', 'room_type' => 'event',
                'description' => 'Desc', 'capacity' => '40', 'fee' => '1500', 'amenities' => json_encode(['bogus']),
                'best_for' => 'Seminars', 'catering' => '', 'accessibility' => 'Ramp',
            ])->assertOk()->assertJson(['ok' => true, 'created' => true, 'room_id' => $next,
                'message' => 'Room created as ' . $next . '. You can add its photos now.']);

            $room = DB::table('rooms')->where('room_code', $next)->first();
            $this->assertSame('Desc', $room->description);
            $this->assertSame('[]', $room->amenities);   // unknown keys dropped
            $this->assertSame('Seminars', $room->best_for);
            $this->assertNull($room->catering);
            $details = DB::table('event_room_details')->where('room_id', $room->id)->first();
            $this->assertSame(40, (int) $details->attendee_capacity);
            $this->assertEquals(1500, (float) $details->fee_per_day);

            $this->postForm('admin.room-save', [
                'room_id' => $next, 'name' => 'Port Test Room B', 'venue' => 'Bahay Alumni', 'room_type' => 'event',
                'capacity' => '30', 'fee' => '2000',
                'maintenance' => json_encode(['on' => true, 'tier' => 'medium', 'from' => '2031-01-01', 'until' => '2031-01-05', 'reason' => 'Paint']),
            ])->assertOk()->assertJson(['ok' => true, 'name' => 'Port Test Room B',
                'notes' => ['The new rate applies to new bookings; existing bookings keep the price they were made at.']]);
            $this->assertSame(30, (int) DB::table('event_room_details')->where('room_id', $room->id)->value('attendee_capacity'));
            $this->assertSame(0, (int) DB::table('maintenance_windows')->where('room_id', $room->id)->value('blocks_booking'));
        } finally {
            $this->dropRoom($next);
        }
    }

    public function test_a_new_hostel_room_gets_its_beds_and_the_count_can_change(): void
    {
        $this->actingAsAdmin();
        $res = $this->postForm('admin.room-save', [
            'room_id' => '', 'name' => 'Port Test Dorm', 'venue' => 'USeP Hostel', 'room_type' => 'hostel',
            'beds' => '4', 'rate' => '350', 'cr_type' => 'private', 'check_in_time' => '14:00', 'check_out_time' => '11:00',
        ])->assertOk()->assertJson(['ok' => true, 'created' => true]);
        $code = $res->json('room_id');
        try {
            $id = DB::table('rooms')->where('room_code', $code)->value('id');
            $this->assertSame(4, DB::table('hostel_beds')->where('room_id', $id)->where('is_active', 1)->count());
            $this->assertSame('14:00:00', DB::table('hostel_room_details')->where('room_id', $id)->value('check_in_time'));

            $this->postForm('admin.room-save', [
                'room_id' => $code, 'name' => 'Port Test Dorm', 'venue' => 'USeP Hostel', 'room_type' => 'hostel',
                'beds' => '2', 'rate' => '350', 'cr_type' => 'communal', 'check_in_time' => '14:00', 'check_out_time' => '11:00',
            ])->assertOk()->assertJson(['ok' => true, 'message' => 'Room saved.']);
            $this->assertSame(2, DB::table('hostel_beds')->where('room_id', $id)->where('is_active', 1)->count());
            $this->assertSame(4, DB::table('hostel_beds')->where('room_id', $id)->count());   // retired, never deleted
        } finally {
            $this->dropRoom($code);
        }
    }

    /* A cleared or non-numeric rate used to be cast to ₱0 and saved — the room
       became free. Counts must be whole numbers; a clock time must be real. */
    public function test_blank_or_non_numeric_rates_and_counts_are_refused_not_saved_as_zero(): void
    {
        $this->actingAsAdmin();
        $r1 = DB::table('rooms as r')->join('event_room_details as e', 'e.room_id', '=', 'r.id')
            ->where('r.room_code', 'r1')->select('r.name', 'e.fee_per_day', 'e.attendee_capacity')->first();
        $event = ['room_id' => 'r1', 'name' => $r1->name, 'venue' => 'Bahay Alumni', 'room_type' => 'event'];
        foreach (['', 'abc', '-5'] as $bad) {
            $this->postForm('admin.room-save', $event + ['fee' => $bad, 'capacity' => '10'])
                ->assertStatus(400)->assertJson(['ok' => false, 'error' => 'bad_fee']);
        }
        foreach (['', 'ten', '12.5', '0'] as $bad) {
            $this->postForm('admin.room-save', $event + ['fee' => '100', 'capacity' => $bad])
                ->assertStatus(400)->assertJson(['ok' => false, 'error' => 'bad_capacity']);
        }
        $after = DB::table('event_room_details')->where('room_id', DB::table('rooms')->where('room_code', 'r1')->value('id'))->first();
        $this->assertEquals((float) $r1->fee_per_day, (float) $after->fee_per_day);
        $this->assertSame((int) $r1->attendee_capacity, (int) $after->attendee_capacity);

        $h1 = DB::table('rooms as r')->join('hostel_room_details as h', 'h.room_id', '=', 'r.id')
            ->where('r.room_code', 'h1')->select('r.name', 'h.rate_per_head_per_night')->first();
        $hostel = ['room_id' => 'h1', 'name' => $h1->name, 'venue' => 'USeP Hostel', 'room_type' => 'hostel',
            'beds' => '6', 'rate' => '350', 'cr_type' => 'communal', 'check_in_time' => '14:00', 'check_out_time' => '11:00'];
        $this->postForm('admin.room-save', ['rate' => ''] + $hostel)->assertStatus(400)->assertJson(['error' => 'bad_rate']);
        $this->postForm('admin.room-save', ['beds' => 'six'] + $hostel)->assertStatus(400)->assertJson(['error' => 'bad_beds']);
        $this->postForm('admin.room-save', ['check_in_time' => '25:99'] + $hostel)->assertStatus(400)->assertJson(['error' => 'bad_times']);
        $this->assertEquals((float) $h1->rate_per_head_per_night,
            (float) DB::table('hostel_room_details')->where('room_id', DB::table('rooms')->where('room_code', 'h1')->value('id'))->value('rate_per_head_per_night'));
    }
}
