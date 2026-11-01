<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/customer-search.php + admin/customer-verify.php — JSON for the counter JS. */
class CounterCustomerTest extends TestCase
{
    public function test_search_is_staff_only_json(): void
    {
        $this->getJson(route('admin.customer-search', ['q' => 'Maria']))
            ->assertStatus(403)->assertExactJson(['ok' => false, 'results' => [], 'message' => 'Staff only.']);
        $this->actingAsCustomer()->getJson(route('admin.customer-search', ['q' => 'Maria']))->assertStatus(403);
    }

    public function test_search_needs_three_characters_and_masks_the_phone(): void
    {
        $this->actingAsStaff();
        $this->get(route('admin.customer-search', ['q' => 'Ma']))->assertOk()->assertExactJson(['ok' => true, 'results' => []]);
        $res = $this->get(route('admin.customer-search', ['q' => 'Maria Santos']));
        $res->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertExactJson(['ok' => true, 'results' => [
                ['id' => 3, 'name' => 'Maria Santos', 'email' => 'msantos@usep.edu.ph', 'phone' => '0917•••0188'],
            ]]);
        // walk-ins (no user account) never come back
        $this->get(route('admin.customer-search', ['q' => 'Carmen']))->assertExactJson(['ok' => true, 'results' => []]);
    }

    /* "%%%" or "___" are three characters, but as LIKE wildcards they match every
       customer — the three-character minimum must not be walkable that way. */
    public function test_search_treats_like_wildcards_as_plain_characters(): void
    {
        $this->actingAsStaff();
        foreach (['%%%', '___', '%_%', 'a%a', '\\\\\\'] as $q) {
            $this->get(route('admin.customer-search', ['q' => $q]))->assertOk()
                ->assertExactJson(['ok' => true, 'results' => []]);
        }
        $this->get(route('admin.customer-search', ['q' => 'msantos@usep']))->assertOk()
            ->assertJsonPath('results.0.name', 'Maria Santos');
    }

    public function test_a_suspended_staff_session_cannot_search_or_verify(): void
    {
        $staffId = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        DB::table('users')->where('id', $staffId)->update(['is_active' => 0]);
        try {
            $this->actingAsStaff()->withSession(['_token' => 'tok123'])
                ->get(route('admin.customer-search', ['q' => 'Maria']))
                ->assertStatus(403)->assertExactJson(['ok' => false, 'results' => [], 'message' => 'Staff only.']);
            $this->actingAsStaff()->withSession(['_token' => 'tok123'])
                ->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 3, 'password' => 'x'])
                ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Staff only.']);
            $this->assertNull(session('counter_proof'));
        } finally {
            DB::table('users')->where('id', $staffId)->update(['is_active' => 1]);
        }
    }

    public function test_verify_rejects_get_guests_and_a_bad_token(): void
    {
        $this->get(route('admin.customer-verify'))->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->post(route('admin.customer-verify'), ['customer_id' => 3, 'password' => 'x'])
            ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Staff only.']);
        $this->actingAsStaff()->post(route('admin.customer-verify'), ['csrf' => 'stale', 'customer_id' => 3, 'password' => 'x'])
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
    }

    public function test_verify_checks_the_password_and_leaves_a_proof_in_the_staff_session(): void
    {
        $old = DB::table('users')->where('id', 4)->value('password_hash');
        DB::table('users')->where('id', 4)->update(['password_hash' => password_hash('Counter#2026', PASSWORD_ARGON2ID)]);
        try {
            $this->actingAsStaff()->withSession(['_token' => 'tok123']);
            $this->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 3, 'password' => 'wrong'])
                ->assertStatus(401)->assertJson(['ok' => false, 'message' => 'That password did not match. Try again, or book them as a walk-in with no account.']);
            $this->assertNull(session('counter_proof'));
            $this->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 3, 'password' => ''])
                ->assertStatus(400)->assertJson(['message' => 'Pick the account and ask the customer to type their password.']);
            $this->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 999999, 'password' => 'x'])
                ->assertStatus(404)->assertJson(['message' => 'That account no longer exists.']);

            $this->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 3, 'password' => 'Counter#2026'])
                ->assertOk()->assertExactJson(['ok' => true, 'message' => 'Identity confirmed.']);
            $this->assertEqualsWithDelta(time(), session('counter_proof')[3], 5);
            $this->assertSame('staff', session('account_type'));          // the staff member stays signed in
        } finally {
            DB::table('users')->where('id', 4)->update(['password_hash' => $old]);
        }
    }

    /* Five wrong passwords lock THIS customer's account on the users.reauth_*
       ladder (10s, then 30s, ...); while locked even the right password is
       refused, with a message that tells staff how long to wait. */
    public function test_verify_locks_the_account_after_five_wrong_passwords(): void
    {
        $old = DB::table('users')->where('id', 4)->value('password_hash');
        DB::table('users')->where('id', 4)->update(['password_hash' => password_hash('Counter#2026', PASSWORD_ARGON2ID)]);
        $try = fn (string $pw) => $this->post(route('admin.customer-verify'), ['csrf' => 'tok123', 'customer_id' => 3, 'password' => $pw]);
        try {
            $this->actingAsStaff()->withSession(['_token' => 'tok123']);
            for ($i = 1; $i <= 4; $i++) {
                $try('wrong')->assertStatus(401)->assertJson(['ok' => false, 'attemptsLeft' => 5 - $i]);
            }
            $try('wrong')->assertStatus(423)->assertExactJson(['ok' => false, 'seconds' => 10,
                'message' => 'Too many wrong passwords for this account, so it is locked for 10 seconds. Ask the customer to try again after that, or book them as a walk-in with no account.']);
            $try('Counter#2026')->assertStatus(423)->assertJsonPath('ok', false);
            $this->assertNull(session('counter_proof'));
            $this->assertSame(1, (int) DB::table('users')->where('id', 4)->value('reauth_lock_level'));

            // the lock runs out; five more wrong = the next, longer lock
            DB::table('users')->where('id', 4)->update(['reauth_locked_until' => DB::raw('NOW() - INTERVAL 1 SECOND')]);
            for ($i = 1; $i <= 4; $i++) {
                $try('wrong')->assertStatus(401);
            }
            $try('wrong')->assertStatus(423)->assertJsonPath('seconds', 30)
                ->assertJsonPath('message', 'Too many wrong passwords for this account, so it is locked for 30 seconds. Ask the customer to try again after that, or book them as a walk-in with no account.');

            // after it runs out, the right password works and the ladder starts over
            DB::table('users')->where('id', 4)->update(['reauth_locked_until' => DB::raw('NOW() - INTERVAL 1 SECOND')]);
            $try('wrong')->assertStatus(401);
            $try('Counter#2026')->assertOk()->assertExactJson(['ok' => true, 'message' => 'Identity confirmed.']);
            $row = DB::table('users')->where('id', 4)->first();
            $this->assertSame([0, 0, null], [(int) $row->reauth_failed_attempts, (int) $row->reauth_lock_level, $row->reauth_locked_until]);

            // a 5-minute lock is told in minutes
            DB::table('users')->where('id', 4)->update(['reauth_lock_level' => 3, 'reauth_failed_attempts' => 4]);
            $try('wrong')->assertStatus(423)->assertJsonPath('seconds', 300)
                ->assertJsonPath('message', 'Too many wrong passwords for this account, so it is locked for 5 minutes. Ask the customer to try again after that, or book them as a walk-in with no account.');
        } finally {
            DB::table('users')->where('id', 4)->update(['password_hash' => $old,
                'reauth_failed_attempts' => 0, 'reauth_lock_level' => 0, 'reauth_locked_until' => null]);
        }
    }

    public function test_verify_refuses_a_suspended_account(): void
    {
        DB::table('users')->where('id', 4)->update(['is_active' => 0]);
        try {
            $this->actingAsStaff()->withSession(['_token' => 'tok123'])
                ->withHeader('X-CSRF-TOKEN', 'tok123')
                ->post(route('admin.customer-verify'), ['customer_id' => 3, 'password' => 'x'])
                ->assertStatus(403)->assertJson(['message' => 'That account is suspended and cannot be booked for.']);
        } finally {
            DB::table('users')->where('id', 4)->update(['is_active' => 1]);
        }
    }
}
