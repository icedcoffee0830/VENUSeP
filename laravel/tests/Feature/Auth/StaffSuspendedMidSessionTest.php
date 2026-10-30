<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* A staff member suspended WHILE signed in loses the session on their next
   request of ANY kind. Before, only pages behind the `staff` guard checked
   (staff_session_heal()); the JSON endpoints a still-open page calls
   (booking-action, booking-create, discount-save, ...) and the counter
   booking pages kept working for the suspended account. */
class StaffSuspendedMidSessionTest extends TestCase
{
    use MakesAccounts;

    private const TOKEN = 'suspended-mid-session-token';

    public function test_a_suspended_staff_members_json_requests_answer_session_ended(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff')->withSession(['_token' => self::TOKEN]);
        DB::table('users')->where('id', $staff->id)->update(['is_active' => 0]);   // an admin suspends them

        $this->post(route('admin.booking-action'), ['_token' => self::TOKEN, 'action' => 'approve', 'booking' => 'VB-2026-1'],
            ['Accept' => 'application/json'])
            ->assertStatus(401)->assertJson(['ok' => false]);
        $this->assertNull(session('user_id'));
        $this->get(route('admin.admin-login'))->assertSee('Your account has been suspended.');
    }

    public function test_a_suspended_staff_member_loses_the_counter_booking_page(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff');
        DB::table('users')->where('id', $staff->id)->update(['is_active' => 0]);

        $this->get(route('customer.room-reservation') . '?room=r2&counter=1')->assertRedirect();
        $this->assertNull(session('user_id'));
    }

    public function test_a_suspended_staff_member_on_a_page_still_gets_the_suspended_notice(): void
    {
        $staff = $this->makeAccount('staff');
        $this->signInAs($staff, 'staff');
        DB::table('users')->where('id', $staff->id)->update(['is_active' => 0]);

        $to = $this->get(route('admin.dashboard'))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith(route('admin.admin-login'), $to);
        $this->get($to)->assertSee('Your account has been suspended.');
    }

    public function test_a_demoted_admin_stops_being_one_on_json_endpoints_too(): void
    {
        $admin = $this->makeAccount('admin', ['totp' => 'JBSWY3DPEHPK3PXP']);
        $this->signInAs($admin, 'admin')->withSession(['_token' => self::TOKEN]);
        DB::table('users')->where('id', $admin->id)->update(['account_type' => 'staff']);

        $this->post(route('admin.discount-save'), ['_token' => self::TOKEN, 'percent' => 'abc', 'reason' => 'x'],   // invalid on purpose: nothing is written even if the role check fails
            ['Accept' => 'application/json'])
            ->assertStatus(403)->assertJson(['error' => 'not_admin']);
    }

    public function test_an_active_staff_member_is_not_touched(): void
    {
        $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk();
        $this->assertSame('staff', session('account_type'));
    }
}
