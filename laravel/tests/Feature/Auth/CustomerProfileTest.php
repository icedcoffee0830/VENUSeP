<?php

namespace Tests\Feature\Auth;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* customer/customer-profile.php + the customer branches of profile-save.php */
class CustomerProfileTest extends TestCase
{
    use MakesAccounts;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    private function save(array $fields)
    {
        return $this->post(route('root.profile-save'), $fields);
    }

    public function test_guest_and_staff_are_sent_to_the_customer_login(): void
    {
        $this->get(route('customer.customer-profile'))->assertRedirect(route('customer.customer-login'));
        $this->actingAsStaff()->get(route('customer.customer-profile'))->assertRedirect(route('customer.customer-login'));
    }

    public function test_the_page_shows_the_session_customers_own_record(): void
    {
        $c = $this->makeAccount('customer', ['name' => 'Lara Santos Cruz']);
        $this->signInAs($c, 'customer')->get(route('customer.customer-profile'))->assertOk()
            ->assertSee('My Profile')->assertSee('Lara Santos Cruz')->assertSee($c->email)
            ->assertSee('>LS</div>', false)                               // initials
            ->assertSee('data-endpoint="' . route('root.profile-save') . '"', false)
            ->assertSee(route('customer.logout'), false)
            ->assertSee(asset('assets/js/two-factor.js'), false)
            ->assertSee('Account Security')->assertDontSee('Connect Google');   // Google not configured here
    }

    public function test_a_notice_from_google_is_shown_once(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->withSession(['profile_notice' => 'Google sign-in is not set up on this server yet.'])
            ->get(route('customer.customer-profile'))->assertSee('Google sign-in is not set up on this server yet.');
        $this->get(route('customer.customer-profile'))->assertDontSee('Google sign-in is not set up on this server yet.');
    }

    public function test_save_refuses_get_and_guests_with_the_old_json(): void
    {
        $this->get(route('root.profile-save'))->assertStatus(405)->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->save(['action' => 'details'])->assertStatus(401)->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->save(['action' => 'nope'])->assertStatus(400)->assertExactJson(['ok' => false, 'message' => 'Unknown action.']);
    }

    /* users.email is varchar(190): a longer address was a database error ("Something
       went wrong") instead of the field message registration gives for it. */
    public function test_an_email_longer_than_the_column_gets_the_field_message(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $long = str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.com';   // valid, 195 chars
        $this->save(['action' => 'details', 'full_name' => 'X', 'email' => $long])->assertStatus(400)
            ->assertJson(['field' => 'email', 'message' => 'Enter a valid email address.']);
        $this->assertSame($c->email, DB::table('users')->where('id', $c->id)->value('email'));
    }

    public function test_details_are_saved_and_the_session_follows(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $this->save(['action' => 'details', 'full_name' => '', 'email' => $c->email])->assertStatus(400)
            ->assertJson(['field' => 'full_name', 'message' => 'Enter your full name.']);
        $this->save(['action' => 'details', 'full_name' => 'X', 'email' => $c->email, 'contact_number' => '123'])->assertStatus(400)
            ->assertJson(['field' => 'contact_number']);
        $this->save(['action' => 'details', 'full_name' => 'X', 'email' => 'admin@gmail.com', 'current_password' => self::PASSWORD])->assertStatus(409)
            ->assertJson(['field' => 'email', 'message' => 'That email address is already used by another account.']);
        $this->save(['action' => 'details', 'full_name' => 'New Name', 'email' => strtoupper($c->email),
                     'contact_number' => '0917-123-4567', 'address' => 'Davao', 'university_id_no' => '2023-1'])
            ->assertOk()->assertExactJson(['ok' => true, 'message' => 'Your details were saved.'])
            ->assertSessionHas('customer_name', 'New Name')->assertSessionHas('customer_email', $c->email);
        $row = DB::table('customers')->where('user_id', $c->id)->first();
        $this->assertSame(['New Name', '09171234567', 'Davao', '2023-1'], [$row->full_name, $row->phone, $row->address, $row->university_id_no]);
    }

    public function test_password_change_needs_the_current_password_and_forgets_remembered_devices(): void
    {
        $c = $this->makeAccount('customer');
        DB::table('remember_tokens')->insert(['user_id' => $c->id, 'selector' => substr(bin2hex(random_bytes(12)), 0, 24),
            'validator_hash' => str_repeat('b', 64), 'expires_at' => now()->addDays(15)]);
        $this->signInAs($c, 'customer');
        $this->save(['action' => 'password', 'new_password' => 'short', 'confirm_new_password' => 'short'])->assertStatus(400)
            ->assertJson(['field' => 'new_password']);
        $this->save(['action' => 'password', 'current_password' => 'wrong', 'new_password' => 'new-pass-123', 'confirm_new_password' => 'new-pass-123'])
            ->assertStatus(401)->assertJson(['field' => 'current_password', 'message' => 'That is not your current password.']);
        $this->save(['action' => 'password', 'current_password' => self::PASSWORD, 'new_password' => 'new-pass-123', 'confirm_new_password' => 'new-pass-123'])
            ->assertOk()->assertExactJson(['ok' => true, 'message' => 'Your password was changed.']);
        $this->assertTrue(password_verify('new-pass-123', DB::table('users')->where('id', $c->id)->value('password_hash')));
        $this->assertSame(0, DB::table('remember_tokens')->where('user_id', $c->id)->count());
        // this session stays signed in (auth_at moved forward)
        $this->get(route('customer.customer-profile'))->assertOk();
    }

    public function test_a_google_only_account_needs_the_google_proof_for_a_first_password(): void
    {
        $c = $this->makeAccount('customer', ['password' => null, 'google_sub' => 'gsub-' . bin2hex(random_bytes(4))]);
        $this->signInAs($c, 'customer');
        $this->get(route('customer.customer-profile'))->assertSee('Confirm with Google')
            ->assertSee(route('customer.google-start', ['purpose' => 'reauth']), false);
        $f = ['action' => 'password', 'new_password' => 'first-pass-1', 'confirm_new_password' => 'first-pass-1'];
        $this->save($f)->assertStatus(403)->assertJson(['reauth' => true]);
        $this->withSession(['google_reauth' => ['user_id' => $c->id, 'at' => time()]])->save($f)->assertOk()
            ->assertJson(['message' => 'Your password was set. You can now sign in with your email and password, or with Google.']);
        $this->save(['action' => 'google_unlink', 'password' => 'first-pass-1'])->assertOk()
            ->assertJson(['message' => 'Google was disconnected. Sign in with your email and password from now on.']);
        $this->assertNull(DB::table('users')->where('id', $c->id)->value('google_sub'));
        $this->save(['action' => 'google_link_begin', 'password' => 'first-pass-1'])->assertStatus(409)
            ->assertJson(['message' => 'Google sign-in is not set up on this server yet.']);
    }

    public function test_turning_two_step_on_regenerating_codes_and_off(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $this->save(['action' => 'tfa_begin', 'password' => 'wrong'])->assertStatus(401)->assertJson(['field' => 'password']);
        $begin = $this->save(['action' => 'tfa_begin', 'password' => self::PASSWORD])->assertOk()->json();
        $this->assertStringStartsWith('otpauth://totp/', $begin['uri']);
        $this->assertSame($begin['secret'], $this->save(['action' => 'tfa_begin', 'password' => self::PASSWORD])->json('secret'));   // same key again
        $this->save(['action' => 'tfa_confirm', 'code' => '000000'])->assertStatus(400)->assertJson(['field' => 'code']);
        $on = $this->save(['action' => 'tfa_confirm', 'code' => $this->totpNow($begin['secret'])])->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Two-step verification is on.'])->json();
        $this->assertCount(10, $on['codes']);
        $this->assertSame($begin['secret'], DB::table('users')->where('id', $c->id)->value('totp_secret'));
        $this->get(route('customer.customer-profile'))->assertSee('10</strong> of 10 recovery codes left', false);

        $this->save(['action' => 'tfa_codes', 'code' => '000000'])->assertStatus(401)
            ->assertJson(['field' => 'code', 'message' => 'That code is not right. 4 more tries before a short lock.', 'seconds' => 0]);
        $this->save(['action' => 'tfa_codes', 'code' => $on['codes'][0]])->assertOk()->assertJson(['message' => 'New recovery codes made. The old ones no longer work.']);
        $this->save(['action' => 'tfa_disable', 'code' => $this->totpNow($begin['secret'], 1)])->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'Two-step verification is off.']);
        $this->assertNull(DB::table('users')->where('id', $c->id)->value('totp_secret'));
    }

    public function test_photo_upload_and_remove(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'av');
        file_put_contents($tmp, $png);
        $this->post(route('root.profile-save'), ['action' => 'photo'])->assertStatus(400)->assertJson(['message' => 'Choose a picture to upload.']);
        $this->post(route('root.profile-save'), ['action' => 'photo', 'photo' => new UploadedFile($tmp, 'me.png', 'image/png', null, true)])
            ->assertOk()->assertExactJson(['ok' => true, 'photo' => 'assets/img/avatars/c' . $c->id . '.png', 'message' => 'Photo updated.']);
        $this->assertFileExists(public_path('assets/img/avatars/c' . $c->id . '.png'));
        $this->get(route('customer.customer-profile'))->assertSee('src="' . asset('assets/img/avatars/c' . $c->id . '.png') . '"', false);
        $this->post(route('root.profile-save'), ['action' => 'photo', 'remove' => '1'])->assertOk()->assertJson(['photo' => null]);
        $this->assertFileDoesNotExist(public_path('assets/img/avatars/c' . $c->id . '.png'));
        $this->assertNull(DB::table('customers')->where('user_id', $c->id)->value('photo_path'));
    }
}
