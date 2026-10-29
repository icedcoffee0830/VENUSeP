<?php

namespace Tests\Feature\AdminPeople;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/venusep_staffManagement.php + admin/staff-save.php + admin/admin-register.php.
   Test DB: admin 1 + admin 75, staff 7 (venue 1), 73 (venue 3), 74 (venue 1). */
class StaffManagementTest extends TestCase
{
    private function post_(array $data)
    {
        return $this->post(route('admin.staff-save'), $data + ['csrf' => 'tok']);
    }

    public function test_pages_are_admin_only(): void
    {
        $this->get(route('admin.staff'))->assertRedirect(route('admin.admin-login'));
        $this->get(route('admin.admin-register'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsStaff()->get(route('admin.staff'))->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.admin-register'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_customer_is_sent_to_the_staff_sign_in(): void
    {
        $this->actingAsCustomer()->get(route('admin.staff'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_admin_sees_the_roster(): void
    {
        $this->actingAsAdmin()->get(route('admin.staff'))->assertOk()
            ->assertSee('Staff Management')
            ->assertSee('"email":"staff@gmail.com"', false)
            ->assertSee('"venue":"All venues"', false)
            ->assertSee('"isSelf":true', false)
            ->assertSee('href="' . route('admin.admin-register') . '"', false)
            ->assertSee(json_encode(route('admin.staff-save')), false);
    }

    public function test_admin_sees_the_register_form_with_venues(): void
    {
        $this->actingAsAdmin()->get(route('admin.admin-register'))->assertOk()
            ->assertSee('Create a staff or admin account')
            ->assertSee('<option value="3">USeP Hostel</option>', false)
            ->assertSee(json_encode(route('root.register-submit')), false)
            ->assertSee(asset('logo/Logo Header 3.png'), false);
    }

    public function test_save_is_admin_only_json(): void
    {
        $this->get(route('admin.staff-save'))->assertStatus(405)->assertExactJson(['ok' => false, 'message' => 'Use POST.']);
        $this->actingAsStaff()->post(route('admin.staff-save'), ['action' => 'set_active', 'user_id' => 73, 'active' => 0])
            ->assertStatus(403)->assertExactJson(['ok' => false, 'message' => 'Only an administrator can manage staff accounts.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', 73)->value('is_active'));
    }

    public function test_save_refuses_a_stale_token_and_a_customer_target(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        $this->post(route('admin.staff-save'), ['csrf' => 'old', 'action' => 'set_active', 'user_id' => 73])
            ->assertStatus(400)->assertJson(['message' => 'This page has expired. Reload it and try again.']);
        $this->post_(['action' => 'set_active', 'user_id' => 2, 'active' => '0'])
            ->assertStatus(403)->assertJson(['message' => 'That account is not a staff account.']);
    }

    public function test_suspend_then_reactivate_notifies_admins(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        $before = DB::table('notifications')->count();
        try {
            $this->post_(['action' => 'set_active', 'user_id' => 73, 'active' => '0'])
                ->assertOk()->assertExactJson(['ok' => true, 'active' => 0, 'message' => 'Account suspended. They can no longer sign in.']);
            $this->assertSame(0, (int) DB::table('users')->where('id', 73)->value('is_active'));
            $this->assertGreaterThan($before, DB::table('notifications')->count());
            $this->post_(['action' => 'set_active', 'user_id' => 73, 'active' => '1'])
                ->assertOk()->assertJson(['active' => 1, 'message' => 'Account reactivated.']);
        } finally {
            DB::table('users')->where('id', 73)->update(['is_active' => 1]);
        }
    }

    public function test_an_admin_cannot_suspend_themselves(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        $this->post_(['action' => 'set_active', 'user_id' => 1, 'active' => '0'])
            ->assertStatus(409)->assertJson(['message' => 'You cannot suspend your own account. Ask another administrator to do it.']);
        $this->assertSame(1, (int) DB::table('users')->where('id', 1)->value('is_active'));
    }

    public function test_a_suspended_admin_cannot_suspend_the_last_active_admin(): void
    {
        /* Since 2026-10-29 staff-save re-reads the ACTOR: with the actor's own row inactive
           it refuses before the last-admin count guard (which stays as a backstop). */
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        DB::table('users')->where('id', 1)->update(['is_active' => 0]);
        try {
            $this->post_(['action' => 'set_active', 'user_id' => 75, 'active' => '0'])
                ->assertStatus(403)->assertJson(['message' => 'Only an administrator can manage staff accounts.']);
            $this->assertSame(1, (int) DB::table('users')->where('id', 75)->value('is_active'));
        } finally {
            DB::table('users')->whereIn('id', [1, 75])->update(['is_active' => 1]);
        }
    }

    public function test_set_venue_requires_a_venue_and_moves_the_staff_member(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        try {
            $this->post_(['action' => 'set_venue', 'user_id' => 73, 'venue_id' => ''])
                ->assertStatus(400)->assertExactJson(['ok' => false, 'field' => 'venue_id', 'message' => 'Every staff member must handle a venue. Choose one.']);
            $this->post_(['action' => 'set_venue', 'user_id' => 1, 'venue_id' => '2'])
                ->assertStatus(400)->assertJson(['message' => 'Venue assignments apply to staff accounts.']);
            $this->post_(['action' => 'set_venue', 'user_id' => 73, 'venue_id' => '99'])
                ->assertStatus(400)->assertJson(['message' => 'That venue does not exist.']);
            $this->post_(['action' => 'set_venue', 'user_id' => 73, 'venue_id' => '2'])
                ->assertOk()->assertExactJson(['ok' => true, 'venue_id' => 2, 'venue' => 'USeP Venues', 'message' => 'Venue assignment updated.']);
            $this->assertSame(2, (int) DB::table('staff')->where('user_id', 73)->value('venue_id'));
        } finally {
            DB::table('staff')->where('user_id', 73)->update(['venue_id' => 3]);
        }
    }

    public function test_update_validates_and_saves(): void
    {
        $this->actingAsAdmin()->withSession(['_token' => 'tok']);
        $orig = DB::table('staff')->where('user_id', 74)->first();
        $origEmail = DB::table('users')->where('id', 74)->value('email');
        try {
            $this->post_(['action' => 'update', 'user_id' => 74, 'full_name' => '', 'email' => 'x@y.z'])
                ->assertStatus(400)->assertJson(['field' => 'full_name', 'message' => 'Enter a full name.']);
            $this->post_(['action' => 'update', 'user_id' => 74, 'full_name' => 'A', 'email' => 'x@y.z', 'phone' => '123'])
                ->assertStatus(400)->assertJson(['field' => 'phone', 'message' => 'Enter a mobile number in the form 09XX XXX XXXX.']);
            $this->post_(['action' => 'update', 'user_id' => 74, 'full_name' => 'A', 'email' => 'staff@gmail.com'])
                ->assertStatus(409)->assertJson(['field' => 'email', 'message' => 'That email address already belongs to another account.']);
            $this->post_(['action' => 'update', 'user_id' => 74, 'full_name' => 'Maco Case', 'email' => 'MacoCase@Gmail.com',
                          'phone' => '+63 917 555 0100', 'employee_no' => 'EMP-T-1', 'position_role' => 'Clerk'])
                ->assertOk()->assertExactJson(['ok' => true, 'message' => 'Account updated.']);
            $s = DB::table('staff')->where('user_id', 74)->first();
            $this->assertSame(['Maco Case', '09175550100', 'EMP-T-1', 'Clerk', 1],
                [$s->full_name, $s->phone, $s->employee_no, $s->position_role, (int) $s->venue_id]);
            $this->assertSame('macocase@gmail.com', DB::table('users')->where('id', 74)->value('email'));
        } finally {
            DB::table('staff')->where('user_id', 74)->update((array) $orig);
            DB::table('users')->where('id', 74)->update(['email' => $origEmail]);
        }
    }
}
