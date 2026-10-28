<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/admin-login.php + admin/logout.php */
class AdminLoginTest extends TestCase
{
    use MakesAccounts;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function url(): string
    {
        return route('admin.admin-login');
    }

    public function test_guest_sees_the_staff_sign_in_form(): void
    {
        $this->get($this->url())->assertOk()
            ->assertSee('Staff sign in')->assertSee('name="_token"', false)
            ->assertSee(asset('assets/css/two-factor.css'), false)
            ->assertDontSee('Enter your code');
    }

    public function test_signed_in_staff_is_sent_to_the_dashboard(): void
    {
        $this->actingAsStaff()->get($this->url())->assertRedirect(route('admin.dashboard'));
    }

    public function test_notices_for_a_suspended_account_and_required_two_step(): void
    {
        $this->get($this->url() . '?suspended=1')->assertSee('Your account has been suspended.');
        $this->get($this->url() . '?tfa=required')->assertSee('Admin accounts now need two-step verification.');
        $this->withSession(['tfa_required_notice' => true])->get($this->url())
            ->assertSee('Admin accounts now need two-step verification.')->assertSessionMissing('tfa_required_notice');
    }

    public function test_bad_input_and_wrong_password_keep_the_form_with_the_old_messages(): void
    {
        $staff = $this->makeAccount('staff');
        $this->post($this->url(), ['email' => 'not-an-email', 'password' => 'x'])->assertOk()
            ->assertSee('Please enter a valid email address.');
        $this->post($this->url(), ['email' => $staff->email, 'password' => ''])->assertSee('Please enter your password.');
        $this->post($this->url(), ['email' => $staff->email, 'password' => 'wrong-password'])->assertSee('Invalid email or password.');
        $customer = $this->makeAccount('customer');
        $this->post($this->url(), ['email' => $customer->email, 'password' => self::PASSWORD])
            ->assertSee('Invalid email or password.');   // a customer is never signed in here
        $off = $this->makeAccount('staff', ['active' => 0]);
        $this->post($this->url(), ['email' => $off->email, 'password' => self::PASSWORD])->assertSee('Invalid email or password.');
        $this->assertNull(session('user_id'));
    }

    public function test_staff_sign_in_with_the_password_only_and_a_bcrypt_hash_is_upgraded(): void
    {
        $staff = $this->makeAccount('staff', ['hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD, 'department' => 'bahay-alumni'])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('user_id', $staff->id)->assertSessionHas('account_type', 'staff')
            ->assertSessionHas('tfa_passed', false)->assertSessionHas('department', 'bahay-alumni')
            ->assertSessionHas('user_email', $staff->email);
        $row = DB::table('users')->where('id', $staff->id)->first();
        $this->assertStringStartsWith('$argon2id$', $row->password_hash);
        $this->assertNotNull($row->last_login_at);
    }

    public function test_admin_with_two_step_on_needs_the_code(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD, 'department' => 'usep-venues'])
            ->assertRedirect($this->url())->assertSessionMissing('user_id');
        $code = $this->get($this->url())->assertOk()->assertSee('Enter your code')->assertSee('name="tfa_step" value="code"', false);
        $this->assertStringContainsString('no-store', $code->headers->get('Cache-Control'));   // the code step is never cached

        $this->post($this->url(), ['tfa_step' => 'code', 'code' => '000000'])->assertOk()
            ->assertSee('That code is not right. 4 more tries before a short lock.');
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => $this->totpNow(self::SECRET)])
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('user_id', $admin->id)->assertSessionHas('account_type', 'admin')
            ->assertSessionHas('tfa_passed', true)->assertSessionHas('department', 'usep-venues')
            ->assertSessionMissing('tfa_pending');
        $this->assertSame(0, (int) DB::table('users')->where('id', $admin->id)->value('totp_failed_attempts'));
    }

    public function test_five_wrong_codes_lock_for_ten_seconds_then_the_ladder_continues(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD]);
        for ($i = 1; $i <= 4; $i++) {
            $this->post($this->url(), ['tfa_step' => 'code', 'code' => '000000']);
        }
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => '000000'])
            ->assertSee('Too many wrong codes. Try again in', false)->assertSee('data-tfa-countdown="10"', false);
        $row = DB::table('users')->where('id', $admin->id)->first();
        $this->assertSame(1, (int) $row->totp_lock_level);
        // even the right code is refused while locked
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => $this->totpNow(self::SECRET)])
            ->assertSee('Too many wrong codes.')->assertSessionMissing('user_id');
        // a reload shows the running lock again
        $this->get($this->url())->assertSee('data-tfa-countdown=', false);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        legacy('two-factor');
        $codes = tfa_regenerate_codes(venusep_db(), $admin->id);
        $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD]);
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => strtolower($codes[0])])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('tfa_passed', true);
        $this->assertSame(9, (int) DB::table('user_recovery_codes')->where('user_id', $admin->id)->whereNull('used_at')->count());
    }

    public function test_admin_without_two_step_sets_up_a_phone_then_saves_the_codes(): void
    {
        $admin = $this->makeAccount('admin');
        $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect($this->url());
        $page = $this->get($this->url())->assertOk()->assertSee('Scan with your app')
            ->assertSee(asset('assets/js/vendor/qrcode-generator-1.4.4.js'), false);
        $secret = session('tfa_setup')['secret'];
        $this->assertSame($secret, $this->get($this->url())->viewData('tfaSecret'));   // the same key on every visit

        $this->post($this->url(), ['tfa_step' => 'enroll_confirm', 'code' => '000000'])
            ->assertSee('That code does not match.');
        $this->post($this->url(), ['tfa_step' => 'enroll_confirm', 'code' => $this->totpNow($secret)])
            ->assertRedirect($this->url());
        $this->assertSame($secret, DB::table('users')->where('id', $admin->id)->value('totp_secret'));
        $this->assertSame(10, DB::table('user_recovery_codes')->where('user_id', $admin->id)->count());

        $this->get($this->url())->assertSee('Save your recovery codes')->assertDontSee('Use a different account');
        $this->post($this->url(), ['tfa_step' => 'cancel'])->assertOk();   // no way out once 2FA is on
        $this->post($this->url(), ['tfa_step' => 'codes_done'])
            ->assertSee('Tick the box to confirm you saved your recovery codes.');
        $this->post($this->url(), ['tfa_step' => 'codes_done', 'saved' => '1'])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('tfa_passed', true)
            ->assertSessionHas('account_type', 'admin');
    }

    public function test_cancel_and_timed_out_steps(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => '123456'])
            ->assertSee('Your sign-in timed out. Enter your password again.');
        $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD]);
        $this->post($this->url(), ['tfa_step' => 'cancel'])->assertRedirect($this->url())->assertSessionMissing('tfa_pending');
        $this->get($this->url())->assertSee('Staff sign in');
    }

    public function test_logout_ends_the_session_on_a_plain_get(): void
    {
        $this->actingAsStaff()->get(route('admin.logout'))
            ->assertRedirect($this->url())->assertHeader('Pragma', 'no-cache')->assertSessionMissing('user_id');
    }
}
