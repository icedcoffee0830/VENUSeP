<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/discount-save.php (a JSON endpoint
   called from Venue Management and the admin profile page). */
class SettingSwitchesTest extends TestCase
{
    use AdminTestHelpers;

    protected function tearDown(): void
    {
        $this->restoreAdminPassword();
        parent::tearDown();
    }

    private function setting(string $key): string
    {
        return (string) DB::table('system_settings')->where('setting_key', $key)->value('setting_value');
    }

    /* ---- discount ---- */

    public function test_discount_guards_and_validation(): void
    {
        $this->get(route('admin.discount-save'))->assertStatus(405)->assertJson(['error' => 'method']);
        $this->post(route('admin.discount-save'))->assertStatus(401)
            ->assertExactJson(['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
        $this->actingAsAdmin()->withTestCsrf()->post(route('admin.discount-save'), ['_token' => 'bad'])
            ->assertStatus(400)->assertJson(['error' => 'csrf']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => '101', 'reason' => 'x'])
            ->assertStatus(400)->assertJson(['error' => 'bad_percent', 'message' => 'Enter a whole number from 0 to 100.']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => '15', 'reason' => ' '])
            ->assertStatus(400)->assertJson(['error' => 'no_reason']);
    }

    public function test_admin_changes_the_discount_and_history_is_written(): void   // admin-only since 2026-10-29 (BugFixesTest)
    {
        $old = $this->setting('discount_percent');
        $new = $old === '15' ? '16' : '15';
        $before = DB::table('system_settings_history')->where('setting_key', 'discount_percent')->count();

        $this->actingAsAdmin()->withTestCsrf()
            ->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'test run'])
            ->assertOk()->assertExactJson(['ok' => true, 'percent' => (int) $new, 'previous' => (int) $old]);
        $this->assertSame($new, $this->setting('discount_percent'));
        $this->assertSame($before + 1, DB::table('system_settings_history')->where('setting_key', 'discount_percent')->count());

        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'again'])
            ->assertStatus(400)->assertJson(['error' => 'unchanged']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $old, 'reason' => 'restore'])
            ->assertOk();
    }

    /* A suspended or demoted admin keeps 'admin' in a session that is still open;
       the endpoint has no staff middleware to notice, so it must re-read the account. */
    public function test_discount_refuses_a_suspended_or_demoted_admin_session(): void
    {
        $old = $this->setting('discount_percent');
        $new = $old === '15' ? '16' : '15';
        $this->actingAsAdmin()->withTestCsrf();
        try {
            DB::table('users')->where('email', 'admin@gmail.com')->update(['is_active' => 0]);
            // a suspended account's session is ended on its next request (staff_session_refresh)
            $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'suspended'])
                ->assertStatus(401)->assertJson(['ok' => false, 'error' => 'not_logged_in']);
            DB::table('users')->where('email', 'admin@gmail.com')->update(['is_active' => 1, 'account_type' => 'staff', 'password_changed_at' => now()]);
            $this->actingAsAdmin()->withTestCsrf();
            $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'demoted'])
                ->assertStatus(403)->assertJson(['ok' => false, 'error' => 'not_admin']);
        } finally {
            DB::table('users')->where('email', 'admin@gmail.com')->update(['is_active' => 1, 'account_type' => 'admin']);
            DB::table('system_settings')->where('setting_key', 'discount_percent')->update(['setting_value' => $old]);
        }
        $this->assertSame($old, $this->setting('discount_percent'));
    }
}
