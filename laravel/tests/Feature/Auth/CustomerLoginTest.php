<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/customer-login.php + customer/logout.php (+ "Remember me", #23) */
class CustomerLoginTest extends TestCase
{
    use MakesAccounts;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function url(): string
    {
        return route('customer.customer-login');
    }

    public function test_guest_sees_the_form_without_the_google_button(): void
    {
        $this->get($this->url())->assertOk()->assertSee('Welcome back!')
            ->assertSee(route('customer.forgot-password'), false)->assertSee(route('customer.customer-register'), false)
            ->assertSee(asset('assets/css/customer-auth.css'), false)
            ->assertDontSee('Continue with Google');   // no google-config.php in the Laravel copy
    }

    public function test_signed_in_customer_goes_to_the_booking_page(): void
    {
        $this->actingAsCustomer()->get($this->url())->assertRedirect(route('customer.venues'));
    }

    public function test_the_one_time_notice_is_shown_once(): void
    {
        $this->withSession(['login_notice' => 'Your password was changed. Log in with your new password.'])
            ->get($this->url())->assertSee('Your password was changed.');
        $this->get($this->url())->assertDontSee('Your password was changed.');
    }

    public function test_wrong_input_gives_the_old_messages(): void
    {
        $c = $this->makeAccount('customer');
        $this->post($this->url(), ['email' => 'nope', 'password' => 'x'])->assertSee('Please enter a valid email address.');
        $this->post($this->url(), ['email' => $c->email, 'password' => ''])->assertSee('Please enter your password.');
        $this->post($this->url(), ['email' => $c->email, 'password' => 'wrong-one'])->assertSee('Invalid email or password.');
        $staff = $this->makeAccount('staff');
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD])->assertSee('Invalid email or password.');
        $g = $this->makeAccount('customer', ['password' => null, 'google_sub' => 'g-' . $c->id]);
        $this->post($this->url(), ['email' => $g->email, 'password' => 'anything'])
            ->assertSee('This account uses Google sign-in, which is not set up on this server yet.');
    }

    public function test_correct_password_signs_in_with_the_session_contract(): void
    {
        $c = $this->makeAccount('customer', ['hash' => password_hash(self::PASSWORD, PASSWORD_BCRYPT), 'name' => 'Ana Cruz']);
        $this->post($this->url(), ['email' => $c->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('customer.venues'))
            ->assertSessionHas('user_id', $c->id)->assertSessionHas('customer_id', $c->customer_id)
            ->assertSessionHas('account_type', 'customer')->assertSessionHas('customer_name', 'Ana Cruz')
            ->assertSessionHas('customer_email', $c->email)->assertSessionHas('auth_at')
            ->assertCookieMissing('venusep_remember');
        $this->assertStringStartsWith('$argon2id$', DB::table('users')->where('id', $c->id)->value('password_hash'));
    }

    public function test_customer_with_two_step_on_is_asked_for_the_code(): void
    {
        $c = $this->makeAccount('customer', ['totp' => self::SECRET]);
        $this->post($this->url(), ['email' => $c->email, 'password' => self::PASSWORD])
            ->assertRedirect($this->url())->assertSessionMissing('user_id');
        $this->get($this->url())->assertSee('Enter your code')->assertSee('One more <br />step.', false);
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => '000000'])->assertSee('That code is not right.');
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => $this->totpNow(self::SECRET)])
            ->assertRedirect(route('customer.venues'))->assertSessionHas('user_id', $c->id);
    }

    public function test_remember_me_cookie_signs_the_device_back_in_and_logout_forgets_it(): void
    {
        $c = $this->makeAccount('customer');
        $login = $this->post($this->url(), ['email' => $c->email, 'password' => self::PASSWORD, 'remember_me' => '1'])
            ->assertRedirect(route('customer.venues'))->assertCookie('venusep_remember', null, false);
        $cookie = $login->getCookie('venusep_remember', false);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{24}:[A-Za-z0-9_-]{43}$/', $cookie->getValue());
        $this->assertSame(1, DB::table('remember_tokens')->where('user_id', $c->id)->count());

        // the session is gone, the cookie is not: signed straight back in, with a rotated cookie
        $this->flushSession();
        $back = $this->withUnencryptedCookie('venusep_remember', $cookie->getValue())->get($this->url())
            ->assertRedirect(route('customer.venues'))->assertSessionHas('user_id', $c->id)->assertSessionHas('remembered', true);
        $next = $back->getCookie('venusep_remember', false)->getValue();
        $this->assertNotSame($cookie->getValue(), $next);
        $this->assertSame(explode(':', $cookie->getValue())[0], explode(':', $next)[0]);

        // Log Out forgets this device and clears the cookie
        $out = $this->withUnencryptedCookie('venusep_remember', $next)->get(route('customer.logout'))
            ->assertRedirect($this->url())->assertSessionMissing('user_id');
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $c->id)->count());
        $this->assertLessThan(time(), $out->getCookie('venusep_remember', false)->getExpiresTime());
    }

    public function test_remember_me_rides_through_the_code_step(): void
    {
        $c = $this->makeAccount('customer', ['totp' => self::SECRET]);
        $this->post($this->url(), ['email' => $c->email, 'password' => self::PASSWORD, 'remember_me' => '1'])
            ->assertCookieMissing('venusep_remember');
        $this->post($this->url(), ['tfa_step' => 'code', 'code' => $this->totpNow(self::SECRET)])
            ->assertRedirect(route('customer.venues'))->assertCookie('venusep_remember', null, false);
    }

    public function test_google_finish_your_account_screen(): void
    {
        $email = 'lvtest-g' . bin2hex(random_bytes(4)) . '@test.local';
        $signup = ['sub' => 'sub-' . $email, 'email' => $email, 'name' => 'Gina Google', 'at' => time(), 'remember' => false];
        $this->withSession(['google_signup' => $signup])->get($this->url())->assertOk()
            ->assertSee('Finish creating your account')->assertSee($email)->assertSee('value="Gina Google"', false);

        $this->post($this->url(), ['google_step' => 'create', 'full_name' => '', 'contact_number' => '123'])
            ->assertSee('Enter your full name.')->assertSee('Enter a mobile number in the form 09XX XXX XXXX.')
            ->assertSee('You must agree to the Terms and Conditions.');

        $this->post($this->url(), ['google_step' => 'create', 'full_name' => 'Gina G.', 'contact_number' => '09171234567', 'terms' => '1'])
            ->assertRedirect(route('customer.venues'))->assertSessionHas('account_type', 'customer');
        $u = DB::table('users')->where('email', $email)->first();
        $this->madeUserIds[] = $u->id;
        $this->assertNull($u->password_hash);
        $this->assertSame('sub-' . $email, $u->google_sub);
        $this->assertNotNull($u->email_verified_at);
        $this->assertSame('Gina G.', DB::table('customers')->where('user_id', $u->id)->value('full_name'));
    }

    public function test_google_sign_up_cancel_and_timeout(): void
    {
        $this->withSession(['google_signup' => ['sub' => 's', 'email' => 'x@test.local', 'name' => 'X', 'at' => time()]])
            ->post($this->url(), ['google_step' => 'cancel'])->assertRedirect($this->url())->assertSessionMissing('google_signup');
        $this->post($this->url(), ['google_step' => 'create'])->assertSee('That sign-up timed out. Choose Continue with Google again.');
    }
}
