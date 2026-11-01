<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* A suspended staff (or admin) account that types the RIGHT password is told it
   is suspended, like the disabled customer (Bug 2). A wrong password keeps the
   one generic message, so the page still reveals nothing about the account. */
class AdminSuspendedSignInTest extends TestCase
{
    use MakesAccounts;

    private const NOTICE = 'Your account has been suspended.';

    private function url(): string
    {
        return route('admin.admin-login');
    }

    public function test_right_password_on_a_suspended_staff_account_says_suspended(): void
    {
        $off = $this->makeAccount('staff', ['active' => 0]);
        $this->post($this->url(), ['email' => $off->email, 'password' => self::PASSWORD, 'department' => 1])
            ->assertOk()->assertSee(self::NOTICE)->assertDontSee('Invalid email or password.')
            ->assertSessionMissing('user_id');
    }

    public function test_the_suspended_notice_comes_before_the_venue_check(): void
    {
        $off = $this->makeAccount('staff', ['active' => 0]);   // venue 1; picks another card
        $other = (int) DB::table('venues')->where('venue_code', 'USEP-VENUES')->value('id');
        $this->post($this->url(), ['email' => $off->email, 'password' => self::PASSWORD, 'department' => $other])
            ->assertSee(self::NOTICE)->assertDontSee('This account belongs to');
    }

    public function test_right_password_on_a_suspended_admin_account_says_suspended(): void
    {
        $off = $this->makeAccount('admin', ['active' => 0, 'totp' => 'JBSWY3DPEHPK3PXP']);
        $this->post($this->url(), ['email' => $off->email, 'password' => self::PASSWORD, 'department' => 1])
            ->assertOk()->assertSee(self::NOTICE)->assertSessionMissing('tfa_pending');
    }

    public function test_wrong_password_on_a_suspended_account_keeps_the_generic_message(): void
    {
        $off = $this->makeAccount('staff', ['active' => 0]);
        $this->post($this->url(), ['email' => $off->email, 'password' => 'wrong-password', 'department' => 1])
            ->assertOk()->assertSee('Invalid email or password.')->assertDontSee(self::NOTICE);
    }
}
