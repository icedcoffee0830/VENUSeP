<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/* Forgot password for staff, normal admins and the super admin (DB-DECISIONS 28):
   admin/forgot-password + admin/reset-password, the customer flow's rules
   (legacy password-reset.php) on the staff side. */
class StaffPasswordResetTest extends TestCase
{
    use MakesAccounts;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        ini_set('error_log', storage_path('logs/auth-tests-error.log'));   // mail_send() may error_log() (see PasswordResetTest)
    }

    private function token(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public function test_the_sign_in_page_links_to_the_staff_forgot_page(): void
    {
        $this->get(route('admin.admin-login'))->assertOk()
            ->assertSee('href="' . route('admin.forgot-password') . '"', false)->assertDontSee('href="#" class="auth-link"', false);
        $this->get(route('admin.forgot-password'))->assertOk()->assertSee('Reset your password')
            ->assertSee('action="' . route('admin.forgot-password') . '"', false)
            ->assertSee('href="' . route('admin.admin-login') . '"', false)
            ->assertDontSee(route('customer.customer-login'));
    }

    public static function accounts(): array
    {
        return [
            'staff'        => ['staff', []],
            'normal admin' => ['admin', ['totp' => self::SECRET, 'group' => 'cedu']],
        ];
    }

    #[DataProvider('accounts')]
    public function test_a_request_sends_one_scrubbed_link_to_the_staff_page(string $type, array $o): void
    {
        $acct = $this->makeAccount($type, $o);
        $this->post(route('admin.forgot-password'), ['email' => $acct->email])->assertRedirect(route('admin.forgot-password'));
        $this->get(route('admin.forgot-password'))->assertSee('Email isn’t set up')->assertSee(PR_NOT_SET_UP_REPLY);   // no mail settings in tests

        $this->assertSame(1, DB::table('password_resets')->where('user_id', $acct->id)->count());
        $mail = DB::table('email_outbox')->where('requested_by_user_id', $acct->id)->first();
        $this->assertSame('password_reset', $mail->kind);
        $this->assertStringContainsString('[link removed after sending]', $mail->body_text);
        $this->assertStringNotContainsString('reset-password?token=', $mail->body_text . $mail->body_html);

        // within 5 minutes: no second link, same reply
        $this->post(route('admin.forgot-password'), ['email' => $acct->email])->assertRedirect(route('admin.forgot-password'));
        $this->assertSame(1, DB::table('password_resets')->where('user_id', $acct->id)->count());
    }

    public function test_the_emailed_link_uses_the_apps_url_and_the_staff_page(): void
    {
        legacy('db', 'password-reset');
        $acct = $this->makeAccount('staff');
        config(['app.url' => 'https://venusep.example.test']);
        pr_request(venusep_db(), $acct->email, '127.0.0.1', null, true);
        /* the stored row is scrubbed; the log transport's copy (mail-log/<id>.eml) is what was "sent" */
        $id = DB::table('email_outbox')->where('requested_by_user_id', $acct->id)->value('id');
        $eml = doc_ensure_dir('mail-log') . '/' . $id . '.eml';
        $this->assertFileExists($eml);
        $sent = quoted_printable_decode(file_get_contents($eml));
        @unlink($eml);
        $this->assertMatchesRegularExpression('~https://venusep\.example\.test/admin/reset-password\?token=[A-Za-z0-9_-]{43}~', $sent);
    }

    public function test_the_super_admin_gets_a_link_too(): void
    {
        $sa = DB::table('users')->where('is_super_admin', 1)->first();
        DB::table('password_resets')->where('user_id', $sa->id)->delete();
        try {
            $this->post(route('admin.forgot-password'), ['email' => $sa->email])->assertRedirect(route('admin.forgot-password'));
            $this->assertSame(1, DB::table('password_resets')->where('user_id', $sa->id)->whereNull('used_at')->count());
        } finally {
            DB::table('password_resets')->where('user_id', $sa->id)->delete();
            DB::table('email_outbox')->where('requested_by_user_id', $sa->id)->where('kind', 'password_reset')->delete();
        }
    }

    public static function noLink(): array
    {
        return ['suspended staff' => ['suspended'], 'a former super admin' => ['handed_over'], 'a customer' => ['customer']];
    }

    #[DataProvider('noLink')]
    public function test_no_link_for_a_suspended_or_handed_over_account_or_a_customer(string $who): void
    {
        $acct = match ($who) {
            'suspended'   => $this->makeAccount('staff', ['active' => 0]),
            'handed_over' => $this->makeAccount('admin', ['totp' => self::SECRET]),   // even left active: handed_over_at alone refuses
            'customer'    => $this->makeAccount('customer'),
        };
        if ($who === 'handed_over') {
            DB::table('users')->where('id', $acct->id)->update(['handed_over_at' => now()]);
        }
        $this->post(route('admin.forgot-password'), ['email' => $acct->email])->assertRedirect(route('admin.forgot-password'));
        $this->get(route('admin.forgot-password'))->assertSee(PR_NOT_SET_UP_REPLY);   // the same reply
        $this->assertSame(0, DB::table('password_resets')->where('user_id', $acct->id)->count());
    }

    public function test_a_staff_link_never_works_on_the_customer_page_and_the_other_way_round(): void
    {
        $staff = $this->makeAccount('staff');
        $customer = $this->makeAccount('customer');
        $st = $this->token();
        $ct = $this->token();
        DB::table('password_resets')->insert([
            ['user_id' => $staff->id, 'token_hash' => hash('sha256', $st), 'expires_at' => now()->addMinutes(30)],
            ['user_id' => $customer->id, 'token_hash' => hash('sha256', $ct), 'expires_at' => now()->addMinutes(30)],
        ]);
        $this->get(route('customer.reset-password', ['token' => $st]));
        $this->get(route('customer.reset-password'))->assertSee('This link can');
        $this->get(route('admin.reset-password', ['token' => $ct]));
        $this->get(route('admin.reset-password'))->assertSee('This link can');
    }

    public function test_an_admin_resets_signs_out_everywhere_and_is_still_asked_for_the_code(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET, 'group' => 'bahay_alumni']);
        DB::table('users')->where('id', $admin->id)->update(['password_changed_at' => now()->subDay()]);
        $token = $this->token();
        DB::table('password_resets')->insert(['user_id' => $admin->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30)]);

        $r = $this->get(route('admin.reset-password', ['token' => $token]))->assertRedirect(route('admin.reset-password'));
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        $this->get(route('admin.reset-password'))->assertOk()->assertSee('For ' . $admin->email)
            ->assertSee('action="' . route('admin.reset-password') . '"', false);
        $this->post(route('admin.reset-password'), ['new_password' => 'brand-new-123', 'confirm_password' => 'brand-new-123'])
            ->assertRedirect(route('admin.admin-login'))->assertSessionMissing('user_id');   // not signed in
        $this->get(route('admin.admin-login'))->assertSee('Your password was changed. Log in with your new password.');

        $u = DB::table('users')->where('id', $admin->id)->first();
        $this->assertTrue(password_verify('brand-new-123', $u->password_hash));
        $this->assertGreaterThan(now()->subMinute()->timestamp, strtotime($u->password_changed_at));
        $this->get(route('admin.reset-password', ['token' => $token]));
        $this->get(route('admin.reset-password'))->assertSee('This link can');   // once only

        // the new password, then still the two-step code
        $this->post(route('admin.admin-login'), ['email' => $admin->email, 'password' => 'brand-new-123', 'as_admin' => 1])
            ->assertRedirect(route('admin.admin-login'))->assertSessionMissing('user_id');
        $this->get(route('admin.admin-login'))->assertSee('Enter your code');
    }

    public function test_a_session_signed_in_before_the_reset_is_ended(): void
    {
        $staff = $this->makeAccount('staff');
        DB::table('users')->where('id', $staff->id)->update(['password_changed_at' => now()->subDay()]);
        $this->withSession(['user_id' => $staff->id, 'account_type' => 'staff', 'auth_at' => time() - 3600]);
        $this->get(route('admin.dashboard'))->assertOk();

        legacy('db', 'password-reset');
        $token = $this->token();
        $id = DB::table('password_resets')->insertGetId(['user_id' => $staff->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(30)]);
        $this->assertTrue(pr_complete(venusep_db(), $id, $staff->id, 'brand-new-123'));

        $this->get(route('admin.dashboard'))->assertRedirect();
        $this->assertNull(session('user_id'));
    }
}
