<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* adminb's report: register-submit trusted the session's account_type, so an
   admin suspended or demoted while signed in could still create staff accounts.
   staff_session_refresh() (EnforceTwoFactor, every request) re-reads the account
   before the endpoint runs: these pin that it covers register-submit. */
class RegisterSubmitStaleAdminTest extends TestCase
{
    use MakesAccounts;

    private function createStaff(object $admin): \Illuminate\Testing\TestResponse
    {
        $email = 'lvtest-made-' . bin2hex(random_bytes(4)) . '@test.local';
        $r = $this->signInAs($admin, 'admin')->post(route('root.register-submit'), [
            'kind' => 'staff', 'venue_id' => '1', 'full_name' => 'Made By Stale Admin', 'email' => $email,
            'contact_number' => '0917 123 4567', 'password' => 'long-enough-1', 'confirm_password' => 'long-enough-1', 'terms' => '1',
        ]);
        $this->assertFalse(DB::table('users')->where('email', $email)->exists(), 'a staff account was created');

        return $r;
    }

    public function test_a_suspended_admins_open_session_cannot_create_staff(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        DB::table('users')->where('id', $admin->id)->update(['is_active' => 0]);
        $this->createStaff($admin)->assertStatus(403)
            ->assertJson(['message' => 'Only an administrator can create a staff or admin account.']);
        $this->assertNull(session('user_id'));   // and the session is over
    }

    public function test_a_demoted_admins_open_session_cannot_create_staff(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        DB::table('users')->where('id', $admin->id)->update(['account_type' => 'staff']);
        DB::table('staff')->insert(['user_id' => $admin->id, 'venue_id' => 1, 'full_name' => 'Demoted']);
        $this->createStaff($admin)->assertStatus(403);
        $this->assertSame('staff', session('account_type'));
    }
}
