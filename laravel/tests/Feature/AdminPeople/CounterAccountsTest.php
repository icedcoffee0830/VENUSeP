<?php

namespace Tests\Feature\AdminPeople;

use App\Http\Controllers\Admin\CounterCustomerController;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* DB-DECISIONS 28, the counter's customers: the search finds any account of every office
   (exact email, exact mobile, or a name of 3+ letters; masked; nothing about bookings);
   an account made at the counter (a taken email refused, a used mobile a warning); the
   one correction of a customer's details (a counter account's wrong email); and
   "ID checked" for an account with no password. */
class CounterAccountsTest extends TestCase
{
    private int $lastUser = 0;
    private int $lastOutbox = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lastUser = (int) DB::table('users')->max('id');
        $this->lastOutbox = (int) DB::table('email_outbox')->max('id');
    }

    /* Only what this test made: accounts and emails newer than setUp. */
    protected function tearDown(): void
    {
        $users = DB::table('users')->where('id', '>', $this->lastUser)->where('account_type', 'customer')->pluck('id')->all() ?: [0];
        $customers = DB::table('customers')->whereIn('user_id', $users)->pluck('id')->all() ?: [0];
        foreach (DB::table('email_outbox')->where('id', '>', $this->lastOutbox)->pluck('id') as $id) {
            @unlink(getenv('VENUSEP_DOC_ROOT') . '/mail-log/' . $id . '.eml');
        }
        DB::table('email_outbox')->where('id', '>', $this->lastOutbox)->delete();
        DB::table('bookings')->whereIn('customer_id', $customers)->delete();
        DB::table('customers')->whereIn('id', $customers)->delete();
        DB::table('users')->whereIn('id', $users)->delete();
        parent::tearDown();
    }

    /* A customer account of our own: $user overrides users columns, $customer customers columns. */
    private function account(string $email, array $user = [], array $customer = []): int
    {
        $userId = DB::table('users')->insertGetId($user + ['email' => $email, 'username' => 'u' . random_int(100000, 999999),
            'password_hash' => null, 'account_type' => 'customer']);
        return DB::table('customers')->insertGetId($customer + ['user_id' => $userId, 'full_name' => 'Counter Test Person', 'phone' => '09170001111']);
    }

    private function search(string $q)
    {
        return $this->actingAsStaff()->get(route('admin.customer-search', ['q' => $q]))->assertOk();
    }

    private function createAccount(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->post(route('admin.customer-create'), $data + ['csrf' => 'tok', 'room' => 'r1',
            'full_name' => 'Lorna Bautista', 'email' => 'lorna.counter@gmail.com', 'phone' => '0917 000 2222']);
    }

    private function fixEmail(int $customerId, string $email, string $reason = 'Typo at the counter: one letter off')
    {
        return $this->withSession(['_token' => 'tok'])->post(route('admin.customer-save'),
            ['csrf' => 'tok', 'action' => 'fix_email', 'customer_id' => $customerId, 'email' => $email, 'reason' => $reason]);
    }

    public function test_search_finds_exact_email_exact_mobile_or_a_name_masked_and_nothing_else(): void
    {
        $maria = ['id' => 3, 'name' => 'Maria Santos', 'email' => 'ms•••@usep.edu.ph', 'phone' => '0917•••0188', 'usep' => true, 'noPassword' => false];
        $this->search('Maria')->assertExactJson(['ok' => true, 'results' => [$maria]]);
        $this->search('MSANTOS@usep.edu.ph')->assertExactJson(['ok' => true, 'results' => [$maria]]);
        $this->search('0917 555 0188')->assertExactJson(['ok' => true, 'results' => [$maria]]);
        $this->search('+63 917 555 0188')->assertExactJson(['ok' => true, 'results' => [$maria]]);
        /* part of an email or a number, two letters, digits alone: nothing */
        foreach (['msantos@usep', 'msantos', '0917555', 'Ma', 'M 1 a', '123'] as $q) {
            $this->search($q)->assertExactJson(['ok' => true, 'results' => []]);
        }
    }

    public function test_search_covers_every_office_shows_ten_at_most(): void
    {
        /* an account another office's counter made, never booked anywhere: staff of Bahay Alumni find it */
        $id = $this->account('hostel.made@gmail.com', [], ['full_name' => 'Quintessa Hostelmade', 'counter_venue_id' => 3]);
        $this->search('Quintessa')->assertJsonPath('results.0.id', $id)->assertJsonPath('results.0.noPassword', true);
        foreach (range(1, 11) as $n) {
            $this->account("tenlimit$n@example.com", [], ['full_name' => "Tenlimit Person $n"]);
        }
        $this->assertCount(10, $this->search('Tenlimit')->json('results'));
    }

    public function test_making_an_account_refuses_a_taken_email_and_warns_on_a_used_mobile(): void
    {
        $this->actingAsStaff();
        $users = DB::table('users')->count();
        $this->createAccount(['email' => 'MSantos@usep.edu.ph'])->assertStatus(409)
            ->assertJson(['ok' => false, 'error' => 'email_taken', 'message' => CounterCustomerController::ALREADY_HAS_ACCOUNT]);
        $this->createAccount(['email' => 'staff@gmail.com'])->assertStatus(409);           // any account's email
        $this->createAccount(['room' => 'r5'])->assertStatus(403);                         // another office's counter
        $this->createAccount(['phone' => '0917'])->assertStatus(400)->assertJsonPath('field', 'phone');

        /* check=1: the confirm step, nothing saved; a mobile Maria uses is a warning only */
        $this->createAccount(['check' => '1', 'phone' => '09175550188'])->assertOk()
            ->assertJson(['ok' => true, 'email' => 'lorna.counter@gmail.com'])->assertJsonPath('warning', fn ($w) => str_contains($w, 'already uses this mobile'));
        $this->createAccount(['check' => '1'])->assertOk()->assertJsonPath('warning', '');
        $this->assertSame($users, DB::table('users')->count());

        $res = $this->createAccount(['phone' => '09175550188'])->assertOk()->assertJsonPath('phone', '0917•••0188');
        $row = DB::table('customers as c')->join('users as u', 'u.id', '=', 'c.user_id')->where('c.id', $res->json('customerId'))->first();
        $this->assertSame(['lorna.counter@gmail.com', null, 'customer', 1, '09175550188'],
            [$row->email, $row->password_hash, $row->account_type, (int) $row->counter_venue_id, $row->phone]);
        $mail = DB::table('email_outbox')->where('to_email', 'lorna.counter@gmail.com')->first();
        $this->assertSame(['counter_welcome', 'sent'], [$mail->kind, $mail->status]);
        $this->assertStringContainsString('/customer/forgot-password', $mail->body_text);
        $this->assertStringContainsString('Bahay Alumni counter', $mail->body_text);
        $this->createAccount([])->assertStatus(409);                                        // made once
    }

    public function test_only_a_normal_admin_of_the_creating_group_fixes_the_email_of_an_unused_counter_account(): void
    {
        $hostel = $this->account('wrong.adress@gmial.com', [], ['counter_venue_id' => 3]);
        DB::table('password_resets')->insert(['user_id' => DB::table('customers')->where('id', $hostel)->value('user_id'),
            'token_hash' => str_repeat('a', 64), 'expires_at' => now()->addMinutes(30)]);

        $this->actingAsAdmin()->fixEmail($hostel, 'right.address@gmail.com')->assertStatus(403);                   // the super admin
        $this->actingAsAdmin('francishubero@gmail.com')->fixEmail($hostel, 'right.address@gmail.com')->assertStatus(403);   // another group
        $this->actingAsStaff()->fixEmail($hostel, 'right.address@gmail.com')->assertStatus(403);                  // staff

        $this->actingAsAdmin('cedu.admin@gmail.com');   /* USeP CEDU: the Hostel counter's account */
        $this->fixEmail($hostel, 'right.address@gmail.com', '')->assertStatus(400);
        $this->fixEmail($hostel, 'msantos@usep.edu.ph')->assertStatus(409);
        $this->fixEmail($hostel, 'not an email')->assertStatus(400);
        $this->fixEmail($hostel, 'right.address@gmail.com')->assertOk()->assertJson(['ok' => true]);

        $userId = DB::table('customers')->where('id', $hostel)->value('user_id');
        $this->assertSame('right.address@gmail.com', DB::table('users')->where('id', $userId)->value('email'));
        $fix = DB::table('customer_email_fixes')->where('customer_id', $hostel)->first();
        $this->assertSame(['wrong.adress@gmial.com', 'right.address@gmail.com', 'Typo at the counter: one letter off'],
            [$fix->old_email, $fix->new_email, $fix->reason]);
        $this->assertEquals(DB::table('users')->where('email', 'cedu.admin@gmail.com')->value('id'), $fix->fixed_by_user_id);
        $this->assertSame(0, DB::table('password_resets')->where('user_id', $userId)->whereNull('used_at')->count());
        $this->assertSame('counter_welcome', DB::table('email_outbox')->where('to_email', 'right.address@gmail.com')->value('kind'));

        /* the customers page offers it to the CEDU admin only */
        $rows = collect($this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.customer-management'))->viewData('cmRows'));
        $this->assertTrue($rows->firstWhere('id', $hostel)['fixEmail']);
        $this->assertNull(collect($this->actingAsAdmin('francishubero@gmail.com')->get(route('admin.customer-management'))->viewData('cmRows'))->firstWhere('id', $hostel));
        $this->assertFalse(collect($this->actingAsAdmin()->get(route('admin.customer-management'))->viewData('cmRows'))->firstWhere('id', $hostel)['fixEmail']);
    }

    public function test_no_fix_once_signed_into_for_google_or_for_a_self_made_account(): void
    {
        $signedIn = $this->account('signed.in@gmail.com', ['last_login_at' => now()], ['counter_venue_id' => 2]);
        $google = $this->account('google.made@gmail.com', ['google_sub' => 'g-' . random_int(1, 1e9)], ['counter_venue_id' => 2]);
        $self = $this->account('self.made@gmail.com');
        $this->actingAsAdmin('cedu.admin@gmail.com');
        foreach ([$signedIn, $google, $self] as $id) {
            $this->fixEmail($id, 'other' . $id . '@gmail.com')->assertStatus(403)->assertJsonPath('error', 'forbidden');
        }
        $this->assertSame(0, DB::table('customer_email_fixes')->whereIn('customer_id', [$signedIn, $google, $self])->count());
    }

    /* A Google-only account (no password): verify says so, and the booking needs the
       "ID checked" tick instead; who ticked it is on the booking's History. */
    public function test_an_account_with_no_password_is_booked_on_id_checked(): void
    {
        $id = $this->account('google.only@gmail.com', ['google_sub' => 'g-' . random_int(1, 1e9)]);
        $this->actingAsStaff()->withSession(['_token' => 'tok'])
            ->post(route('admin.customer-verify'), ['csrf' => 'tok', 'customer_id' => $id, 'password' => 'anything'])
            ->assertStatus(409)->assertExactJson(['ok' => false, 'noPassword' => true, 'message' => CounterCustomerController::NO_PASSWORD]);

        $day = date('Y-m-d', strtotime('+' . random_int(400, 1400) . ' days'));
        $book = fn (array $extra) => $this->actingAsStaff()->post(route('admin.booking-create'), $extra + [
            'type' => 'venue', 'room' => 'r1', 'event_name' => 'ID checked test', 'date_start' => $day, 'date_end' => $day,
            'attendees' => '30', 'times' => json_encode([$day => ['start' => '08:00', 'end' => '10:00']]), 'method' => 'cash',
            'agree_no_refund' => '1', 'booker_mode' => 'account', 'id_checked' => '1', 'usep_id' => '0']);

        $book(['customer_id' => $id])->assertStatus(403)->assertJsonPath('message', fn ($m) => str_contains($m, 'ID checked'));
        /* a customer WITH a password still needs the password, tick or not */
        $book(['customer_id' => 1, 'id_identity' => '1'])->assertStatus(403)
            ->assertJsonPath('message', 'Ask the customer to confirm their password again before booking.');

        $res = $book(['customer_id' => $id, 'id_identity' => '1'])->assertOk();
        $line = DB::table('booking_timeline')->where('booking_id', $res->json('bookingId'))->where('action_code', 'counter_identity_id_checked')->first();
        $this->assertNotNull($line);
        $this->assertEquals(DB::table('users')->where('email', 'staff@gmail.com')->value('id'), $line->performed_by_user_id);
        $this->assertNotNull($line->occurred_at);
        $this->assertSame(0, DB::table('booking_timeline')->where('booking_id', $res->json('bookingId'))->where('action_code', 'counter_identity_confirmed')->count());
    }

    /* A new account at the counter: the same phone rule as a walk-in (Aron 2026-11-05). */
    public function test_a_new_counter_account_takes_only_09_or_plus_63_mobiles(): void
    {
        $this->actingAsStaff();
        foreach (['O917 000 2222', '0917-000-2222', '63 917 000 2222'] as $bad) {
            $this->createAccount(['check' => '1', 'phone' => $bad])->assertStatus(400)
                ->assertExactJson(['ok' => false, 'field' => 'phone', 'message' => 'Enter a mobile number as 09XX XXX XXXX or +63 9XX XXX XXXX.']);
        }
        $this->createAccount(['check' => '1', 'phone' => '+63 917 000 2222'])->assertOk();
        $this->assertFalse(DB::table('users')->where('email', 'lorna.counter@gmail.com')->exists());   // check=1 makes nothing
    }

    /* The page's own check matches: inputmode, pattern and message on both counter phone inputs. */
    public function test_the_counter_phone_inputs_say_the_rule_on_screen(): void
    {
        $this->actingAsStaff()->get(route('customer.room-reservation', ['room' => 'r1', 'counter' => 1]))->assertOk()
            ->assertSee("const PH_MOBILE_PATTERN = '[ ]*(0|[+]63)[ ]*9([ ]*[0-9]){9}[ ]*';", false)
            ->assertSee('<input id="bkPhone" type="tel" inputmode="tel" pattern="${PH_MOBILE_PATTERN}"', false)
            ->assertSee('<input id="bkNewPhone" type="tel" inputmode="tel" pattern="${PH_MOBILE_PATTERN}"', false)
            ->assertSee('Enter a mobile number as 09XX XXX XXXX or +63 9XX XXX XXXX.', false);
    }
}
