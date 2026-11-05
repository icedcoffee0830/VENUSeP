<?php

namespace Tests\Feature\AdminPeople;

use App\Http\Controllers\Customer\BookingRules;
use App\Queries\ReportQueries;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Option B (DB-DECISIONS 28): staff see and act on their own halls and the halls they cover
   right now, nothing else of the office, and the server refuses the rest. The staff member
   here has hall 1 (r1) of Bahay Alumni; r2 is the same office's other hall. */
class StaffHallScopeTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }
    use SeedsBookings;

    private const BAHAY = 1, VENUES = 2, HOSTEL = 3, R1 = 1, R2 = 2;

    private int $lastBooking = 0;
    private int $lastNotification = 0;
    private object $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastBooking = (int) DB::table('bookings')->max('id');
        $this->lastNotification = (int) DB::table('notifications')->max('id');
        $this->staff = $this->staffWith(self::BAHAY, [self::R1]);
    }

    protected function tearDown(): void
    {
        DB::table('notifications')->where('id', '>', $this->lastNotification)->delete();
        DB::table('system_receipts')->where('booking_id', '>', $this->lastBooking)->delete();
        DB::table('payments')->where('booking_id', '>', $this->lastBooking)->delete();
        DB::table('bookings')->where('id', '>', $this->lastBooking)->delete();
        DB::table('staff_covers')->whereIn('user_id', $this->madeUserIds)->delete();
        DB::table('staff_halls')->whereIn('user_id', $this->madeUserIds)->delete();
        $this->removeAccounts();
    }

    /* A staff member of the office with exactly these halls. */
    private function staffWith(int $venue, array $halls): object
    {
        $acct = $this->makeAccount('staff');
        DB::table('staff')->where('user_id', $acct->id)->update(['venue_id' => $venue]);
        DB::table('users')->where('id', $acct->id)->update(['password_changed_at' => now()]);
        DB::table('staff_halls')->where('user_id', $acct->id)->whereNotIn('room_id', $halls)->delete();
        return $acct;
    }

    private function asStaff(?object $staff = null): static
    {
        return $this->signInAs($staff ?? $this->staff, 'staff');
    }

    private function cover(int $venue, ?int $room, array $over = []): int
    {
        return (int) DB::table('staff_covers')->insertGetId($over + ['user_id' => $this->staff->id, 'venue_id' => $venue, 'room_id' => $room,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour(), 'reason' => 'test', 'approved_at' => now()]);
    }

    private function act(string $ref, string $action, array $extra = [])
    {
        return $this->asStaff()->postWithToken(route('admin.booking-action'), ['booking' => $ref, 'action' => $action, 'note' => 'Test note'] + $extra);
    }

    /* The calendar's events, read back out of the page. */
    private function calendarEvents(object $viewer, string $type = 'staff'): array
    {
        $html = $this->signInAs($viewer, $type)->get(route('admin.calendar'))->assertOk()->getContent();
        $this->assertSame(1, preg_match('/events: (\[.*\]),\s*\}\);/s', $html, $m));
        return json_decode($m[1], true);
    }

    private function news(int $bookingId): int
    {
        return (int) DB::table('notifications')->insertGetId(['user_id' => $this->staff->id, 'kind' => 'new_request', 'booking_id' => $bookingId]);
    }

    private function bellIds(): array
    {
        return array_column($this->asStaff()->getJson(route('root.notifications'))->assertOk()->json('items'), 'id');
    }

    /* ---------------------------------------------------------------- booking list */

    public function test_the_list_the_ledger_and_the_dashboard_hold_their_halls_only(): void
    {
        [, $own] = $this->seedVenueBooking('r1');
        [, $other] = $this->seedVenueBooking('r2');
        foreach (['admin.booking-requests', 'admin.transaction-history', 'admin.dashboard'] as $page) {
            $this->asStaff()->get(route($page))->assertOk()->assertDontSee($other);
        }
        $this->asStaff()->get(route('admin.booking-requests'))->assertSee($own)->assertDontSee('Whole office');
        $this->asStaff()->get(route('admin.transaction-history'))->assertSee($own);
    }

    public function test_the_live_check_does_not_move_for_another_hall(): void
    {
        $version = fn () => $this->asStaff()->getJson(route('root.live-check', ['page' => 'queue']))->json('page');
        $before = $version();
        $this->seedVenueBooking('r2');
        $this->assertSame($before, $version());
        $this->seedVenueBooking('r1');
        $this->assertNotSame($before, $version());
    }

    /* The money tile and the venue box follow the halls; the admin's tile stays the office's. */
    public function test_the_dashboard_tiles_follow_the_halls(): void
    {
        [$other] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        DB::table('venue_booking_details')->where('booking_id', $other)->update(['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        DB::table('bookings')->where('id', $other)->update(['total_amount' => 7654321]);
        $staffTile = $this->asStaff()->get(route('admin.dashboard'))->assertOk();
        $this->assertLessThan(7654321, $staffTile->viewData('adRevenue'));
        $this->assertSame(0, $staffTile->viewData('adToday'));
        $this->assertSame([1], array_map('intval', array_column($staffTile->viewData('adVenues'), 'rooms')));

        $adminTile = $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.dashboard'))->assertOk();
        $this->assertGreaterThanOrEqual(7654321, $adminTile->viewData('adRevenue'));
    }

    /* ---------------------------------------------------------------- the booking page */

    public static function actionFamilies(): array
    {
        return [
            'approve' => ['approve'], 'reject' => ['reject'],
            'payment' => ['confirm_payment'], 'cash' => ['cash_received'], 'reject payment' => ['reject_payment'],
            'check-in' => ['check_in'], 'check-out' => ['check_out'], 'POS' => ['record_pos'], 'OR' => ['record_or'],
            'refund file' => ['refund_file'], 'refund return' => ['refund_return'], 'refund pay out' => ['refund_complete'],
            'move date' => ['move_date'], 'extend' => ['extend_deadline'], 'release' => ['release_slot'],
            'cancel' => ['force_cancel'], 'write off' => ['write_off_request'],
        ];
    }

    #[DataProvider('actionFamilies')]
    public function test_a_forged_action_on_another_hall_is_refused(string $action): void
    {
        [$id, $ref] = $this->seedVenueBooking('r2');
        $before = $this->booking($id);
        $this->act($ref, $action)->assertStatus(403)->assertJson(['ok' => false]);
        $this->assertEquals($before, $this->booking($id));
    }

    public function test_the_booking_page_and_its_papers_refuse_another_hall(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        DB::table('venue_booking_details')->where('booking_id', $id)->update(['event_name' => 'Other hall secret']);

        $this->asStaff()->get(route('admin.booking-request', ['id' => $ref]))->assertOk()->assertDontSee('Other hall secret')->assertDontSee($ref);
        $this->asStaff()->get(route('admin.booking-request', ['id' => $ref, 'part' => 'brq-row']))->assertDontSee('Other hall secret');
        $this->asStaff()->get(route('root.booking-details', ['booking' => $ref]))->assertNotFound();
        $this->asStaff()->get(route('root.valid-id-view', ['booking' => $ref]))->assertNotFound();
        $this->asStaff()->get(route('admin.booking-emails', ['booking' => $ref]))->assertStatus(403);
        $this->asStaff()->postWithToken(route('admin.booking-emails'), ['booking' => $ref, 'action' => 'resend'])->assertStatus(403);
    }

    /* A real receipt: the same office's staff get it on its hall, a 404 on any other. */
    public function test_a_receipt_of_another_hall_is_refused(): void
    {
        [, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_cash', 'cash');
        $this->act($ref, 'confirm_payment')->assertOk();
        $away = $this->staffWith(self::BAHAY, [self::R2]);

        foreach (['admin.receipt', 'root.receipt-pdf'] as $page) {
            $this->asStaff($away)->get(route($page, ['booking' => $ref]))->assertNotFound();
            $this->asStaff()->get(route($page, ['booking' => $ref]))->assertOk();
        }
    }

    /* A real file: its hall's staff open it, the office's other staff get a 404. */
    public function test_a_document_of_another_hall_is_refused(): void
    {
        [$id] = $this->seedVenueBooking('r2');
        $path = "bookings/$id/customer_id_scope.png";
        $file = getenv('VENUSEP_DOC_ROOT') . '/' . $path;
        @mkdir(dirname($file), 0700, true);
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        $row = (array) DB::table('booking_documents')->where('id', 3)->first();
        unset($row['id']);
        $doc = (int) DB::table('booking_documents')->insertGetId(['booking_id' => $id, 'file_path' => $path, 'removed_at' => null] + $row);
        try {
            $this->asStaff()->get(route('root.document-view', ['doc' => $doc]))->assertNotFound();
            $this->asStaff($this->staffWith(self::BAHAY, [self::R2]))->get(route('root.document-view', ['doc' => $doc]))->assertOk();
        } finally {
            DB::table('booking_documents')->where('id', $doc)->delete();
            @unlink($file);
            @rmdir(dirname($file));
        }
    }

    /* ---------------------------------------------------------------- the counter */

    public function test_the_counter_lists_and_books_their_halls_only(): void
    {
        $r1 = DB::table('rooms')->where('room_code', 'r1')->value('name');
        $r2 = DB::table('rooms')->where('room_code', 'r2')->value('name');
        $this->asStaff()->get(route('customer.venues', ['counter' => 1]))->assertOk()->assertSee($r1)->assertDontSee($r2);
        $this->asStaff()->get(route('customer.room-reservation', ['room' => 'r2', 'counter' => 1]))->assertRedirect();
        $this->asStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk();

        $count = DB::table('bookings')->count();
        $this->asStaff()->postWithToken(route('admin.booking-create'), ['type' => 'venue', 'room' => 'r2', 'method' => 'gcash', 'id_checked' => '1',
            'usep_id' => '1', 'customer_id' => $this->customerId()])->assertStatus(403);
        $this->assertSame($count, DB::table('bookings')->count());
        $this->asStaff()->postWithToken(route('admin.customer-create'), ['room' => 'r2', 'full_name' => 'Nobody Here', 'email' => 'nobody@test.local', 'phone' => '09175550188'])
            ->assertStatus(403);
    }

    /* ---------------------------------------------------------------- Venue Management */

    public function test_venue_management_shows_and_changes_their_halls_only(): void
    {
        $r1 = DB::table('rooms')->where('room_code', 'r1')->first();
        $r2 = DB::table('rooms')->where('room_code', 'r2')->first();
        $this->asStaff()->get(route('admin.venue-management'))->assertOk()->assertSee($r1->name)->assertDontSee($r2->name)
            ->assertDontSee(route('admin.venue-form', ['id' => self::BAHAY]));
        $this->asStaff()->get(route('admin.room-form', ['id' => 'r2']))->assertRedirect(route('admin.venue-management'));
        $this->asStaff()->get(route('admin.room-form', ['id' => 'r1']))->assertOk();

        $this->asStaff()->postWithToken(route('admin.room-save'), ['room_id' => 'r2', 'name' => 'Scope Rename', 'venue' => 'Bahay Alumni', 'room_type' => 'event'])
            ->assertStatus(403)->assertJson(['error' => 'forbidden']);
        $this->asStaff()->postWithToken(route('admin.room-toggle-active'), ['room_id' => 'r2'])->assertStatus(403);
        $this->asStaff()->postWithToken(route('admin.room-photos-api'), ['action' => 'delete_pano', 'room_id' => 'r2'])->assertStatus(403);
        $this->assertEquals($r2, DB::table('rooms')->where('room_code', 'r2')->first());
    }

    /* An office's own details touch every hall: some of the halls is not enough, all of them is. */
    public function test_the_office_details_need_every_hall_of_it(): void
    {
        $venue = DB::table('venues')->where('id', self::BAHAY)->first();
        $form = ['venue_id' => self::BAHAY, 'name' => 'Scope Rename', 'description' => 'x'];
        $this->asStaff()->get(route('admin.venue-form', ['id' => self::BAHAY]))->assertRedirect(route('admin.venue-management'));
        $this->asStaff()->postWithToken(route('admin.venue-save'), $form)->assertStatus(403);
        $this->asStaff()->postWithToken(route('admin.venue-photo-api'), ['action' => 'delete', 'venue_id' => self::BAHAY])->assertStatus(403);
        $this->assertSame($venue->name, DB::table('venues')->where('id', self::BAHAY)->value('name'));

        $all = DB::table('rooms')->where('venue_id', self::BAHAY)->whereNull('deleted_at')->pluck('id')->all();
        $whole = $this->staffWith(self::BAHAY, $all);
        $this->asStaff($whole)->get(route('admin.venue-form', ['id' => self::BAHAY]))->assertOk();
        $this->asStaff($whole)->get(route('admin.venue-management'))->assertSee(route('admin.venue-form', ['id' => self::BAHAY]));
    }

    /* ---------------------------------------------------------------- the bell */

    public function test_the_bell_keeps_their_halls_news_and_a_cover_takes_its_news_with_it(): void
    {
        [$own] = $this->seedVenueBooking('r1');
        [$other] = $this->seedVenueBooking('r2');
        $mine = $this->news($own);
        $theirs = $this->news($other);
        $none = (int) DB::table('notifications')->insertGetId(['user_id' => $this->staff->id, 'kind' => 'cover_set']);

        $ids = $this->bellIds();
        $this->assertContains($mine, $ids);
        $this->assertContains($none, $ids);
        $this->assertNotContains($theirs, $ids);
        $this->assertSame(2, $this->asStaff()->getJson(route('root.notifications'))->json('unread'));
        $this->assertStringStartsWith('2.', $this->asStaff()->getJson(route('root.live-check'))->json('bell'));

        $cover = $this->cover(self::BAHAY, self::R2);
        $this->assertContains($theirs, $this->bellIds());
        DB::table('staff_covers')->where('id', $cover)->update(['ended_at' => now()]);
        $this->assertNotContains($theirs, $this->bellIds());
    }

    /* ---------------------------------------------------------------- the calendar */

    public function test_the_calendar_shows_other_halls_as_busy_and_leaks_nothing(): void
    {
        [$other] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        DB::table('venue_booking_details')->where('booking_id', $other)->update(['event_name' => 'Other hall secret']);
        $day = DB::table('venue_booking_details')->where('booking_id', $other)->value('start_date');
        [$gone] = $this->seedVenueBooking('r2', 'cancelled', 'locked');
        $goneDay = DB::table('venue_booking_details')->where('booking_id', $gone)->value('start_date');
        [$office] = $this->seedVenueBooking('r5', 'approved', 'confirmed');
        $officeDay = DB::table('venue_booking_details')->where('booking_id', $office)->value('start_date');

        $page = $this->asStaff()->get(route('admin.calendar'))->assertOk()->assertDontSee('Other hall secret');
        $page->assertDontSee(DB::table('rooms')->where('room_code', 'r2')->value('name'));
        $page->assertDontSee(DB::table('customers')->where('id', $this->customerId())->value('full_name'));

        $events = collect($this->calendarEvents($this->staff));
        $busy = $events->firstWhere('start', $day);
        $this->assertSame(['title', 'start', 'end', 'backgroundColor', 'borderColor', 'textColor'], array_keys($busy));
        $this->assertSame('Busy', $busy['title']);
        $this->assertSame(date('Y-m-d', strtotime($day . ' +1 day')), $busy['end']);
        $this->assertNull($events->firstWhere('start', $goneDay));      // a cancelled booking holds nothing
        $this->assertNull($events->firstWhere('start', $officeDay));    // another office is not on it at all

        /* a hall of theirs keeps its full event */
        [$own] = $this->seedVenueBooking('r1');
        $ownDay = DB::table('venue_booking_details')->where('booking_id', $own)->value('start_date');
        $this->assertStringContainsString('Laravel test event', collect($this->calendarEvents($this->staff))->firstWhere('start', $ownDay)['title']);
    }

    /* ---------------------------------------------------------------- covers */

    public function test_a_cover_grants_the_hall_and_ending_it_removes_it_at_once(): void
    {
        [, $ref] = $this->seedVenueBooking('r2');
        $page = fn () => $this->asStaff()->get(route('root.booking-details', ['booking' => $ref]));
        $page()->assertNotFound();

        $waiting = $this->cover(self::BAHAY, self::R2, ['approved_at' => null]);
        $page()->assertNotFound();                     // not approved yet
        DB::table('staff_covers')->where('id', $waiting)->delete();
        $scheduled = $this->cover(self::BAHAY, self::R2, ['starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        $page()->assertNotFound();                     // not started yet
        DB::table('staff_covers')->where('id', $scheduled)->delete();

        $on = $this->cover(self::BAHAY, self::R2);
        $page()->assertOk();
        $this->asStaff()->get(route('admin.booking-requests'))->assertSee($ref);
        $this->act($ref, 'no_such_action')->assertStatus(400);   // past the hall check

        DB::table('staff_covers')->where('id', $on)->update(['ended_at' => now()]);
        $page()->assertNotFound();
        $this->act($ref, 'approve')->assertStatus(403);

        DB::table('staff_covers')->where('id', $on)->update(['ended_at' => null, 'ends_at' => now()->subMinute(), 'starts_at' => now()->subHour()]);
        $page()->assertNotFound();                     // it ran out by itself
    }

    public function test_a_cover_of_one_hall_of_another_office_gives_that_hall_only(): void
    {
        [, $covered] = $this->seedVenueBooking('r5');
        [, $notCovered] = $this->seedVenueBooking('r6');
        $this->cover(self::VENUES, (int) DB::table('rooms')->where('room_code', 'r5')->value('id'));
        $this->asStaff()->get(route('root.booking-details', ['booking' => $covered]))->assertOk();
        $this->asStaff()->get(route('root.booking-details', ['booking' => $notCovered]))->assertNotFound();
    }

    /* ---------------------------------------------------------------- the hostel */

    public function test_the_hostel_is_one_unit(): void
    {
        [, $stay] = $this->seedHostelBooking();
        $rooms = DB::table('rooms')->where('venue_id', self::HOSTEL)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $hostelStaff = $this->staffWith(self::HOSTEL, []);
        $this->asStaff($hostelStaff)->get(route('root.booking-details', ['booking' => $stay]))->assertOk();
        legacy('auth');
        $held = staff_hall_ids();
        sort($held);
        $this->assertSame($rooms, $held);

        /* not theirs: nothing of it; a cover of the hostel: all of it, until it ends */
        $this->asStaff()->get(route('root.booking-details', ['booking' => $stay]))->assertNotFound();
        $cover = $this->cover(self::HOSTEL, null);
        $this->asStaff()->get(route('root.booking-details', ['booking' => $stay]))->assertOk();
        $held = staff_hall_ids();
        $this->assertSame([], array_diff($rooms, $held));
        DB::table('staff_covers')->where('id', $cover)->update(['ended_at' => now()]);
        $this->asStaff()->get(route('root.booking-details', ['booking' => $stay]))->assertNotFound();
    }

    /* ---------------------------------------------------------------- no halls */

    public function test_staff_with_no_halls_sign_in_and_are_told_why_the_pages_are_empty(): void
    {
        $note = 'You have no halls yet. Ask your admin to assign you one.';
        $staff = $this->staffWith(self::BAHAY, []);
        $email = DB::table('users')->where('id', $staff->id)->value('email');
        $this->post(route('admin.admin-login'), ['email' => $email, 'password' => self::PASSWORD, 'department' => self::BAHAY])->assertRedirect();
        $this->assertSame($staff->id, (int) session('user_id'));

        foreach (['admin.dashboard', 'admin.booking-requests', 'admin.calendar', 'admin.venue-management', 'admin.transaction-history'] as $page) {
            $this->asStaff($staff)->get(route($page))->assertOk()->assertSee($note);
        }
        $this->asStaff($staff)->get(route('customer.venues', ['counter' => 1]))->assertOk()->assertSee($note);
        $this->asStaff()->get(route('admin.dashboard'))->assertOk()->assertDontSee($note);   // has a hall

        [, $ref] = $this->seedVenueBooking('r1');
        $this->asStaff($staff)->get(route('root.booking-details', ['booking' => $ref]))->assertNotFound();
        $this->asStaff($staff)->getJson(route('admin.customer-search', ['q' => 'customer@gmail.com']))->assertStatus(403)->assertJson(['results' => []]);
        $this->asStaff($staff)->postWithToken(route('admin.customer-verify'), ['customer_id' => $this->customerId(), 'password' => 'x'])->assertStatus(403);
    }

    /* ---------------------------------------------------------------- what stays */

    public function test_reports_stay_whole_office(): void
    {
        [$other] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        DB::table('venue_booking_details')->where('booking_id', $other)->update(['start_date' => now()->toDateString(), 'end_date' => now()->toDateString()]);
        DB::table('bookings')->where('id', $other)->update(['total_amount' => 7654321]);

        $this->asStaff()->get(route('admin.reports'))->assertOk();
        legacy('auth');
        $this->assertSame([self::BAHAY], staff_venue_scope());
        $this->assertGreaterThanOrEqual(7654321, ReportQueries::quarters(staff_venue_scope(), 1)[0]['revenue']);
    }

    public function test_admins_are_unchanged(): void
    {
        [, $ref] = $this->seedVenueBooking('r2');
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.booking-requests'))->assertOk()->assertSee($ref);
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('root.booking-details', ['booking' => $ref]))->assertOk();
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.calendar'))->assertOk()->assertDontSee('"title":"Busy"', false);
        $this->actingAsAdmin('admin@gmail.com')->get(route('admin.booking-requests'))->assertOk()->assertSee($ref);
        $this->actingAsAdmin('admin@gmail.com')->get(route('admin.calendar'))->assertOk()->assertDontSee('"title":"Busy"', false);
    }

    /* ---------------------------------------------------------------- attacks (phase RCHECK) */

    private function roomId(string $code): int
    {
        return (int) DB::table('rooms')->where('room_code', $code)->value('id');
    }

    private function saveRoom(string $code, string $venue)
    {
        $name = DB::table('rooms')->where('room_code', $code)->value('name');
        return $this->asStaff()->postWithToken(route('admin.room-save'), ['room_id' => $code, 'name' => $name, 'venue' => $venue, 'room_type' => 'event']);
    }

    /* A hall moved to the staff member's office would be theirs for good, the cover long over. */
    public function test_a_covered_hall_cannot_be_carried_home(): void
    {
        $this->cover(self::VENUES, $this->roomId('r5'));
        $this->saveRoom('r5', 'Bahay Alumni')->assertStatus(403)->assertJson(['error' => 'staff_move']);
        $this->assertSame(self::VENUES, (int) DB::table('rooms')->where('room_code', 'r5')->value('venue_id'));
        $this->assertFalse(DB::table('staff_halls')->where(['user_id' => $this->staff->id, 'room_id' => $this->roomId('r5')])->exists());
    }

    public function test_their_own_hall_cannot_be_sent_to_a_covered_office(): void
    {
        $this->cover(self::VENUES, null);
        $this->saveRoom('r1', 'USeP Venues')->assertStatus(403)->assertJson(['error' => 'staff_move']);
        $this->assertSame(self::BAHAY, (int) DB::table('rooms')->where('room_code', 'r1')->value('venue_id'));
    }

    public function test_staff_still_save_a_hall_where_it_is_and_the_form_offers_no_other_office(): void
    {
        $this->saveRoom('r1', 'Bahay Alumni')->assertOk();
        $this->cover(self::VENUES, null);
        $this->asStaff()->get(route('admin.room-form', ['id' => 'r1']))->assertOk()
            ->assertSee('id="roomLoc" class="vm-control" type="text" value="Bahay Alumni" readonly', false)
            ->assertDontSee('<li class="vm-dd-option">USeP Venues', false);
    }

    /* The counter's warning: the rule stays (the customer owes the office), the other hall's details do not reach the page. */
    public function test_the_counter_warning_names_no_detail_of_another_hall(): void
    {
        /* a customer of its own: the shared one may hold other tests' overdue bookings, and only the oldest per office is named */
        $owner = $this->makeAccount('customer')->customer_id;
        [$other] = $this->seedVenueBooking('r2', 'completed', 'overdue');
        DB::table('bookings')->where('id', $other)->update(['customer_id' => $owner, 'total_amount' => 7654.32]);
        $page = $this->asStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk();
        $page->assertSee('Another room at Bahay Alumni')->assertDontSee('7,654.32');
        $page->assertDontSee(DB::table('rooms')->where('room_code', 'r2')->value('name'));

        /* their own hall keeps the details, and a customer still reads their own */
        DB::table('bookings')->where('id', $other)->update(['room_id' => self::R1]);
        $this->asStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk()->assertSee('7,654.32');

        /* an admin reads it whole, another group's office included (the counter booking's history) */
        DB::table('bookings')->where('id', $other)->update(['room_id' => $this->roomId('r5')]);
        $this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.dashboard'))->assertOk();
        $owes = BookingRules::overdueBookings(venusep_db(), null, $owner)[$owner] ?? '';
        $this->assertStringContainsString(' at USeP Venues, ', $owes);
    }

    /* The counter pages with no room, or a list for a room: the lists stay narrowed. */
    public function test_the_counter_pages_with_no_room_hold_no_other_room(): void
    {
        $this->asStaff()->get(route('customer.room-reservation', ['counter' => 1]))->assertOk()
            ->assertDontSee(DB::table('rooms')->where('room_code', 'r2')->value('name'));
        $this->asStaff()->get(route('customer.hostel-reservation', ['counter' => 1]))->assertOk()
            ->assertDontSee(DB::table('rooms')->where('room_code', 'h1')->value('name'));
        $this->asStaff()->get(route('customer.room-reservation', ['counter' => 1, 'room' => ['r2']]))->assertOk()
            ->assertDontSee(DB::table('rooms')->where('room_code', 'r2')->value('name'));
    }

    public function test_halls_taken_or_an_office_change_count_on_the_next_request(): void
    {
        [, $ref] = $this->seedVenueBooking('r1');
        $page = fn () => $this->asStaff()->get(route('root.booking-details', ['booking' => $ref]));
        $page()->assertOk();

        DB::table('staff_halls')->where('user_id', $this->staff->id)->delete();
        $page()->assertNotFound();
        $this->act($ref, 'approve')->assertStatus(403);
        $this->asStaff()->get(route('admin.booking-requests'))->assertDontSee($ref);

        DB::table('staff_halls')->insert(['user_id' => $this->staff->id, 'room_id' => self::R1]);
        $page()->assertOk();
        DB::table('staff')->where('user_id', $this->staff->id)->update(['venue_id' => self::VENUES]);
        $page()->assertNotFound();
        $this->act($ref, 'approve')->assertStatus(403);
    }

    public function test_payment_settings_shows_staff_no_booking_counts_and_changes_nothing(): void
    {
        [, $ref] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        $name = DB::table('customers')->where('id', $this->customerId())->value('full_name');
        $gcash = DB::table('gcash_accounts')->count();
        $refunds = DB::table('venues')->pluck('refunds_enabled', 'id');

        foreach ([$this->staff, $this->staffWith(self::BAHAY, [])] as $staff) {
            $this->asStaff($staff)->get(route('admin.payment-settings'))->assertOk()
                ->assertDontSee('data-open', false)->assertDontSee('data-stay', false)->assertDontSee('data-rs-open', false)
                ->assertDontSee($ref)->assertDontSee($name);
            $this->asStaff($staff)->postWithToken(route('admin.gcash-account-save'), ['venue' => 'Bahay Alumni', 'account_name' => 'Forged', 'mobile_number' => '09170000000', 'password' => self::PASSWORD])
                ->assertStatus(403);
            $this->asStaff($staff)->postWithToken(route('admin.refund-switch'), ['venue_id' => (string) self::BAHAY, 'enable' => '0', 'password' => self::PASSWORD])
                ->assertStatus(403);
        }
        $this->assertSame($gcash, DB::table('gcash_accounts')->count());
        $this->assertEquals($refunds, DB::table('venues')->pluck('refunds_enabled', 'id'));
    }

    public function test_venue_cards_carry_staff_names_but_no_booking_of_another_hall(): void
    {
        [$other, $ref] = $this->seedVenueBooking('r2', 'approved', 'confirmed');
        DB::table('venue_booking_details')->where('booking_id', $other)->update(['event_name' => 'Other hall secret']);
        $colleague = DB::table('staff')->where('venue_id', self::BAHAY)->where('user_id', '<>', $this->staff->id)->value('full_name');
        $this->asStaff()->get(route('admin.venue-management'))->assertOk()->assertSee($colleague)
            ->assertDontSee($ref)->assertDontSee('Other hall secret')
            ->assertDontSee(DB::table('customers')->where('id', $this->customerId())->value('full_name'));
    }

    public function test_a_cover_cannot_be_set_on_part_of_the_hostel(): void
    {
        $this->actingAsAdmin()->postWithToken(route('admin.staff-cover-save'), ['action' => 'cover_create', 'user_id' => $this->staff->id, 'venue_id' => self::HOSTEL,
            'room_id' => $this->roomId('h1'), 'from' => now()->format('Y-m-d\TH:i'), 'to' => now()->addDay()->format('Y-m-d\TH:i'), 'reason' => 'test'])
            ->assertStatus(400)->assertJson(['field' => 'room_id']);
    }

    public static function adminOnlyWrites(): array
    {
        return [
            'staff-save'         => ['admin.staff-save', ['action' => 'set_active', 'user_id' => 1, 'active' => 0]],
            'set a cover'        => ['admin.staff-cover-save', ['action' => 'cover_create']],
            'customer block'     => ['admin.customer-save', ['action' => 'block', 'customer_id' => 1, 'venue_id' => 1, 'reason' => 'x']],
            'office-save'        => ['admin.office-save', ['action' => 'save', 'venue_id' => self::BAHAY]],
            'delete a room'      => ['admin.catalog-delete', ['entity' => 'room', 'room_id' => 'r1', 'confirm' => 'delete']],
            'add a room'         => ['admin.room-save', ['room_id' => '', 'name' => 'Forged', 'venue' => 'Bahay Alumni', 'room_type' => 'event']],
            'backup'             => ['admin.backup-action', ['action' => 'create']],
            'faq-save'           => ['admin.faq-save', ['action' => 'save']],
            'make a staff login' => ['root.register-submit', ['kind' => 'staff', 'venue_id' => self::BAHAY]],
            'customer booking'   => ['customer.booking-submit', ['room' => 'r2']],
        ];
    }

    #[DataProvider('adminOnlyWrites')]
    public function test_a_forged_post_to_a_write_staff_do_not_have_is_refused(string $route, array $form): void
    {
        $status = $this->asStaff()->postWithToken(route($route), $form)->getStatusCode();
        $this->assertContains($status, [302, 401, 403], "$route answered $status");
    }
}
