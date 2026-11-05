<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Covers and moves between groups (Aron 2026-11-05, migration 18). Offices: 1 Bahay Alumni
   (group bahay_alumni), 2 USeP Venues and 3 USeP Hostel (group cedu). Halls: r1-r4 = ids
   1-4 at Bahay Alumni, r5-r8 = ids 5-8 at USeP Venues. Staff are made per test. */
class StaffCoverTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }
    use SeedsBookings;

    private const BAHAY = 1, VENUES = 2, HOSTEL = 3;
    private const SUPER = 'admin@gmail.com', BAHAY_NA = 'francishubero@gmail.com', CEDU_NA = 'cedu.admin@gmail.com';

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
        DB::table('users')->where('email', self::BAHAY_NA)->update(['is_active' => 1]);
        $this->removeAccounts();
    }

    private function staffAt(int $venue): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue, 'full_name' => 'Staff ' . substr($acct->email, 7, 6)]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        return $acct;
    }

    private function as(string $email): static
    {
        return $this->actingAsAdmin($email)->withSession(['_token' => 'tok']);
    }

    private function save(array $data)
    {
        return $this->withHeader('X-CSRF-TOKEN', 'tok')->post(route('admin.staff-cover-save'), $data);
    }

    /* A cover request from tomorrow for two days. */
    private function form(object $staff, int $venue, array $over = []): array
    {
        return $over + ['action' => 'cover_create', 'user_id' => $staff->id, 'venue_id' => $venue, 'room_id' => '',
            'from' => now()->addDay()->format('Y-m-d\TH:i'), 'to' => now()->addDays(3)->format('Y-m-d\TH:i'), 'reason' => 'Shift change'];
    }

    /* A cover already going on, set by the CEDU admin. */
    private function coverNow(object $staff, int $venue, ?int $room = null): int
    {
        return (int) DB::table('staff_covers')->insertGetId(['user_id' => $staff->id, 'venue_id' => $venue, 'room_id' => $room,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'reason' => 'test', 'approved_at' => now()]);
    }

    private function offices(object $staff): array
    {
        $this->signInAs($staff, 'staff')->get(route('admin.dashboard'))->assertOk();
        legacy('auth');
        return staff_me()['offices'];
    }

    private function told(string $kind): array
    {
        $to = DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')
            ->where('n.id', '>', $this->before)->where('n.kind', $kind)->pluck('u.email')->all();
        sort($to);
        return $to;
    }

    private function bookingNews(int $bookingId): array
    {
        $from = (int) DB::table('notifications')->max('id');
        DB::statement('CALL sp_notify_venue_staff(?, ?, NULL, NULL)', [$bookingId, 'new_request']);
        return DB::table('notifications as n')->join('users as u', 'u.id', '=', 'n.user_id')->where('n.id', '>', $from)->pluck('u.email')->all();
    }

    public function test_only_admins_reach_it(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->withSession(['_token' => 'tok']);
        $this->save($this->form($staff, self::HOSTEL))->assertStatus(401);
        $this->signInAs($staff, 'staff')->withSession(['_token' => 'tok']);
        $this->save($this->form($staff, self::HOSTEL))->assertStatus(403);
        $this->assertSame(0, DB::table('staff_covers')->where('user_id', $staff->id)->count());
    }

    /* Inside one group: one admin, active at once, for the whole office. */
    public function test_a_cover_inside_a_group_counts_at_once_and_opens_the_office(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $now = now()->format('Y-m-d\TH:i');
        $this->as(self::CEDU_NA);
        $this->save($this->form($staff, self::HOSTEL, ['from' => $now]))->assertOk()->assertJson(['ok' => true, 'waiting' => false, 'message' => 'Cover set.']);
        $this->assertSame([self::VENUES, self::HOSTEL], $this->offices($staff));

        [$id, $ref] = $this->seedHostelBooking();
        $this->get(route('admin.booking-request', ['id' => $ref]))->assertOk()->assertSee('"' . $ref . '"', false);
        $this->assertContains($staff->email, $this->bookingNews($id));
    }

    /* A hall cover: that hall's news only, though the whole office's bookings open. */
    public function test_a_hall_cover_brings_that_halls_news_only(): void
    {
        $staff = $this->staffAt(self::HOSTEL);
        $this->coverNow($staff, self::VENUES, 5);
        [$r5] = $this->seedVenueBooking('r5');
        DB::table('rooms')->where('id', 6)->update(['is_active' => 1]);
        [$r6] = $this->seedVenueBooking('r6');
        DB::table('rooms')->where('id', 6)->update(['is_active' => 0]);
        $this->assertContains($staff->email, $this->bookingNews($r5));
        $this->assertNotContains($staff->email, $this->bookingNews($r6));
        $this->assertSame([self::VENUES, self::HOSTEL], $this->offices($staff));
    }

    /* It ends by itself: nothing runs, the clock alone closes it. */
    public function test_a_cover_ends_by_itself_at_its_time(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $id = $this->coverNow($staff, self::HOSTEL);
        [$booking] = $this->seedHostelBooking();
        $this->assertSame([self::VENUES, self::HOSTEL], $this->offices($staff));

        DB::table('staff_covers')->where('id', $id)->update(['ends_at' => now()->subMinute()]);
        $this->assertSame([self::VENUES], $this->offices($staff));
        $this->assertNotContains($staff->email, $this->bookingNews($booking));
        $this->as(self::CEDU_NA)->get(route('admin.staff'))->assertOk()->assertSee('data-state="finished"', false);
    }

    public function test_a_cover_of_more_than_7_days_is_refused(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $from = now()->addDay();
        $this->as(self::CEDU_NA);
        $this->save($this->form($staff, self::HOSTEL, ['from' => $from->format('Y-m-d\TH:i'), 'to' => $from->copy()->addDays(7)->addMinute()->format('Y-m-d\TH:i')]))
            ->assertStatus(400)->assertJson(['field' => 'to', 'message' => 'A cover lasts 7 days at most.']);
        $this->save($this->form($staff, self::HOSTEL, ['from' => $from->format('Y-m-d\TH:i'), 'to' => $from->copy()->addDays(7)->format('Y-m-d\TH:i')]))->assertOk();

        $this->expectException(QueryException::class);   // the database says the same
        DB::table('staff_covers')->insert(['user_id' => $staff->id, 'venue_id' => self::HOSTEL, 'starts_at' => now(), 'ends_at' => now()->addDays(8), 'reason' => 'x']);
    }

    public function test_bad_forms_are_refused(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->as(self::CEDU_NA);
        $this->save($this->form($staff, self::HOSTEL, ['reason' => ' ']))->assertStatus(400)->assertJson(['field' => 'reason']);
        $this->save($this->form($staff, self::HOSTEL, ['from' => '2030-02-30T08:00']))->assertStatus(400)->assertJson(['field' => 'from']);
        $this->save($this->form($staff, self::HOSTEL, ['to' => now()->subDay()->format('Y-m-d\TH:i')]))->assertStatus(400)->assertJson(['field' => 'to']);
        $this->save($this->form($staff, self::HOSTEL, ['room_id' => 1]))->assertStatus(400)->assertJson(['field' => 'room_id']);   // the hostel has no halls
        $this->save($this->form($staff, self::VENUES, ['room_id' => 1]))->assertStatus(400)->assertJson(['field' => 'room_id']);   // another office's hall
        $this->save(['action' => 'nothing'])->assertStatus(400)->assertJson(['error' => 'bad_action']);
        $other = $this->staffAt(self::BAHAY);
        $this->save($this->form($other, self::HOSTEL))->assertStatus(403);   // not this admin's staff
        $this->assertSame(0, DB::table('staff_covers')->whereIn('user_id', [$staff->id, $other->id])->count());
    }

    /* Across groups both admins: waiting, nothing opens, each side told; then approved. */
    public function test_a_cover_across_groups_needs_the_destinations_admin(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->as(self::CEDU_NA);
        $id = $this->save($this->form($staff, self::BAHAY, ['room_id' => 1, 'from' => now()->addMinutes(2)->format('Y-m-d\TH:i')]))->assertOk()
            ->assertJson(['waiting' => true, 'message' => 'Waiting for approval from Bahay Alumni.'])->json('id');
        $this->assertSame([self::BAHAY_NA], $this->told('cover_request'));
        $this->get(route('admin.staff'))->assertOk()->assertSee('data-state="waiting"', false)->assertSee('Waiting for approval');

        /* the admin who asked cannot answer it */
        $this->save(['action' => 'cover_approve', 'id' => $id])->assertStatus(403);
        $this->as(self::BAHAY_NA)->save(['action' => 'cover_approve', 'id' => $id])->assertOk()->assertJson(['message' => 'Cover approved.']);
        $this->assertSame([self::CEDU_NA], $this->told('cover_approved'));

        DB::table('staff_covers')->where('id', $id)->update(['starts_at' => now()->subMinute()]);
        $this->assertSame([self::BAHAY, self::VENUES], $this->offices($staff));
        $this->as(self::BAHAY_NA)->save(['action' => 'cover_approve', 'id' => $id])->assertStatus(409);   // answered already
    }

    public function test_a_waiting_cover_opens_nothing(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $id = $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY))->assertOk()->json('id');
        DB::table('staff_covers')->where('id', $id)->update(['starts_at' => now()->subMinute()]);
        $this->assertSame([self::VENUES], $this->offices($staff));
    }

    public function test_a_cover_across_groups_cannot_start_before_it_is_answered(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY, ['from' => now()->subMinute()->format('Y-m-d\TH:i')]))
            ->assertStatus(400)->assertJson(['field' => 'from']);
    }

    public function test_a_rejection_needs_a_reason_and_tells_the_other_side(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $id = $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY))->assertOk()->json('id');
        $this->as(self::BAHAY_NA);
        $this->save(['action' => 'cover_reject', 'id' => $id, 'reason' => ''])->assertStatus(400)->assertJson(['field' => 'reason']);
        $this->save(['action' => 'cover_reject', 'id' => $id, 'reason' => 'Short of people'])->assertOk()->assertJson(['message' => 'Cover rejected.']);
        $this->assertSame([self::CEDU_NA], $this->told('cover_rejected'));
        $row = DB::table('staff_covers')->where('id', $id)->first();
        $this->assertSame('Short of people', $row->reject_reason);
        $this->assertNull($row->approved_at);
        $this->save(['action' => 'cover_end', 'id' => $id])->assertStatus(409);
    }

    /* Unanswered at its start time: expired, and no answer can bring it back. */
    public function test_an_unanswered_cover_expires_at_its_start_time(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $id = $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY))->assertOk()->json('id');
        DB::table('staff_covers')->where('id', $id)->update(['starts_at' => now()->subMinute()]);
        $this->as(self::BAHAY_NA);
        $this->save(['action' => 'cover_approve', 'id' => $id])->assertStatus(409)->assertJson(['error' => 'closed']);
        $this->save(['action' => 'cover_reject', 'id' => $id, 'reason' => 'Late'])->assertStatus(409);
        $this->get(route('admin.staff'))->assertOk()->assertSee('data-state="expired"', false);
        $this->as(self::SUPER)->get(route('admin.activity'))->assertOk()->assertSee('expired unanswered');
    }

    /* Either side's admin ends it early, with no approval. */
    public function test_either_admin_ends_a_cover_early(): void
    {
        foreach ([self::CEDU_NA, self::BAHAY_NA] as $who) {
            $staff = $this->staffAt(self::VENUES);
            $id = $this->coverNow($staff, self::BAHAY);
            $this->as($who)->save(['action' => 'cover_end', 'id' => $id])->assertOk()->assertJson(['message' => 'Cover ended.']);
            $this->assertSame([self::VENUES], $this->offices($staff));
        }
        $this->assertCount(2, $this->told('cover_ended'));
    }

    public function test_an_admin_of_neither_side_cannot_end_a_cover(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $id = $this->coverNow($staff, self::HOSTEL);
        $this->as(self::BAHAY_NA)->save(['action' => 'cover_end', 'id' => $id])->assertStatus(403);
        $this->assertNull(DB::table('staff_covers')->where('id', $id)->value('ended_at'));
    }

    public function test_the_super_admin_sets_up_and_approves_alone(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $this->as(self::SUPER)->save($this->form($staff, self::BAHAY, ['from' => now()->format('Y-m-d\TH:i')]))->assertOk()->assertJson(['waiting' => false]);
        $this->assertSame([self::BAHAY, self::VENUES], $this->offices($staff));

        $other = $this->staffAt(self::VENUES);
        $id = $this->as(self::CEDU_NA)->save($this->form($other, self::BAHAY))->assertOk()->json('id');
        $this->as(self::SUPER)->save(['action' => 'cover_approve', 'id' => $id])->assertOk();
    }

    /* A group with no normal admin: the request goes to the super admin, who answers. */
    public function test_a_group_with_no_normal_admin_is_answered_by_the_super_admin(): void
    {
        $staff = $this->staffAt(self::VENUES);
        DB::table('users')->where('email', self::BAHAY_NA)->update(['is_active' => 0]);
        $id = $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY))->assertOk()->json('id');
        $this->assertSame([self::SUPER], $this->told('cover_request'));
        $this->as(self::SUPER)->save(['action' => 'cover_approve', 'id' => $id])->assertOk();
    }

    /* Every step shows on the super admin's Activity page, in words. */
    public function test_every_step_is_on_activity(): void
    {
        $staff = $this->staffAt(self::VENUES);
        $a = $this->as(self::CEDU_NA)->save($this->form($staff, self::BAHAY))->json('id');
        $b = $this->save($this->form($staff, self::BAHAY))->json('id');
        $this->save($this->form($staff, self::HOSTEL))->assertOk();
        $this->as(self::BAHAY_NA)->save(['action' => 'cover_approve', 'id' => $a])->assertOk();
        $this->save(['action' => 'cover_reject', 'id' => $b, 'reason' => 'No thanks'])->assertOk();
        $this->save(['action' => 'cover_end', 'id' => $a])->assertOk();
        $this->as(self::SUPER)->get(route('admin.activity'))->assertOk()
            ->assertSee('Asked for a cover at Bahay Alumni')->assertSee('Set a cover at USeP Hostel')
            ->assertSee('Approved the cover at Bahay Alumni')->assertSee('Rejected the cover at Bahay Alumni')
            ->assertSee('No thanks')->assertSee('Ended the cover at Bahay Alumni');
    }

    /* The move hall 5 needs: $mover is its only staff, $spare covers the other halls. */
    private function moverAndSpare(): array
    {
        $mover = $this->staffAt(self::VENUES);
        $spare = $this->staffAt(self::VENUES);
        DB::table('staff_halls')->where('user_id', $spare->id)->where('room_id', 5)->delete();
        return [$mover, $spare];
    }

    private function moveForm(object $staff, int $to, array $over = []): array
    {
        return $over + ['action' => 'move_create', 'user_id' => $staff->id, 'venue_id' => $to, 'reason' => 'Transfer'];
    }

    private function halls(object $staff): array
    {
        return DB::table('staff_halls')->where('user_id', $staff->id)->orderBy('room_id')->pluck('room_id')->map(fn ($v) => (int) $v)->all();
    }

    private function officeOf(object $staff): int
    {
        return (int) DB::table('staff')->where('user_id', $staff->id)->value('venue_id');
    }

    /* The move waits; approved, the person moves and the replacement takes the hall at once. */
    public function test_a_move_across_groups_needs_the_destinations_admin_and_a_replacement(): void
    {
        [$mover, $spare] = $this->moverAndSpare();
        $this->as(self::CEDU_NA);
        $this->save($this->moveForm($mover, self::BAHAY))->assertStatus(400)
            ->assertJson(['error' => 'replacement', 'message' => 'Name a replacement staff member for USeP Gymnasium.']);
        $id = $this->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]))->assertOk()
            ->assertJson(['waiting' => true, 'message' => 'Waiting for approval from Bahay Alumni.'])->json('id');
        $this->assertSame([self::BAHAY_NA], $this->told('move_request'));
        $this->assertSame(self::VENUES, $this->officeOf($mover));
        $this->assertNotContains(5, $this->halls($spare));

        $this->save(['action' => 'move_approve', 'id' => $id])->assertStatus(403);   // not the asker's to approve
        $this->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]))->assertStatus(409)->assertJson(['error' => 'waiting']);

        $this->as(self::BAHAY_NA)->save(['action' => 'move_approve', 'id' => $id])->assertOk();
        $this->assertSame(self::BAHAY, $this->officeOf($mover));
        $this->assertSame([1, 2, 3, 4], $this->halls($mover));
        $this->assertContains(5, $this->halls($spare));
        $this->assertSame([self::CEDU_NA], $this->told('move_approved'));
        $this->as(self::SUPER)->get(route('admin.activity'))->assertOk()->assertSee('Started the move USeP Venues to Bahay Alumni')->assertSee('Approved the move');
    }

    public function test_a_replacement_must_be_of_the_same_office(): void
    {
        [$mover] = $this->moverAndSpare();
        $this->as(self::CEDU_NA)->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => 7])]))->assertStatus(400)->assertJson(['error' => 'replacement']);
        $this->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $mover->id])]))->assertStatus(400)->assertJson(['error' => 'replacement']);
    }

    /* Nobody else in the office: it cannot even be sent. */
    public function test_a_move_with_no_possible_replacement_cannot_be_sent(): void
    {
        $alone = $this->staffAt(self::VENUES);
        $this->as(self::CEDU_NA)->save($this->moveForm($alone, self::BAHAY))->assertStatus(409)->assertJson(['error' => 'no_replacement']);
        $this->assertStringContainsString('Create or assign another staff member to', $this->save($this->moveForm($alone, self::BAHAY))->json('message'));
        $this->assertSame(0, DB::table('staff_moves')->where('user_id', $alone->id)->count());
    }

    /* The hostel is one unit: its last staff member has no hall to hand over. */
    public function test_the_hostels_last_staff_member_cannot_be_moved_away(): void
    {
        $this->as(self::CEDU_NA)->save(['action' => 'move_create', 'user_id' => 73, 'venue_id' => self::BAHAY, 'reason' => 'x'])
            ->assertStatus(409)->assertJson(['error' => 'no_replacement', 'message' => 'Create or assign another staff member to USeP Hostel first.']);
    }

    public function test_a_move_inside_a_group_stays_on_the_staff_list(): void
    {
        [$mover] = $this->moverAndSpare();
        $this->as(self::CEDU_NA)->save($this->moveForm($mover, self::HOSTEL))->assertStatus(400)->assertJson(['field' => 'venue_id']);
    }

    public function test_a_rejected_move_changes_nothing_and_needs_a_reason(): void
    {
        [$mover, $spare] = $this->moverAndSpare();
        $id = $this->as(self::CEDU_NA)->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]))->json('id');
        $this->as(self::BAHAY_NA);
        $this->save(['action' => 'move_reject', 'id' => $id])->assertStatus(400)->assertJson(['field' => 'reason']);
        $this->save(['action' => 'move_reject', 'id' => $id, 'reason' => 'Not now'])->assertOk();
        $this->assertSame(self::VENUES, $this->officeOf($mover));
        $this->assertNotContains(5, $this->halls($spare));
        $this->assertSame([self::CEDU_NA], $this->told('move_rejected'));
    }

    public function test_an_unanswered_move_expires_after_3_days(): void
    {
        [$mover, $spare] = $this->moverAndSpare();
        $form = $this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]);
        $id = $this->as(self::CEDU_NA)->save($form)->json('id');
        DB::table('staff_moves')->where('id', $id)->update(['requested_at' => now()->subDays(3)->subMinute()]);
        $this->save($form)->assertOk();   // no longer in the way of a new one
        $this->as(self::BAHAY_NA)->save(['action' => 'move_approve', 'id' => $id])->assertStatus(409)->assertJson(['error' => 'closed']);
        $this->assertSame(self::VENUES, $this->officeOf($mover));
        $this->as(self::SUPER)->get(route('admin.activity'))->assertOk()->assertSee('expired unanswered');
    }

    public function test_the_super_admin_moves_alone(): void
    {
        [$mover, $spare] = $this->moverAndSpare();
        $this->as(self::SUPER)->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]))->assertOk()->assertJson(['waiting' => false]);
        $this->assertSame(self::BAHAY, $this->officeOf($mover));
        $this->assertContains(5, $this->halls($spare));
    }

    /* The replacement left before the answer: the move is refused whole, nothing half done. */
    public function test_a_replacement_who_left_stops_the_move(): void
    {
        [$mover, $spare] = $this->moverAndSpare();
        $id = $this->as(self::CEDU_NA)->save($this->moveForm($mover, self::BAHAY, ['replacements' => json_encode([5 => $spare->id])]))->json('id');
        DB::table('users')->where('id', $spare->id)->update(['is_active' => 0]);
        $this->as(self::BAHAY_NA)->save(['action' => 'move_approve', 'id' => $id])->assertStatus(409)->assertJson(['error' => 'replacement']);
        $this->assertSame(self::VENUES, $this->officeOf($mover));
        $this->assertNull(DB::table('staff_moves')->where('id', $id)->value('approved'));
    }
}
