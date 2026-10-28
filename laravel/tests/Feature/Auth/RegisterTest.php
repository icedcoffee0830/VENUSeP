<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/customer-register.php + register-submit.php */
class RegisterTest extends TestCase
{
    use MakesAccounts;

    private const TOKEN = 'test-csrf-token-0123456789';

    private function submit(array $fields, array $session = [])
    {
        return $this->withSession($session + ['_token' => self::TOKEN])
            ->post(route('root.register-submit'), $fields + ['csrf' => self::TOKEN]);
    }

    private function customerFields(string $email): array
    {
        return ['full_name' => 'New Person', 'email' => $email, 'contact_number' => '0917 123 4567',
                'password' => 'long-enough-1', 'confirm_password' => 'long-enough-1', 'terms' => '1'];
    }

    private function forget(string $email): void
    {
        $id = DB::table('users')->where('email', $email)->value('id');
        if ($id) {
            $this->madeUserIds[] = (int) $id;
        }
    }

    public function test_register_page_renders_with_the_endpoint_and_token(): void
    {
        $this->withSession(['_token' => self::TOKEN])->get(route('customer.customer-register'))->assertOk()
            ->assertSee('Create your account')
            ->assertSee(json_encode(route('root.register-submit')), false)
            ->assertSee('const CSRF = "' . self::TOKEN . '"', false)
            ->assertSee('data-redirect="' . route('customer.customer-login') . '"', false)
            ->assertDontSee('Sign up with Google');
    }

    public function test_a_customer_account_is_created_with_an_argon2id_hash(): void
    {
        $email = 'lvtest-reg' . bin2hex(random_bytes(4)) . '@test.local';
        $this->submit($this->customerFields(strtoupper($email)))->assertOk()
            ->assertExactJson(['ok' => true, 'kind' => 'customer', 'message' => 'Account created. You can sign in now.'])
            ->assertSessionMissing('user_id');   // not signed in automatically
        $this->forget($email);
        $u = DB::table('users')->where('email', $email)->first();
        $this->assertSame('customer', $u->account_type);
        $this->assertStringStartsWith('$argon2id$', $u->password_hash);
        $c = DB::table('customers')->where('user_id', $u->id)->first();
        $this->assertSame('New Person', $c->full_name);
        $this->assertSame('09171234567', $c->phone);

        $this->submit($this->customerFields($email))->assertStatus(409)
            ->assertJson(['ok' => false, 'field' => 'email', 'message' => 'That email address is already registered. Try logging in instead.']);
    }

    public function test_validation_and_csrf_replies_keep_the_old_json(): void
    {
        $f = $this->customerFields('lvtest-x@test.local');
        $this->submit(['full_name' => ''] + $f)->assertStatus(400)->assertJson(['field' => 'full_name', 'message' => 'Enter your full name.']);
        $this->submit(['email' => 'bad'] + $f)->assertStatus(400)->assertJson(['field' => 'email']);
        $this->submit(['password' => 'short', 'confirm_password' => 'short'] + $f)->assertStatus(400)->assertJson(['field' => 'password']);
        $this->submit(['confirm_password' => 'other-one-1'] + $f)->assertStatus(400)->assertJson(['field' => 'confirm_password']);
        $this->submit(['terms' => ''] + $f)->assertStatus(400)->assertJson(['field' => 'terms', 'message' => 'Please accept the terms to continue.']);
        $this->submit(['contact_number' => '12345'] + $f)->assertStatus(400)
            ->assertJson(['field' => 'contact_number', 'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
        $this->withSession(['_token' => self::TOKEN])->post(route('root.register-submit'), ['csrf' => 'wrong'] + $f)
            ->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        $this->get(route('root.register-submit'))->assertStatus(405)->assertHeader('Allow', 'POST')
            ->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->assertNull(DB::table('users')->where('email', 'lvtest-x@test.local')->value('id'));
    }

    public function test_only_an_admin_creates_staff_and_staff_need_a_venue(): void
    {
        $email = 'lvtest-st' . bin2hex(random_bytes(4)) . '@test.local';
        $f = ['kind' => 'staff'] + $this->customerFields($email);
        $this->submit($f)->assertStatus(403)
            ->assertJson(['message' => 'Only an administrator can create a staff or admin account.']);
        $admin = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        $as = ['user_id' => $admin->id, 'account_type' => 'admin', 'tfa_passed' => true];
        $this->submit($f, $as)->assertStatus(400)->assertJson(['field' => 'venue_id', 'message' => 'Choose the venue this staff member will handle.']);
        $this->submit(['venue_id' => 'x'] + $f, $as)->assertStatus(400)->assertJson(['message' => 'Select a valid venue.']);
        $this->submit(['venue_id' => '999999'] + $f, $as)->assertStatus(400)->assertJson(['message' => 'That venue does not exist.']);
        $this->submit(['venue_id' => '1'] + $f, $as)->assertOk()->assertJson(['ok' => true, 'kind' => 'staff', 'message' => 'Staff account created.']);
        $this->forget($email);
        $u = DB::table('users')->where('email', $email)->first();
        $this->assertSame('staff', $u->account_type);
        $this->assertSame(1, (int) DB::table('staff')->where('user_id', $u->id)->value('venue_id'));
    }

    public function test_a_new_admin_needs_the_creators_password_with_the_reauth_ladder(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        $as = ['user_id' => $admin->id, 'account_type' => 'admin', 'tfa_passed' => true];
        $email = 'lvtest-ad' . bin2hex(random_bytes(4)) . '@test.local';
        $f = ['kind' => 'admin'] + $this->customerFields($email);
        $this->submit($f, $as)->assertStatus(400)->assertJson(['error' => 'no_password']);
        $this->submit(['creator_password' => 'wrong'] + $f, $as)->assertStatus(401)
            ->assertJson(['error' => 'wrong_password', 'attemptsLeft' => 4,
                'message' => 'That is not your password. 4 attempt(s) left before a temporary lock.']);
        for ($i = 0; $i < 3; $i++) {
            $this->submit(['creator_password' => 'wrong'] + $f, $as);
        }
        $this->submit(['creator_password' => 'wrong'] + $f, $as)->assertStatus(423)
            ->assertJson(['error' => 'locked', 'seconds' => 10, 'message' => 'Too many wrong passwords. Try again in 10 seconds.']);
        $this->submit(['creator_password' => self::PASSWORD] + $f, $as)->assertStatus(423);   // locked even for the right one
        DB::table('users')->where('id', $admin->id)->update(['reauth_locked_until' => null]);
        $this->submit(['creator_password' => self::PASSWORD] + $f, $as)->assertOk()
            ->assertJson(['ok' => true, 'kind' => 'admin']);
        $this->forget($email);
        $u = DB::table('users')->where('email', $email)->first();
        $this->assertSame('admin', $u->account_type);
        $this->assertNull(DB::table('staff')->where('user_id', $u->id)->value('venue_id'));
        $this->assertSame(0, (int) DB::table('users')->where('id', $admin->id)->value('reauth_lock_level'));
    }
}
