<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/forgot-password.php + customer/reset-password.php (password-reset.php, #23) */
class PasswordResetTest extends TestCase
{
    use MakesAccounts;

    private function makeLink(int $userId, string $token, string $expires = '+30 minutes'): int
    {
        return DB::table('password_resets')->insertGetId([
            'user_id' => $userId, 'token_hash' => hash('sha256', $token),
            'expires_at' => date('Y-m-d H:i:s', strtotime($expires)),
        ]);
    }

    public function test_forgot_page_and_validation(): void
    {
        $this->get(route('customer.forgot-password'))->assertOk()->assertSee('Reset your password')
            ->assertSee('name="_token"', false)->assertSee('Back to login');
        $this->post(route('customer.forgot-password'), ['email' => 'nope'])->assertOk()
            ->assertSee('Please enter a valid email address.')->assertSee('value="nope"', false);
    }

    public function test_a_request_makes_one_link_scrubs_the_stored_email_and_replies_the_same(): void
    {
        /* mail_send() may error_log() (mailer.php looks for the logo under legacy/assets/,
           which the Laravel copy does not have): keep that out of PHPUnit's output. */
        ini_set('error_log', storage_path('logs/auth-tests-error.log'));
        $c = $this->makeAccount('customer');
        $this->post(route('customer.forgot-password'), ['email' => strtoupper($c->email)])
            ->assertRedirect(route('customer.forgot-password'));
        $this->get(route('customer.forgot-password'))->assertSee('Check your email')->assertSee('If that email belongs to a VENUSeP account');
        $this->get(route('customer.forgot-password'))->assertSee('Reset your password');   // shown once

        $rows = DB::table('password_resets')->where('user_id', $c->id)->get();
        $this->assertCount(1, $rows);
        $mail = DB::table('email_outbox')->where('requested_by_user_id', $c->id)->first();
        $this->assertSame('password_reset', $mail->kind);
        $this->assertContains($mail->status, ['sent', 'failed']);   // log transport: nothing leaves the machine either way
        $this->assertStringContainsString('[link removed after sending]', $mail->body_text);
        $this->assertStringNotContainsString('reset-password?token=', $mail->body_text);

        // within 5 minutes: no second link, same reply
        $this->post(route('customer.forgot-password'), ['email' => $c->email])->assertRedirect(route('customer.forgot-password'));
        $this->assertSame(1, DB::table('password_resets')->where('user_id', $c->id)->count());

        // an address with no customer account: the same reply, nothing made
        $staff = $this->makeAccount('staff');
        $this->post(route('customer.forgot-password'), ['email' => $staff->email])->assertRedirect(route('customer.forgot-password'));
        $this->assertSame(0, DB::table('password_resets')->where('user_id', $staff->id)->count());
    }

    public function test_the_link_leaves_the_url_then_sets_a_new_password(): void
    {
        $c = $this->makeAccount('customer');
        DB::table('remember_tokens')->insert(['user_id' => $c->id, 'selector' => substr(bin2hex(random_bytes(12)), 0, 24),
            'validator_hash' => str_repeat('a', 64), 'expires_at' => now()->addDays(15)]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->makeLink($c->id, $token);

        $r = $this->get(route('customer.reset-password', ['token' => $token]))->assertRedirect(route('customer.reset-password'));
        $this->assertSame('no-referrer', $r->headers->get('Referrer-Policy'));
        $this->get(route('customer.reset-password'))->assertOk()->assertSee('Choose a new password')->assertSee('For ' . $c->email);

        $this->post(route('customer.reset-password'), ['new_password' => 'short', 'confirm_password' => 'short'])
            ->assertSee('Use a password of at least 8 characters.');
        $this->post(route('customer.reset-password'), ['new_password' => 'brand-new-123', 'confirm_password' => 'other-123'])
            ->assertSee('The two passwords do not match.');
        $this->post(route('customer.reset-password'), ['new_password' => 'brand-new-123', 'confirm_password' => 'brand-new-123'])
            ->assertRedirect(route('customer.customer-login'))->assertSessionHas('login_notice');

        $u = DB::table('users')->where('id', $c->id)->first();
        $this->assertTrue(password_verify('brand-new-123', $u->password_hash));
        $this->assertStringStartsWith('$argon2id$', $u->password_hash);
        $this->assertNotNull($u->password_changed_at);
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $c->id)->count());   // every device forgotten

        // the link is spent
        $this->get(route('customer.reset-password', ['token' => $token]));
        $this->get(route('customer.reset-password'))->assertSee('This link can');
    }

    public function test_bad_and_expired_links_are_refused(): void
    {
        $c = $this->makeAccount('customer');
        $this->makeLink($c->id, 'expired-token-000000000000000000000000000000', '-1 minute');
        $this->get(route('customer.reset-password', ['token' => 'expired-token-000000000000000000000000000000']))
            ->assertRedirect(route('customer.reset-password'));
        $this->get(route('customer.reset-password'))->assertSee('This link can');
        $this->get(route('customer.reset-password', ['token' => "x' OR '1'='1"]));
        $this->get(route('customer.reset-password'))->assertSee('This link can');
    }
}
