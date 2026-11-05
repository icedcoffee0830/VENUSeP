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

    public function test_staff_see_their_own_card_without_two_step(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff')->get(route('admin.profile'))->assertOk()
            ->assertSee('Settings')->assertSee('Test Staff')->assertSee($staff->email)
            ->assertSee('>TS</div>', false)
            ->assertSee(json_encode(route('root.profile-save')), false)
            ->assertDontSee('data-tfa-panel', false);
    }

    public function test_an_admin_sees_the_two_step_card(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => self::SECRET]);
        $this->signInAs($admin, 'admin')->get(route('admin.profile'))->assertOk()
            ->assertSee('Admin · no group yet')
            ->assertSee('data-tfa-panel', false)->assertSee('data-endpoint="' . route('root.profile-save') . '"', false)
            ->assertDontSee('Demo Mode')
            ->assertSee(asset('assets/js/confirm-modal.js'), false);
    }

    /* The role comes from the account (DB-DECISIONS 28), the employee number from the database. */
    public function test_the_page_shows_the_role_and_the_employee_number(): void
    {
        $this->actingAsStaff()->get(route('admin.profile'))->assertOk()
            ->assertSee('Staff · Bahay Alumni · Venue Coordinator')
            ->assertSee('value="Staff · Bahay Alumni"', false)
            ->assertSee('value="EMP-2021-0043"', false);
        $this->flushSession();
        $this->actingAsStaff('jakemerson@gmail.com')->get(route('admin.profile'))->assertOk()->assertSee('value="Staff · USeP Hostel"', false);
        $this->flushSession();
        $this->actingAsAdmin('cedu.admin@gmail.com')->get(route('admin.profile'))->assertOk()->assertSee('value="Admin · USeP CEDU"', false);
        $this->flushSession();
        $emp = DB::table('staff as s')->join('users as u', 'u.id', '=', 's.user_id')->where('u.email', 'admin@gmail.com')->value('s.employee_no');
        $this->assertMatchesRegularExpression('/^EMP-\d{4}-\d{4,}$/', (string) $emp);   // backfilled by migration 18
        $this->actingAsAdmin()->get(route('admin.profile'))->assertOk()
            ->assertSee('value="Super admin"', false)->assertSee('value="' . $emp . '"', false);
    }

    /* Every new staff/admin account gets the next EMP-<year>-NNNN; nobody changes it. */
    public function test_new_accounts_get_the_next_employee_number_and_it_never_changes(): void
    {
        $year = date('Y');
        $a = $this->makeAccount('staff');
        $b = $this->makeAccount('staff');
        $na = (string) DB::table('staff')->where('user_id', $a->id)->value('employee_no');
        $nb = (string) DB::table('staff')->where('user_id', $b->id)->value('employee_no');
        $this->assertMatchesRegularExpression('/^EMP-' . $year . '-\d{4,}$/', $na);
        $this->assertSame((int) substr($na, 9) + 1, (int) substr($nb, 9));
        try {
            DB::table('staff')->where('user_id', $a->id)->update(['employee_no' => 'EMP-HAND-1']);
            $this->fail('an employee number was changed');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('An employee number is given once and never changes.', $e->getMessage());
        }
        $this->assertSame($na, DB::table('staff')->where('user_id', $a->id)->value('employee_no'));
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
