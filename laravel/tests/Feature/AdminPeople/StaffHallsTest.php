<?php

namespace Tests\Feature\AdminPeople;

use App\Queries\StaffHallQueries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Staff per hall (Aron 2026-11-05, migration 18): one or more halls of their own office,
   the hostel as one unit, every hall keeps a staff member; booking lists and booking
   news follow it. Halls: ids 1-4 at Bahay Alumni (1), 5-8 at USeP Venues (2). */
class StaffHallsTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }
    use SeedsBookings;

    private const BAHAY = 1, VENUES = 2, HOSTEL = 3;

    private int $before = 0;
    private int $lastBooking = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = (int) DB::table('notifications')->max('id');
        $this->lastBooking = (int) DB::table('bookings')->max('id');
    }

    protected function tearDown(): void
    {
        DB::table('notifications')->where('id', '>', $this->before)->delete();
        DB::table('bookings')->where('id', '>', $this->lastBooking)->delete();   // other tests count a room's upcoming bookings
        $this->removeAccounts();
    }

    private function staffAt(int $venue): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        return $acct;
    }

    private function halls(object $staff): array
    {
        return DB::table('staff_halls')->where('user_id', $staff->id)->orderBy('room_id')->pluck('room_id')->map(fn ($v) => (int) $v)->all();
    }

    private function setHalls(object $staff, array $ids)
    {
        return $this->withSession(['_token' => 'tok'])->post(route('admin.staff-save'),
            ['csrf' => 'tok', 'action' => 'set_halls', 'user_id' => $staff->id, 'hall_ids' => json_encode($ids)]);
    }

    private function saveAs(string $email, array $data)
    {
        return $this->actingAsAdmin($email)->withSession(['_token' => 'tok'])->post(route('admin.staff-save'), $data + ['csrf' => 'tok']);
    }

    public function test_the_backfill_gave_every_staff_member_every_hall_of_their_office(): void
    {
        $this->assertSame([1, 2, 3, 4], DB::table('staff_halls')->where('user_id', 7)->orderBy('room_id')->pluck('room_id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, DB::table('staff_halls')->where('user_id', 73)->count());   // the hostel: one unit
    }

    public function test_a_new_or_moved_staff_member_gets_every_hall_of_the_office(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->assertSame([5, 6, 7, 8], $this->halls($staff));
        DB::table('staff')->where('user_id', $staff->id)->update(['venue_id' => self::HOSTEL]);
        $this->assertSame([], $this->halls($staff));
    }

    public function test_the_database_refuses_a_hall_of_another_office(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('A staff member is given only halls of their own office.');
        DB::table('staff_halls')->insert(['user_id' => $staff->id, 'room_id' => 1]);
    }

    /* Own office only; the admin of another office has no say; two staff share a hall. */
    public function test_an_admin_sets_halls_inside_the_office_only(): void
    {
        $a = $this->staffAt(self::VENUES);
        $b = $this->staffAt(self::VENUES);
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->setHalls($a, [5, 6])->assertOk()->assertJson(['ok' => true, 'halls' => [5, 6]]);
        $this->assertSame([5, 6], $this->halls($a));
        $this->setHalls($a, [5, 1])->assertStatus(400)->assertJson(['message' => 'A staff member covers only halls of their own office.']);
        $this->assertSame([5, 6], $this->halls($a));
        $this->assertSame([5, 6, 7, 8], $this->halls($b));

        $this->actingAsAdmin('francishubero@gmail.com');
        $this->setHalls($a, [5])->assertStatus(403);
    }

    public function test_the_hostel_has_no_halls_to_pick(): void
    {
        $this->actingAsAdmin();
        $this->setHalls($this->staffAt(self::HOSTEL), [9])->assertStatus(400)->assertJson(['message' => 'The hostel is one unit: its staff cover the whole hostel.']);
    }

    /* Taking away, suspending and moving the last staff of a hall: each refused, by name. */
    public function test_the_last_staff_of_a_hall_stays(): void
    {
        $a = $this->staffAt(self::VENUES);
        $b = $this->staffAt(self::VENUES);
        DB::table('staff_halls')->where('user_id', $b->id)->where('room_id', 5)->delete();
        $message = 'Assign another staff member to USeP Gymnasium first.';

        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->setHalls($a, [6, 7, 8])->assertStatus(409)->assertJson(['error' => 'last_staff', 'message' => $message]);
        $this->assertSame([5, 6, 7, 8], $this->halls($a));
        $this->saveAs('cedu.admin@gmail.com', ['action' => 'set_active', 'user_id' => $a->id, 'active' => '0'])
            ->assertStatus(409)->assertJson(['error' => 'last_staff', 'message' => $message]);
        $this->assertSame(1, (int) DB::table('users')->where('id', $a->id)->value('is_active'));
        $this->saveAs('cedu.admin@gmail.com', ['action' => 'set_venue', 'user_id' => $a->id, 'venue_id' => self::HOSTEL])
            ->assertStatus(409)->assertJson(['error' => 'last_staff', 'message' => $message]);
        $this->saveAs('cedu.admin@gmail.com', ['action' => 'update', 'user_id' => $a->id, 'full_name' => 'A', 'email' => $a->email, 'venue_id' => self::HOSTEL])
            ->assertStatus(409)->assertJson(['error' => 'last_staff', 'message' => $message]);
        $venue = DB::table('venues')->where('id', self::HOSTEL)->first();
        $this->actingAsAdmin('cedu.admin@gmail.com')->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('admin.venue-save'), ['venue_id' => self::HOSTEL, 'name' => $venue->name, 'description' => (string) $venue->description, 'move_staff' => json_encode([$a->id])])
            ->assertStatus(409)->assertJson(['error' => 'last_staff']);
        $this->assertSame(self::VENUES, (int) DB::table('staff')->where('user_id', $a->id)->value('venue_id'));

        /* a second staff member on the hall frees them */
        DB::table('staff_halls')->insert(['user_id' => $b->id, 'room_id' => 5]);
        $this->setHalls($a, [6])->assertOk();
    }

    /* A suspended colleague does not count as cover; a deleted hall needs nobody. */
    public function test_only_active_staff_and_live_halls_count(): void
    {
        $a = $this->staffAt(self::VENUES);
        $b = $this->staffAt(self::VENUES);
        DB::table('users')->where('id', $b->id)->update(['is_active' => 0]);
        $this->actingAsAdmin('cedu.admin@gmail.com');
        $this->setHalls($a, [5, 6, 7])->assertStatus(409)->assertJson(['message' => 'Assign another staff member to Obrero Function Hall first.']);

        $hall = DB::table('rooms')->insertGetId(['venue_id' => self::VENUES, 'room_code' => 'r950', 'name' => 'Soon Deleted Hall', 'room_type' => 'event']);
        DB::table('staff_halls')->insert(['user_id' => $a->id, 'room_id' => $hall]);
        try {
            $this->assertSame([$hall => 'Soon Deleted Hall'], StaffHallQueries::lastStaffOf($a->id, [5, 6, 7, 8], false));
            DB::update('UPDATE rooms SET deleted_at = NOW(), is_active = 0 WHERE id = ?', [$hall]);
            $this->assertSame([], StaffHallQueries::lastStaffOf($a->id, [5, 6, 7, 8], false));
        } finally {
            DB::table('rooms')->where('id', $hall)->delete();
        }
    }

    /* Booking news goes to that hall's staff and the group's admins, not the rest of the office. */
    public function test_booking_news_goes_to_the_staff_of_that_hall(): void
    {
        $on = $this->staffAt(self::BAHAY);
        $off = $this->staffAt(self::BAHAY);
        DB::table('staff_halls')->where('user_id', $off->id)->where('room_id', 1)->delete();
        [$id] = $this->seedVenueBooking('r1');
        $from = (int) DB::table('notifications')->max('id');
        DB::statement('CALL sp_notify_venue_staff(?, ?, NULL, NULL)', [$id, 'new_request']);
        $to = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.id', '>', $from)->pluck('u.email')->all();

        $this->assertContains($on->email, $to);
        $this->assertContains('francishubero@gmail.com', $to);
        $this->assertNotContains($off->email, $to);
        $this->assertNotContains('admin@gmail.com', $to);
    }

    /* The live "about to expire" reminder follows the hall too. */
    public function test_reminders_follow_the_hall(): void
    {
        $on = $this->staffAt(self::BAHAY);
        $off = $this->staffAt(self::BAHAY);
        DB::table('staff_halls')->where('user_id', $off->id)->where('room_id', 1)->delete();
        [$id, $ref] = $this->seedVenueBooking('r1');
        $day = now()->addHours(10);
        DB::table('venue_booking_details')->where('booking_id', $id)->update(['start_date' => $day->toDateString(), 'end_date' => $day->toDateString()]);
        DB::table('venue_booking_slots')->where('booking_id', $id)->update(['slot_date' => $day->toDateString(), 'start_time' => $day->format('H:i:s'), 'end_time' => '23:59:59']);
        try {
            legacy('notifications');
            $mine = array_column(notif_reminders(DB::connection()->getPdo(), [self::BAHAY], false, $on->id), 'text');
            $other = array_column(notif_reminders(DB::connection()->getPdo(), [self::BAHAY], false, $off->id), 'text');
            $this->assertNotEmpty(array_filter($mine, fn ($t) => str_contains($t, $ref)));
            $this->assertEmpty(array_filter($other, fn ($t) => str_contains($t, $ref)));
        } finally {
            DB::table('bookings')->where('id', $id)->update(['reservation_status' => 'cancelled']);
        }
    }

    /* The list holds a staff member's halls and covers, nothing else of the office: not even
       a booking they made at the counter on a hall that is no longer theirs (DB-DECISIONS 28). */
    public function test_the_list_holds_a_staff_members_halls_only(): void
    {
        $staff = $this->staffAt(self::BAHAY);
        DB::table('staff_halls')->where('user_id', $staff->id)->where('room_id', '<>', 1)->delete();
        [, $hall] = $this->seedVenueBooking('r1');
        [, $other] = $this->seedVenueBooking('r2');
        [$counter, $counterRef] = $this->seedVenueBooking('r3');
        DB::table('booking_timeline')->insert(['booking_id' => $counter, 'action_code' => 'counter_created', 'performed_by_user_id' => $staff->id]);

        $page = $this->signInAs($staff, 'staff')->get(route('admin.booking-requests'))->assertOk();
        $page->assertSee('"id":"' . $hall . '"', false)
             ->assertDontSee('"id":"' . $other . '"', false)
             ->assertDontSee('"id":"' . $counterRef . '"', false)
             ->assertDontSee('Whole office')->assertDontSee('My halls');

        /* a cover going on brings that hall in */
        DB::table('staff_covers')->insert(['user_id' => $staff->id, 'venue_id' => self::BAHAY, 'room_id' => 2, 'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(), 'reason' => 'test', 'approved_at' => now()]);
        $this->signInAs($staff, 'staff')->get(route('admin.booking-requests'))->assertOk()->assertSee('"id":"' . $other . '"', false);
    }

    public function test_hostel_staff_have_the_whole_hostel_and_admins_their_whole_office(): void
    {
        [, $stay] = $this->seedHostelBooking();
        $this->signInAs($this->staffAt(self::HOSTEL), 'staff')->get(route('admin.booking-requests'))->assertOk()->assertSee('"id":"' . $stay . '"', false);

        [, $other] = $this->seedVenueBooking('r6');
        $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.booking-requests'))->assertOk()->assertSee('"id":"' . $other . '"', false);
    }
}
