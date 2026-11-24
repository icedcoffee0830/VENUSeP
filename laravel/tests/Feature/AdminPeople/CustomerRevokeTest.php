<?php

namespace Tests\Feature\AdminPeople;

use App\Queries\AccountQueries;
use App\Queries\SchemaQueries;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminBookings\SeedsBookings;
use Tests\Feature\Auth\MakesAccounts;
use Tests\TestCase;

/* Revoke a customer account (Aron 2026-11-24, migration 21): the super admin only, with their
   own password. Closed for good: signed out, never reactivated, pending requests cancelled,
   data kept, the email never registers again. The tests that need users.revoked_at are
   skipped until migration 21 is on this database. */
class CustomerRevokeTest extends TestCase
{
    use MakesAccounts { tearDown as removeAccounts; }
    use SeedsBookings;

    private ?object $victim = null;
    private array $bookings = [];

    /* SeedsBookings books for this test's own customer */
    private function customerId(): int
    {
        return (int) $this->victim->customer_id;
    }

    protected function tearDown(): void
    {
        DB::table('bookings')->whereIn('id', $this->bookings)->delete();
        if ($this->victim !== null && SchemaQueries::has('activity_log')) {
            DB::table('activity_log')->whereIn('subject', ['Customer: Revoke Tester', 'Sign-in: ' . $this->victim->email])->delete();
        }
        $this->removeAccounts();
    }

    private function needsMigration21(): void
    {
        if (!SchemaQueries::has('users', 'revoked_at')) {
            $this->markTestSkipped('run migration 21 (venusep_migration_21.sql) on the test database');
        }
    }

    private function save(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')->post(route('admin.customer-save'), $data);
    }

    private function revoke(string $password)
    {
        return $this->save(['action' => 'revoke', 'user_id' => $this->victim->id, 'password' => $password]);
    }

    public function test_the_super_admin_sees_revoke_and_its_confirmation_window(): void
    {
        $this->actingAsAdmin()->get(route('admin.customer-management'))->assertOk()
            ->assertSee('btn-cust-revoke', false)
            ->assertSee('Revoke this customer account?')
            ->assertSee('The account is closed for good and can never be reactivated. All of the customer’s pending requests are cancelled. Their bookings, payments and history are kept, and their email address cannot be used to register again.')
            ->assertSee("label: 'Your password', inputType: 'password'", false)
            ->assertSee("okLabel: 'Revoke account', cancelLabel: 'Cancel'", false)
            ->assertSee('<option>Revoked</option>', false);
    }

    public function test_only_the_super_admin_may_revoke(): void
    {
        $this->victim = $this->makeAccount('customer', ['name' => 'Revoke Tester']);
        $this->actingAsAdmin('francishubero@gmail.com');
        $this->revoke(self::PASSWORD)->assertStatus(403)->assertJson(['ok' => false, 'message' => 'Only the super admin can revoke a customer account.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->victim->id)->value('is_active'));
    }

    public function test_before_migration_21_revoke_says_so_and_changes_nothing(): void
    {
        if (SchemaQueries::has('users', 'revoked_at')) {
            $this->markTestSkipped('migration 21 is in: covered by the tests below');
        }
        $this->victim = $this->makeAccount('customer', ['name' => 'Revoke Tester']);
        $this->knowPasswordOf('admin@gmail.com');
        $this->actingAsAdmin();
        $this->revoke(self::PASSWORD)->assertStatus(503)->assertJson(['ok' => false])->assertSee('migration 21');
        $this->assertSame(1, (int) DB::table('users')->where('id', $this->victim->id)->value('is_active'));
        $this->assertSame(0, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('reauth_failed_attempts'));   // no try spent
        $this->assertFalse(AccountQueries::isRevoked($this->victim->email));
    }

    public function test_revoke_needs_your_own_password_then_closes_the_account_and_cancels_pending_requests(): void
    {
        $this->needsMigration21();
        $this->victim = $this->makeAccount('customer', ['name' => 'Revoke Tester']);
        [$pending] = $this->seedVenueBooking();
        [$approved] = $this->seedVenueBooking('r1', 'approved', 'await_gcash');
        $this->bookings = [$pending, $approved];
        $this->knowPasswordOf('admin@gmail.com');
        $super = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        $this->actingAsAdmin();

        $this->revoke('')->assertStatus(400)->assertJson(['error' => 'no_password']);
        $this->revoke('not-mine')->assertStatus(401)->assertJson(['error' => 'wrong_password', 'attemptsLeft' => 4]);
        $this->assertNull(DB::table('users')->where('id', $this->victim->id)->value('revoked_at'));

        $this->revoke(self::PASSWORD)->assertOk()->assertJson(['ok' => true, 'cancelled' => 1]);
        $u = DB::table('users')->where('id', $this->victim->id)->first();
        $this->assertSame([0, $super], [(int) $u->is_active, (int) $u->revoked_by_user_id]);
        $this->assertNotNull($u->revoked_at);
        $this->assertSame('cancelled', $this->booking($pending)->reservation_status);
        $this->assertSame('approved', $this->booking($approved)->reservation_status);   // only pending requests
        $this->assertSame('Request cancelled: the customer account was revoked.',
            DB::table('booking_timeline')->where('booking_id', $pending)->orderByDesc('id')->value('note'));
        $this->assertNotNull(DB::table('venue_booking_slots')->where('booking_id', $pending)->value('released_at'));   // the date is free again
        $this->assertSame(1, DB::table('activity_log')->where('action', 'account_revoked')->where('subject', 'Customer: Revoke Tester')->where('user_id', $super)->count());

        /* never again: no reactivating, no suspending, no second revoke, no email fix */
        $this->save(['action' => 'set_active', 'user_id' => $this->victim->id, 'active' => 1])->assertStatus(409)->assertSee('never be reactivated');
        $this->revoke(self::PASSWORD)->assertStatus(409);
        $this->assertSame(0, (int) DB::table('users')->where('id', $this->victim->id)->value('is_active'));
        $this->get(route('admin.customer-management'))->assertOk()->assertSee('"status":"Revoked"', false);
    }

    public function test_a_revoked_customer_is_signed_out_and_cannot_sign_in_register_or_use_google(): void
    {
        $this->needsMigration21();
        $this->victim = $this->makeAccount('customer', ['name' => 'Revoke Tester', 'google_sub' => 'g-revoked-' . bin2hex(random_bytes(3))]);
        $sub = DB::table('users')->where('id', $this->victim->id)->value('google_sub');
        $admin = (int) DB::table('users')->where('email', 'admin@gmail.com')->value('id');
        DB::table('users')->where('id', $this->victim->id)->update(['is_active' => 0, 'revoked_at' => now(), 'revoked_by_user_id' => $admin]);

        /* signed in when it happened: out on the next click, told why */
        $this->signInAs($this->victim, 'customer')->get(route('customer.booking-history'))->assertRedirect();
        $this->get(route('customer.customer-login'))->assertSee('This account was closed and can&rsquo;t be used again.', false);

        $this->flushSession();
        $this->post(route('customer.customer-login'), ['email' => $this->victim->email, 'password' => self::PASSWORD])
            ->assertSee('This account was closed and can&rsquo;t be used again.', false)->assertDontSee('This account has been disabled');
        $this->post(route('customer.customer-login'), ['email' => $this->victim->email, 'password' => 'wrong-one'])
            ->assertSee('Invalid email or password.');   // a stranger learns nothing

        $this->post(route('root.register-submit'), ['full_name' => 'Again', 'email' => strtoupper($this->victim->email), 'password' => 'Long-enough-1',
            'confirm_password' => 'Long-enough-1', 'terms' => '1'])
            ->assertStatus(409)->assertJson(['ok' => false, 'field' => 'email', 'message' => 'That email address can’t be used to register.']);

        legacy('google-auth');
        $this->assertSame(['action' => 'revoked'], google_resolve($sub, 'someone-else@test.local'));
        $this->assertSame(['action' => 'revoked'], google_resolve('another-google-id', $this->victim->email));
    }
}
