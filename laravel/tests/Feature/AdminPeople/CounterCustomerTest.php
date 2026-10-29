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
