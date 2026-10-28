<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/discount-save.php and admin/demo-mode-switch.php (JSON endpoints
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
        $this->actingAsStaff()->withTestCsrf()->post(route('admin.discount-save'), ['_token' => 'bad'])
            ->assertStatus(400)->assertJson(['error' => 'csrf']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => '101', 'reason' => 'x'])
            ->assertStatus(400)->assertJson(['error' => 'bad_percent', 'message' => 'Enter a whole number from 0 to 100.']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => '15', 'reason' => ' '])
            ->assertStatus(400)->assertJson(['error' => 'no_reason']);
    }

    public function test_staff_changes_the_discount_and_history_is_written(): void
    {
        $old = $this->setting('discount_percent');
        $new = $old === '15' ? '16' : '15';
        $before = DB::table('system_settings_history')->where('setting_key', 'discount_percent')->count();

        $this->actingAsStaff()->withTestCsrf()
            ->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'test run'])
            ->assertOk()->assertExactJson(['ok' => true, 'percent' => (int) $new, 'previous' => (int) $old]);
        $this->assertSame($new, $this->setting('discount_percent'));
        $this->assertSame($before + 1, DB::table('system_settings_history')->where('setting_key', 'discount_percent')->count());

        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $new, 'reason' => 'again'])
            ->assertStatus(400)->assertJson(['error' => 'unchanged']);
        $this->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => $old, 'reason' => 'restore'])
            ->assertOk();
    }

    /* ---- demo mode ---- */

    public function test_demo_mode_refuses_staff_and_guests(): void
    {
        $this->post(route('admin.demo-mode-switch'))->assertStatus(403)->assertJson(['error' => 'not_admin']);
        $this->actingAsStaff()->withTestCsrf()
            ->post(route('admin.demo-mode-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => 'x'])
            ->assertStatus(403)->assertJson(['message' => 'Only an administrator can change demo mode.']);
    }

    public function test_demo_mode_wrong_password_counts(): void
    {
        $this->useKnownAdminPassword();
        $this->actingAsAdmin()->withTestCsrf()
            ->post(route('admin.demo-mode-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => 'nope'])
            ->assertStatus(401)->assertJson(['error' => 'wrong_password', 'attemptsLeft' => 4]);
        $this->assertSame(1, (int) DB::table('users')->where('email', 'admin@gmail.com')->value('reauth_failed_attempts'));
    }

    public function test_demo_mode_on_then_off_clears_the_overlay(): void
    {
        $this->useKnownAdminPassword();
        $this->actingAsAdmin()->withTestCsrf()->withSession(['demo' => ['bookings' => ['x']]])
            ->post(route('admin.demo-mode-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => $this->pw])
            ->assertOk()->assertJson(['ok' => true, 'enabled' => true])->assertSessionMissing('demo');
        $this->assertSame('1', $this->setting('demo_mode'));

        $this->post(route('admin.demo-mode-switch'), ['_token' => 'test-csrf-token', 'enable' => '1', 'password' => $this->pw])
            ->assertStatus(400)->assertJson(['message' => 'Demo mode is already on.']);
        $this->post(route('admin.demo-mode-switch'), ['_token' => 'test-csrf-token', 'enable' => '0', 'password' => $this->pw])
            ->assertOk()->assertJson(['enabled' => false]);
        $this->assertSame('0', $this->setting('demo_mode'));
    }
}
