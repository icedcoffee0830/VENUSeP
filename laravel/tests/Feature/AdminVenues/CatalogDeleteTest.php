<?php

namespace Tests\Feature\AdminVenues;

use App\Queries\ReportQueries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/* Deleting a hall or hostel room (Aron 2026-11-05, migration 18): gone for users, a hidden
   row in the database so the records keep its name; refused while a booking is pending,
   still to come or going on; no undo. Every test works on a room it makes itself. */
class CatalogDeleteTest extends VenuesTestCase
{
    private int $venueId = 0;

    protected function tearDown(): void
    {
        if ($this->venueId) {
            foreach (DB::table('rooms')->where('venue_id', $this->venueId)->pluck('room_code') as $code) {
                DB::table('bookings')->whereIn('room_id', DB::table('rooms')->where('room_code', $code)->pluck('id'))->delete();
                $this->dropRoom($code);
            }
            $this->dropVenue($this->venueId);
        }
        parent::tearDown();
    }

    /* A new event room in a new venue: [room id, code]. */
    private function room(string $code, string $name = 'Delete Test Room'): array
    {
        $this->venueId = $this->venueId ?: $this->makeVenue('Delete Test Venue');
        $id = DB::table('rooms')->insertGetId([
            'venue_id' => $this->venueId, 'room_code' => $code, 'name' => $name, 'room_type' => 'event',
        ]);
        DB::table('event_room_details')->insert(['room_id' => $id, 'attendee_capacity' => 10, 'fee_per_day' => 100]);
        return [$id, $code];
    }

    /* A booking on the room, $days from today (negative = past). */
    private function book(int $roomId, string $res, string $pay, int $days): int
    {
        $day = now()->addDays($days)->toDateString();
        $id = DB::table('bookings')->insertGetId([
            'customer_id' => DB::table('customers')->value('id'), 'room_id' => $roomId, 'booking_type' => 'venue',
            'reservation_status' => $res, 'payment_status' => $pay, 'payment_method' => 'gcash',
            'room_price' => 100, 'total_amount' => 100, 'submitted_at' => now()->subDays(30),
        ]);
        DB::table('venue_booking_details')->insert(['booking_id' => $id, 'event_name' => 'Delete test', 'start_date' => $day,
            'end_date' => $day, 'attendee_count' => 5]);
        return $id;
    }

    public function test_guest_gets_401_and_staff_get_403(): void
    {
        $this->post(route('admin.catalog-delete'))->assertStatus(401)->assertJson(['error' => 'not_logged_in']);
        $this->actingAsStaff();
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r1'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden', 'message' => 'Only administrators can delete rooms or venues.']);
    }

    public function test_unconfirmed_unknown_and_bad_requests_are_refused(): void
    {
        $this->actingAsAdmin();
        $this->postForm('admin.catalog-delete', ['entity' => 'room', 'room_id' => 'r1'])
            ->assertStatus(400)->assertJson(['error' => 'confirmation']);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => 'r999'])
            ->assertStatus(404)->assertJson(['error' => 'not_found']);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'thing'])
            ->assertStatus(400)->assertJson(['error' => 'bad_entity']);
        $this->assertNull(DB::table('rooms')->where('room_code', 'r1')->value('deleted_at'));
    }

    /* The offices are fixed (DB-DECISIONS 28): the super admin cannot delete one either, empty or not. */
    public function test_nobody_deletes_an_office(): void
    {
        $this->venueId = $this->makeVenue('Delete Test Venue');
        $this->actingAsAdmin();
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'venue', 'venue_id' => (string) $this->venueId])
            ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'forbidden', 'message' => 'The offices are fixed: an office cannot be deleted.']);
        $this->assertTrue(DB::table('venues')->where('id', $this->venueId)->exists());
        $this->get(route('admin.venue-management'))->assertOk()->assertDontSee('Delete Venue')->assertDontSee('+ Add Venue');
    }

    /* Pending, approved and still to come, a hostel guest checked in: each one blocks the
       delete, and the reply says how many. Past and closed bookings do not. */
    public function test_a_room_with_live_bookings_is_refused_naming_how_many(): void
    {
        [$roomId, $code] = $this->room('r901');
        $this->book($roomId, 'pending', 'locked', 30);
        $upcoming = $this->book($roomId, 'approved', 'await_gcash', 10);
        $this->book($roomId, 'completed', 'confirmed', -20);
        $this->book($roomId, 'rejected', 'locked', 5);
        $this->actingAsAdmin();
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => $code])
            ->assertStatus(409)->assertJson(['ok' => false, 'error' => 'live_bookings', 'count' => 2,
                'message' => 'Delete Test Room has 2 bookings still pending, coming up or going on, so it cannot be deleted. Finish, cancel or reject them first.']);
        $this->assertNull(DB::table('rooms')->where('id', $roomId)->value('deleted_at'));

        /* today's event is going on: still one */
        DB::table('bookings')->where('room_id', $roomId)->where('reservation_status', 'pending')->update(['reservation_status' => 'cancelled']);
        DB::table('venue_booking_details')->where('booking_id', $upcoming)->update(['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => $code])
            ->assertStatus(409)->assertJson(['count' => 1, 'message' => 'Delete Test Room has 1 booking still pending, coming up or going on, so it cannot be deleted. Finish, cancel or reject it first.']);
    }

    /* The database refuses it too, whoever asks. */
    public function test_the_database_refuses_a_delete_with_a_live_booking_and_an_undo(): void
    {
        [$roomId] = $this->room('r902');
        $pending = $this->book($roomId, 'pending', 'locked', 30);
        try {
            DB::update('UPDATE rooms SET deleted_at = NOW(), is_active = 0 WHERE id = ?', [$roomId]);
            $this->fail('a room with a pending booking was deleted');
        } catch (QueryException $e) {
            $this->assertStringContainsString('bookings still to come or going on', $e->getMessage());
        }
        DB::table('bookings')->where('id', $pending)->update(['reservation_status' => 'cancelled']);
        DB::update('UPDATE rooms SET deleted_at = NOW(), is_active = 0 WHERE id = ?', [$roomId]);
        foreach (["deleted_at = NULL", "is_active = 1"] as $undo) {
            try {
                DB::update("UPDATE rooms SET $undo WHERE id = ?", [$roomId]);
                $this->fail("a deleted room was changed back with $undo");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/stays deleted|chk_rooms_deleted_inactive/', $e->getMessage());
            }
        }
    }

    public function test_the_database_refuses_a_new_booking_on_a_deleted_room(): void
    {
        [$roomId] = $this->room('r903');
        DB::update('UPDATE rooms SET deleted_at = NOW(), is_active = 0 WHERE id = ?', [$roomId]);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('This room was deleted, so it cannot be booked.');
        $this->book($roomId, 'pending', 'locked', 30);
    }

    /* Deleted: a hidden row (who, when), gone from every list and page, its name free for a
       new room; the past booking, its details page and the reports still name it. */
    public function test_a_deleted_room_is_gone_for_users_but_its_records_keep_the_name(): void
    {
        [$roomId, $code] = $this->room('r904', 'Soon Gone Hall');
        $past = $this->book($roomId, 'completed', 'confirmed', -20);
        $this->actingAsAdmin();
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => $code])
            ->assertOk()->assertJson(['ok' => true, 'entity' => 'room',
                'message' => 'Soon Gone Hall was deleted. Past bookings and receipts keep its name.']);

        $row = DB::table('rooms')->where('id', $roomId)->first();
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(0, (int) $row->is_active);
        $this->assertSame((int) DB::table('users')->where('email', 'admin@gmail.com')->value('id'), (int) $row->deleted_by_user_id);

        $this->get(route('admin.venue-management'))->assertOk()->assertDontSee('Soon Gone Hall');
        $this->get(route('admin.room-form', ['id' => $code]))->assertOk()->assertDontSee('Soon Gone Hall');
        $this->get('/customer/venues?counter=1')->assertOk()->assertDontSee('Soon Gone Hall');
        $this->postForm('admin.room-toggle-active', ['room_id' => $code])->assertStatus(404);
        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => $code])->assertStatus(404);

        $ref = 'VB-' . DB::table('bookings')->where('id', $past)->value(DB::raw('YEAR(submitted_at)')) . '-' . str_pad((string) $past, 3, '0', STR_PAD_LEFT);
        $this->get(route('admin.booking-request', ['id' => $ref]))->assertOk()->assertSee('Soon Gone Hall');
        $this->assertContains('Soon Gone Hall (deleted)', array_column(ReportQueries::rooms([$this->venueId]), 'name'));

        /* the name is free for a new room of the office */
        $this->room('r905', 'Soon Gone Hall');
        $this->assertSame(2, DB::table('rooms')->where('venue_id', $this->venueId)->where('name', 'Soon Gone Hall')->count());

        $this->actingAsCustomer();
        $this->get('/customer/venues')->assertOk()->assertDontSee('r904');
        $this->get('/customer/room-reservation?room=' . $code)->assertOk()->assertDontSee('Soon Gone Hall');   // the page sends them back to the list
    }

    /* The photos are not records: their rows and files go. */
    public function test_deleting_a_room_removes_its_photo_files(): void
    {
        [$roomId, $code] = $this->room('r906', 'Delete Files Room');
        $roomDir = public_path('assets/img/venues/rooms/' . $code);
        $this->actingAsAdmin();
        $this->withCsrf()->post(route('admin.room-photos-api'), [
            '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => $code, 'files' => [$this->pngUpload('a.png')],
        ])->assertOk();
        $this->assertCount(1, glob($roomDir . '/photos/*.png'));

        $this->postForm('admin.catalog-delete', ['confirm' => 'delete', 'entity' => 'room', 'room_id' => $code])->assertOk();
        $this->assertDirectoryDoesNotExist($roomDir);
        $this->assertSame(0, DB::table('room_media')->where('room_id', $roomId)->count());
        $this->withCsrf()->post(route('admin.room-photos-api'), [
            '_token' => self::TOKEN, 'action' => 'upload', 'room_id' => $code, 'files' => [$this->pngUpload('b.png')],
        ])->assertStatus(404);
    }
}
