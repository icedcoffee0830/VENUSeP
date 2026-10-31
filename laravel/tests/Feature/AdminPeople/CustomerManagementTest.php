<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/customer-management.php + admin/customer-save.php */
class CustomerManagementTest extends TestCase
{
    public function test_guest_and_customer_are_sent_to_the_staff_sign_in(): void
    {
        $this->get(route('admin.customer-management'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.customer-management'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_the_admin_sees_the_roster_without_walk_ins(): void
    {
        $this->actingAsAdmin()->get(route('admin.customer-management'))->assertOk()
            ->assertSee('Customer accounts')
            ->assertSee('"email":"msantos@usep.edu.ph"', false)
            ->assertDontSee('Carmen Uy')                                        // walk-in: no account
            ->assertSee(json_encode(route('admin.customer-save')), false)
            ->assertSee(asset('assets/js/confirm-modal.js'), false);
    }

    /* Aron 2026-10-31: admin only — page, save, and the sidebar link. */
    public function test_staff_are_kept_out(): void
    {
        $this->actingAsStaff()->get(route('admin.customer-management'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.customer-management'), false);
        $this->withSession(['_token' => 'tok'])
            ->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => 4, 'active' => '0'])
            ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Only an administrator can manage customer accounts.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', 4)->value('is_active'));
    }

    public function test_save_is_json_guarded(): void
    {
        $this->get(route('admin.customer-save'))->assertStatus(405)->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->post(route('admin.customer-save'), ['action' => 'set_active', 'user_id' => 4, 'active' => 0])
            ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Only an administrator can manage customer accounts.']);
        $this->actingAsAdmin()->post(route('admin.customer-save'), ['csrf' => 'stale', 'action' => 'set_active', 'user_id' => 4])
            ->assertStatus(400)->assertJson(['message' => 'This page has expired. Reload it and try again.']);
    }

    public function test_disable_and_re_enable_a_customer(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        try {
            $this->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => 4, 'active' => '0'])
                ->assertOk()->assertExactJson(['ok' => true, 'active' => 0,
                    'message' => 'Account disabled. They have been signed out and can no longer sign in.']);
            $this->assertSame(0, (int) DB::table('users')->where('id', 4)->value('is_active'));
            $this->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => 4, 'active' => '1'])
                ->assertOk()->assertJson(['ok' => true, 'active' => 1, 'message' => 'Account re-enabled.']);
            $this->assertSame(1, (int) DB::table('users')->where('id', 4)->value('is_active'));
        } finally {
            DB::table('users')->where('id', 4)->update(['is_active' => 1]);
        }
    }

    /* A customer chooses their own name. Printed into the page's <script> as JSON, a
       "</script>" in it must not end the script and run as the staff member's page. */
    public function test_a_customer_name_cannot_break_out_of_the_page_script(): void
    {
        $old = DB::table('customers')->where('id', 3)->value('full_name');
        DB::table('customers')->where('id', 3)->update(['full_name' => '</script><script>alert(1)</script>']);
        try {
            $this->actingAsAdmin()->get(route('admin.customer-management'))->assertOk()
                ->assertDontSee('</script><script>alert(1)', false)
                ->assertSee('"name":"\u003C/script\u003E\u003Cscript\u003Ealert(1)\u003C/script\u003E"', false);
        } finally {
            DB::table('customers')->where('id', 3)->update(['full_name' => $old]);
        }
    }

    /* The session still says staff, but the account was suspended meanwhile: the
       database decides, like on every staff page (staff_session_heal()). */
    public function test_a_suspended_staff_session_cannot_disable_customers(): void
    {
        $staffId = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        DB::table('users')->where('id', $staffId)->update(['is_active' => 0]);
        try {
            $this->actingAsStaff()->withSession(['_token' => 'tok'])
                ->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => 4, 'active' => '0'])
                ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Only an administrator can manage customer accounts.']);
            $this->assertSame(1, (int) DB::table('users')->where('id', 4)->value('is_active'));
            $this->assertFalse(session()->has('user_id'));                     // the dead session is ended
        } finally {
            DB::table('users')->where('id', $staffId)->update(['is_active' => 1]);
            DB::table('users')->where('id', 4)->update(['is_active' => 1]);
        }
    }

    public function test_a_staff_side_account_is_refused(): void
    {
        $staffId = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
        $this->actingAsAdmin()->withSession(['_token' => 'tok'])
            ->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => $staffId, 'active' => '0'])
            ->assertStatus(403)->assertJson(['message' => 'That account is not a customer account.']);
        $this->post(route('admin.customer-save'), ['csrf' => 'tok', 'action' => 'set_active', 'user_id' => 'x'])
            ->assertStatus(400)->assertJson(['message' => 'Invalid account.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', $staffId)->value('is_active'));
    }
}
