<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/venusep_profile.php + the staff/admin branches of profile-save.php */
class AdminProfileTest extends TestCase
{
    use MakesAccounts;

    private const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.profile'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.profile'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_see_their_own_card_without_two_step_or_the_demo_switch(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff')->get(route('admin.profile'))->assertOk()
            ->assertSee('Settings')->assertSee('Test Staff')->assertSee($staff->email)
            ->assertSee('>TS</div>', false)
            ->assertSee(json_encode(route('root.profile-save')), false)
            ->assertDontSee('data-tfa-panel', false)
            ->assertDontSee('id="dmOpen"', false);
    }

    public function test_an_admin_sees_the_two_step_card_and_the_demo_switch(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->signInAs($admin, 'admin')->get(route('admin.profile') . '?demo=saved')->assertOk()
            ->assertSee('Administrator · All venues')
            ->assertSee('data-tfa-panel', false)->assertSee('data-endpoint="' . route('root.profile-save') . '"', false)
            ->assertSee('id="dmOpen"', false)->assertSee(json_encode(route('admin.demo-mode-switch')), false)
            ->assertSee('Saved. Demo mode is now', false)
            ->assertSee(asset('assets/js/confirm-modal.js'), false);
    }

    public function test_details_create_the_missing_staff_row_for_an_admin(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->assertNull(DB::table('staff')->where('user_id', $admin->id)->value('full_name'));
        $this->signInAs($admin, 'admin')->post(route('root.profile-save'),
            ['action' => 'details', 'full_name' => 'Ada Admin', 'email' => $admin->email, 'contact_number' => '09171234567'])
            ->assertOk()->assertJson(['ok' => true])->assertSessionHas('user_email', $admin->email)
            ->assertSessionMissing('customer_name');
        $this->assertSame('Ada Admin', DB::table('staff')->where('user_id', $admin->id)->value('full_name'));
        $this->get(route('admin.profile'))->assertSee('Ada Admin');
    }

    public function test_two_step_rules_for_admins_and_staff(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->signInAs($admin, 'admin')->post(route('root.profile-save'), ['action' => 'tfa_disable', 'code' => '123456'])
            ->assertStatus(403)->assertJson(['message' => 'Admin accounts must keep two-step verification on.']);
        // moving to a new phone needs a current code
        $this->post(route('root.profile-save'), ['action' => 'tfa_begin', 'code' => $this->totpNow(self::SECRET)])
            ->assertOk()->assertJsonStructure(['ok', 'uri', 'secret']);

        $this->flushSession();
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff')->post(route('root.profile-save'), ['action' => 'tfa_begin', 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJson(['message' => 'Two-step verification is not available for this account.']);
        $this->post(route('root.profile-save'), ['action' => 'google_unlink', 'password' => self::PASSWORD])
            ->assertStatus(403)->assertJson(['message' => 'Google sign-in is for customer accounts only.']);
    }
}
