<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* A NEW email on the profile needs the current password, like a new password
   (both portals, profile-save action=details). Saving the same email (any case)
   does not. A Google-only account shows a fresh Google confirmation instead. */
class ProfileEmailChangeTest extends TestCase
{
    use MakesAccounts;

    private function save(array $fields)
    {
        return $this->post(route('root.profile-save'), ['action' => 'details', 'full_name' => 'Same Name'] + $fields);
    }

    private function newEmail(): string
    {
        return 'lvtest-new-' . bin2hex(random_bytes(4)) . '@test.local';
    }

    public function test_a_new_email_without_or_with_a_wrong_password_is_refused(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $new = $this->newEmail();
        $this->save(['email' => $new])->assertStatus(401)
            ->assertJson(['ok' => false, 'field' => 'current_password', 'message' => 'Enter your current password to change your email.']);
        $this->save(['email' => $new, 'current_password' => 'wrong-password'])->assertStatus(401)
            ->assertJson(['field' => 'current_password', 'message' => 'That is not your current password.']);
        $this->assertSame($c->email, DB::table('users')->where('id', $c->id)->value('email'));
    }

    public function test_a_new_email_with_the_current_password_is_saved(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $new = $this->newEmail();
        $this->save(['email' => $new, 'current_password' => self::PASSWORD])->assertOk()->assertJson(['ok' => true])
            ->assertSessionHas('customer_email', $new);
        $this->assertSame($new, DB::table('users')->where('id', $c->id)->value('email'));
    }

    public function test_the_same_email_needs_no_password(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer');
        $this->save(['email' => strtoupper($c->email)])->assertOk()->assertJson(['ok' => true]);
    }

    public function test_staff_side_needs_the_password_too(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff');
        $new = $this->newEmail();
        $this->save(['email' => $new])->assertStatus(401)->assertJson(['field' => 'current_password']);
        $this->save(['email' => $new, 'current_password' => self::PASSWORD])->assertOk();
        $this->assertSame($new, DB::table('users')->where('id', $staff->id)->value('email'));
    }

    public function test_a_google_only_account_needs_a_fresh_google_confirmation(): void
    {
        $c = $this->makeAccount('customer', ['password' => null, 'google_sub' => 'g-' . bin2hex(random_bytes(6))]);
        $this->signInAs($c, 'customer');
        $new = $this->newEmail();
        $this->save(['email' => $new])->assertStatus(403)->assertJson(['field' => 'email', 'reauth' => true]);
        $this->assertSame($c->email, DB::table('users')->where('id', $c->id)->value('email'));

        $this->withSession(['google_reauth' => ['user_id' => $c->id, 'at' => time()]]);   // just came back from Google
        $this->save(['email' => $new])->assertOk();
        $this->assertSame($new, DB::table('users')->where('id', $c->id)->value('email'));
    }

    public function test_the_profile_pages_carry_the_hidden_password_field(): void
    {
        $c = $this->makeAccount('customer');
        $this->signInAs($c, 'customer')->get(route('customer.customer-profile'))
            ->assertSee('id="emailPasswordGroup" hidden', false)->assertSee('id="emailPassword" name="current_password"', false);
        $this->flushSession();
        $this->actingAsStaff()->get(route('admin.profile'))
            ->assertSee('id="profile-email-pw-field" hidden', false);
    }
}
