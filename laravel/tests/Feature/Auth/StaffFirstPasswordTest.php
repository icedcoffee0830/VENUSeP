<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Aron 2026-10-31: the admin creates staff accounts and types their password, so
   a staff member must set their own before anything else. "Own password" =
   users.password_changed_at is set (the profile form, or a reset link). */
class StaffFirstPasswordTest extends TestCase
{
    use MakesAccounts;

    private function save(array $data)
    {
        return $this->withSession(['_token' => 'tok'])->withHeader('X-CSRF-TOKEN', 'tok')
            ->post(route('root.profile-save'), $data + ['action' => 'password']);
    }

    public function test_a_new_staff_member_is_held_on_the_password_form(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff')->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.profile', ['password' => 'required']));
        $this->get(route('admin.booking-requests'))->assertRedirect(route('admin.profile', ['password' => 'required']));
        $this->postJson(route('admin.booking-action'), ['booking' => 'VB-2026-001', 'action' => 'approve_id'])
            ->assertStatus(403)->assertJson(['ok' => false]);
        $this->get(route('admin.profile', ['password' => 'required']))->assertOk()
            ->assertSee('Set your own password to continue.')
            ->assertSee('Password you were given');
    }

    public function test_the_given_password_cannot_be_kept_and_a_new_one_lets_them_in(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff');
        $this->save(['current_password' => self::PASSWORD, 'new_password' => self::PASSWORD, 'confirm_new_password' => self::PASSWORD])
            ->assertStatus(400)->assertJson(['ok' => false, 'field' => 'new_password']);
        $this->assertNull(DB::table('users')->where('id', $staff->id)->value('password_changed_at'));

        $this->save(['current_password' => self::PASSWORD, 'new_password' => 'Mine-Only-2026', 'confirm_new_password' => 'Mine-Only-2026'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertNotNull(DB::table('users')->where('id', $staff->id)->value('password_changed_at'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.profile'))->assertOk()->assertDontSee('Set your own password to continue.');
    }

    public function test_admins_and_staff_with_their_own_password_are_not_held(): void
    {
        DB::table('users')->where('email', 'admin@gmail.com')->update(['password_changed_at' => null]);   // the rule is staff-only
        $this->actingAsAdmin()->get(route('admin.profile'))->assertOk()
            ->assertSee('Change password')->assertDontSee('Set your own password to continue.');
        $this->flushSession();
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk();
    }
}
