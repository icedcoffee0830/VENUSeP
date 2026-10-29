<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\AdminSettings\AdminTestHelpers;
use Tests\TestCase;

/* The old-site bugs found during the Laravel port, fixed 2026-10-29 in both the
   plain-PHP site (branch bugfixes) and here. One test per bug. */
class BugFixesTest extends TestCase
{
    use AdminTestHelpers;

    /* 1. Hostel room form: Save called selectedAmenities(), which lived in another
          script scope -> ReferenceError, nothing saved. Now on window, like room-form. */
    public function test_hostel_room_form_save_can_reach_the_amenity_picker(): void
    {
        $this->actingAsAdmin()->get(route('admin.hostel-room-form'))->assertOk()
            ->assertSee('window.selectedAmenities = function', false)
            ->assertSee('JSON.stringify(window.selectedAmenities())', false)
            ->assertDontSee('JSON.stringify(selectedAmenities())', false);
    }

    /* 2. Edit Room on a DISABLED room opened an empty Add form. */
    public function test_a_disabled_event_room_opens_for_editing(): void
    {
        $this->assertDisabledRoomOpens('event', 'admin.room-form');
    }

    public function test_a_disabled_hostel_room_opens_for_editing(): void
    {
        $this->assertDisabledRoomOpens('hostel', 'admin.hostel-room-form');
    }

    private function assertDisabledRoomOpens(string $type, string $route): void
    {
        $room = DB::table('rooms')->where('room_type', $type)->where('is_active', 1)->orderBy('id')->first();
        DB::table('rooms')->where('id', $room->id)->update(['is_active' => 0]);
        try {
            $this->actingAsAdmin()->get(route($route, ['id' => $room->room_code]))->assertOk()
                ->assertSee(e($room->name), false);
        } finally {
            DB::table('rooms')->where('id', $room->id)->update(['is_active' => 1]);
        }
    }

    /* 3. The discount rate is admin-only (decided 2026-10-29): staff are refused by
          the server and don't get the button; the form no longer shows itself. */
    public function test_staff_cannot_change_the_discount(): void
    {
        $before = DB::table('system_settings')->where('setting_key', 'discount_percent')->value('setting_value');
        $this->actingAsStaff()->withTestCsrf()
            ->post(route('admin.discount-save'), ['_token' => 'test-csrf-token', 'percent' => '7', 'reason' => 'staff try'])
            ->assertStatus(403)->assertExactJson(['ok' => false, 'error' => 'not_admin', 'message' => 'Only the administrator can change the discount rate.']);
        $this->assertSame($before, DB::table('system_settings')->where('setting_key', 'discount_percent')->value('setting_value'));
    }

    public function test_only_admins_see_the_change_rate_button(): void
    {
        $this->actingAsStaff()->get(route('admin.venue-management'))->assertOk()
            ->assertDontSee('id="dcOpen"', false)
            ->assertSee('.vm-setting-form[hidden]', false);   // the closed form really hides
    }

    public function test_admins_still_see_the_change_rate_button(): void
    {
        $this->actingAsAdmin()->get(route('admin.venue-management'))->assertOk()->assertSee('id="dcOpen"', false);
    }

    /* 5. Saving a GCash account from Payment Settings (which sends no note) wiped the note. */
    public function test_a_gcash_save_without_a_note_keeps_the_current_note(): void
    {
        $this->useKnownAdminPassword();
        $old = DB::table('gcash_accounts')->where('venue_id', 1)->where('is_active', 1)->whereNull('valid_until')->first();
        DB::table('gcash_accounts')->where('id', $old->id)->update(['note' => 'Cashier: ask for the OR']);
        try {
            $this->actingAsAdmin()->withTestCsrf()->post(route('admin.gcash-account-save'), [
                '_token' => 'test-csrf-token', 'venue' => 'Bahay Alumni', 'account_name' => 'Test Payee',
                'mobile_number' => '0917 000 2222', 'password' => $this->pw,
            ])->assertOk()->assertJson(['ok' => true]);
            $now = DB::table('gcash_accounts')->where('venue_id', 1)->where('is_active', 1)->whereNull('valid_until')->first();
            $this->assertSame('Cashier: ask for the OR', $now->note);

            // put the seeded account back, with its original note
            $this->post(route('admin.gcash-account-save'), [
                '_token' => 'test-csrf-token', 'venue' => 'Bahay Alumni', 'account_name' => $old->account_name,
                'mobile_number' => $old->mobile_number, 'note' => (string) $old->note, 'password' => $this->pw,
            ])->assertOk();
        } finally {
            $this->restoreAdminPassword();
        }
    }

    /* 6. A suspended admin's still-open session could keep managing staff. */
    public function test_a_suspended_admin_cannot_manage_staff(): void
    {
        $this->actingAsAdmin()->withTestCsrf();
        DB::table('users')->where('email', 'admin@gmail.com')->update(['is_active' => 0]);
        try {
            $staffId = DB::table('users')->where('email', 'staff@gmail.com')->value('id');
            $this->post(route('admin.staff-save'), ['_token' => 'test-csrf-token', 'action' => 'set_active', 'user_id' => $staffId, 'active' => '0'])
                ->assertStatus(403)->assertJson(['ok' => false, 'message' => 'Only an administrator can manage staff accounts.']);
            $this->assertSame(1, (int) DB::table('users')->where('id', $staffId)->value('is_active'));
        } finally {
            DB::table('users')->where('email', 'admin@gmail.com')->update(['is_active' => 1]);
        }
    }
}
