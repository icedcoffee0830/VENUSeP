<?php

namespace Tests\Feature;

use App\Queries\AccountQueries;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\AdminVenues\VenuesTestCase;
use Tests\Feature\Auth\MakesAccounts;

/* Cleanup H-K moved the SQL from plain PDO to DB::. These are the paths such a move silently
   changes: a save that must undo itself, a duplicate key turned into a sentence, a row count
   turned into a refusal. A refusal mid-save is a real trigger made for the test, on the test
   database only, dropped afterwards. */
class AfterSqlMoveTest extends VenuesTestCase
{
    use MakesAccounts { tearDown as private dropAccounts; }
    use SeedsBookings;

    protected function tearDown(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_test_refuse');
        $this->dropAccounts();
    }

    /* The next matching write on $table is refused by the database, as a procedure refuses. */
    private function refuse(string $event, string $table, string $when = '1'): void
    {
        $this->assertStringContainsString('test', (string) DB::connection()->getDatabaseName(), 'only ever on the test database');
        DB::unprepared("CREATE TRIGGER trg_test_refuse BEFORE {$event} ON {$table} FOR EACH ROW
            BEGIN IF {$when} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Test refusal.'; END IF; END");
    }

    private function assertNoOneIsSigned(): void
    {
        $this->assertNull(DB::selectOne('SELECT @venusep_actor_user_id AS who')->who);
        $this->assertSame(0, DB::transactionLevel());
    }

    private function receipt(): UploadedFile
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==');

        return UploadedFile::fake()->createWithContent('receipt.png', $png . random_bytes(16));
    }

    private function asOwner(): static
    {
        return $this->actingAsCustomer('customer@gmail.com')->withSession(['_token' => 'tok']);
    }

    /* ---- the GCash upload ---- */

    /* Two uploads of one receipt at the same moment: the rival lands between our check and our
       insert. The UNIQUE index refuses ours, and the payment row made just before is undone. */
    public function test_an_upload_that_loses_the_race_for_a_reference_says_so_and_keeps_nothing(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash', 'cash');
        [$rivalId] = $this->seedVenueBooking('r2', 'approved', 'await_gcash');
        $number = '8' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT);
        config(['database.connections.rival' => config('database.connections.mysql')]);
        $done = false;
        DB::listen(function ($query) use (&$done, $rivalId, $number) {
            if (!$done && $query->connectionName === 'mysql' && str_contains($query->sql, 'active_sha256')) {
                $done = true;
                DB::connection('rival')->insert("INSERT INTO gcash_receipts (booking_id, file_path, reference_number, sha256_hash, verdict)
                                                 VALUES (?, 'rival.png', ?, ?, 'manual_review')", [$rivalId, $number, bin2hex(random_bytes(32))]);
            }
        });
        try {
            $this->asOwner()->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'gcash',
                'receipt' => $this->receipt(), 'ref' => $number, 'receiver_name' => 'RA***N V.', 'receiver_number' => '+63 918 334 5566',
                'amount_centavos' => (string) ((int) round(DB::table('bookings')->where('id', $id)->value('total_amount') * 100)), 'receipt_datetime' => 'Oct 28, 2026 10:15 AM', 'confidence' => '91', 'flags' => '[]', 'text_excerpt' => 'GCash'])
                ->assertStatus(409)->assertExactJson(['ok' => false, 'verdict' => 'rejected', 'message' => 'That GCash reference number has already been used for a booking.']);
            $this->assertTrue($done);
            $this->assertSame(0, DB::table('payments')->where('booking_id', $id)->count());
            $this->assertSame(0, DB::table('gcash_receipts')->where('booking_id', $id)->count());
            $this->assertSame('cash', DB::table('bookings')->where('id', $id)->value('payment_method'));
            $this->assertSame('await_gcash', DB::table('bookings')->where('id', $id)->value('payment_status'));
        } finally {
            DB::table('gcash_receipts')->where('booking_id', $rivalId)->delete();
        }
    }

    public function test_the_upload_reads_its_booking_under_a_lock_inside_its_transaction(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $levels = [];
        DB::listen(function ($query) use (&$levels) {
            if (str_contains($query->sql, 'FOR UPDATE') && str_contains($query->sql, 'receipt_attempts_allowed')) {
                $levels[] = DB::transactionLevel();
            }
        });
        $this->refuse('INSERT', 'payments');   // stops the save after the lock, and keeps the row clean
        $this->asOwner()->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'gcash',
            'receipt' => $this->receipt(), 'ref' => '7' . str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), 'amount_centavos' => '100'])
            ->assertStatus(500);
        $this->assertSame([1], $levels);
        $this->assertNoOneIsSigned();
        $this->assertSame(0, DB::table('gcash_receipts')->where('booking_id', $id)->count());
    }

    /* ---- the cash choice, now one transaction ---- */

    public function test_a_refused_cash_choice_leaves_the_method_as_it_was(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash', 'gcash');
        $this->refuse('UPDATE', 'bookings', "NEW.payment_status = 'await_cash'");
        $this->asOwner()->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'cash'])->assertStatus(500);
        $row = DB::table('bookings')->where('id', $id)->first();
        $this->assertSame(['gcash', 'await_gcash'], [$row->payment_method, $row->payment_status]);
        $this->assertNoOneIsSigned();
    }

    public function test_the_cash_choice_is_signed_by_the_customer_in_the_history(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'await_gcash', 'gcash');
        $this->asOwner()->post(route('customer.payment-submit'), ['_token' => 'tok', 'booking' => $ref, 'method' => 'cash'])->assertOk();
        $line = DB::table('booking_timeline')->where('booking_id', $id)->orderByDesc('id')->first();
        $this->assertSame('Customer will pay in cash.', $line->note);
        $this->assertSame((int) session('user_id'), (int) $line->performed_by_user_id);
        $this->assertNoOneIsSigned();
    }

    /* ---- a save that must undo itself ---- */

    public function test_a_refund_withdrawal_the_booking_refuses_undoes_the_refund_too(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'approved', 'refund_requested', 'gcash');
        $refund = DB::table('refunds')->insertGetId(['booking_id' => $id, 'refund_status' => 'requested', 'reason_category' => 'other',
            'reason' => 'Test', 'amount_requested' => 100, 'requested_by_user_id' => DB::table('users')->where('email', 'customer@gmail.com')->value('id')]);
        $this->refuse('UPDATE', 'bookings', "NEW.payment_status = 'confirmed'");
        $this->asOwner()->post(route('customer.refund-submit'), ['_token' => 'tok', 'booking' => $ref, 'action' => 'withdraw'])->assertStatus(500);
        $this->assertSame('requested', DB::table('refunds')->where('id', $refund)->value('refund_status'));
        $this->assertSame('refund_requested', DB::table('bookings')->where('id', $id)->value('payment_status'));
        $this->assertNoOneIsSigned();
    }

    public function test_a_counter_stay_whose_receipt_fails_leaves_no_booking_and_no_walk_in(): void
    {
        [$bookings, $customers, $payments] = [DB::table('bookings')->count(), DB::table('customers')->count(), DB::table('payments')->count()];
        $in = now()->addDays(random_int(400, 4000));
        $this->refuse('INSERT', 'system_receipts');
        $this->actingAsAdmin()->postWithToken(route('admin.booking-create'), [
            'type' => 'hostel', 'room' => 'h1', 'id_checked' => '1', 'usep_id' => '0', 'agree_no_refund' => '1', 'cash_received' => '1',
            'booker_mode' => 'walkin', 'walkin_name' => 'Failed Walk-in', 'walkin_phone' => '09171234567', 'walkin_no_email' => '1',
            'check_in' => $in->toDateString(), 'check_out' => $in->copy()->addDays(2)->toDateString(),
            'occupants' => json_encode([['name' => 'Guest One', 'gender' => 'female']]),
        ])->assertStatus(500)->assertJson(['ok' => false, 'message' => 'Something went wrong, so nothing was created and nothing was charged.']);
        $this->assertSame([$bookings, $customers, $payments], [DB::table('bookings')->count(), DB::table('customers')->count(), DB::table('payments')->count()]);
        $this->assertNoOneIsSigned();
    }

    public function test_a_new_hostel_room_that_fails_at_its_beds_leaves_no_room(): void
    {
        $this->refuse('INSERT', 'hostel_beds');
        $this->actingAsAdmin();
        $this->postForm('admin.room-save', ['room_id' => '', 'name' => 'Half Built Dorm', 'venue' => 'USeP Hostel', 'room_type' => 'hostel',
            'beds' => '2', 'rate' => '350', 'cr_type' => 'communal', 'check_in_time' => '14:00', 'check_out_time' => '11:00'])->assertStatus(500)->assertJson(['ok' => false, 'error' => 'server']);
        $this->assertSame(0, DB::table('rooms')->where('name', 'Half Built Dorm')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    /* ---- a duplicate key turned into a sentence ---- */

    public function test_a_duplicate_room_name_is_the_sentence_not_an_error(): void
    {
        $this->actingAsAdmin();
        $taken = DB::table('rooms')->where('room_code', 'r2')->value('name');
        $venue = DB::table('rooms as r')->join('venues as v', 'v.id', '=', 'r.venue_id')->where('r.room_code', 'r2')->value('v.name');
        $count = DB::table('rooms')->count();
        $this->postForm('admin.room-save', ['room_id' => '', 'name' => $taken, 'venue' => $venue, 'room_type' => 'event', 'capacity' => '40', 'fee' => '1500',
            'staff_ids' => json_encode(DB::table('staff_halls as h')->join('rooms as r', 'r.id', '=', 'h.room_id')->where('r.room_code', 'r2')->pluck('h.user_id')->all())])
            ->assertStatus(409)->assertJson(['error' => 'duplicate', 'message' => 'That location already has a room with this name.']);
        $this->assertSame($count, DB::table('rooms')->count());

        $was = DB::table('rooms')->where('room_code', 'r1')->value('name');
        $this->postForm('admin.room-save', ['room_id' => 'r1', 'name' => $taken, 'venue' => $venue, 'room_type' => 'event'])
            ->assertStatus(409)->assertJson(['error' => 'duplicate']);
        $this->assertSame($was, DB::table('rooms')->where('room_code', 'r1')->value('name'));
    }

    public function test_an_email_already_taken_on_the_profile_page_changes_nothing(): void
    {
        $c = $this->makeAccount('customer', ['name' => 'Kept Name']);
        $this->signInAs($c, 'customer')->post(route('root.profile-save'), ['action' => 'details',
            'full_name' => 'Changed Name', 'email' => 'staff@gmail.com', 'current_password' => self::PASSWORD])
            ->assertStatus(409)->assertJson(['field' => 'email', 'message' => 'That email address is already used by another account.']);
        $this->assertSame('Kept Name', DB::table('customers')->where('user_id', $c->id)->value('full_name'));
    }

    /* ---- a row count turned into a refusal ---- */

    public function test_cancelling_the_same_request_twice_says_it_cannot_be_cancelled(): void
    {
        [$id, $ref] = $this->seedVenueBooking('r1', 'pending', 'locked');
        $this->asOwner()->post(route('customer.booking-cancel'), ['_token' => 'tok', 'booking' => $ref])->assertOk();
        $this->post(route('customer.booking-cancel'), ['_token' => 'tok', 'booking' => $ref])->assertStatus(409)
            ->assertJson(['message' => 'Only a request that has not been approved can be cancelled here. Please contact the office.']);
        $this->assertNoOneIsSigned();
    }

    public function test_a_first_password_is_set_once(): void
    {
        $user = DB::table('users')->where('email', 'customer@gmail.com')->first();
        DB::table('users')->where('id', $user->id)->update(['password_hash' => null]);
        try {
            $this->assertTrue(AccountQueries::setFirstPassword((int) $user->id, 'first'));
            $this->assertFalse(AccountQueries::setFirstPassword((int) $user->id, 'second'));
            $this->assertSame('first', DB::table('users')->where('id', $user->id)->value('password_hash'));
        } finally {
            DB::table('users')->where('id', $user->id)->update(['password_hash' => $user->password_hash, 'password_changed_at' => $user->password_changed_at]);
        }
    }
}
