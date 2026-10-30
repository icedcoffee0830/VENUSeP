<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* Admin sign-in Department picker (Aron, 2026-10-30): one card per ACTIVE venue;
   a staff account must pick its own venue ("This account belongs to <venue>."),
   checked only after the password; an admin may pick any card. */
class AdminDepartmentPickerTest extends TestCase
{
    use MakesAccounts;

    private function url(): string
    {
        return route('admin.admin-login');
    }

    private function venueId(string $code): int
    {
        return (int) DB::table('venues')->where('venue_code', $code)->value('id');
    }

    public function test_the_cards_are_the_active_venues_and_none_is_pre_picked(): void
    {
        $page = $this->get($this->url())->assertOk();
        foreach (DB::table('venues')->where('is_active', 1)->get() as $v) {
            $page->assertSee('value="' . $v->id . '"', false)->assertSee($v->name);
        }
        $this->assertSame(DB::table('venues')->where('is_active', 1)->count(), substr_count($page->getContent(), 'type="radio" name="department"'));
        $page->assertDontSee('checked required', false);
    }

    public function test_an_inactive_venue_has_no_card(): void
    {
        $hostel = $this->venueId('USEP-HOSTEL');
        DB::table('venues')->where('id', $hostel)->update(['is_active' => 0]);
        try {
            $this->get($this->url())->assertOk()->assertDontSee('value="' . $hostel . '"', false);
        } finally {
            DB::table('venues')->where('id', $hostel)->update(['is_active' => 1]);
        }
    }

    public function test_no_card_or_an_unknown_card_is_refused_before_the_password(): void
    {
        $staff = $this->makeAccount('staff');
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD])
            ->assertOk()->assertSee('Please select a department.')->assertSessionMissing('user_id');
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD, 'department' => 999999])
            ->assertSee('Please select a department.')->assertSessionMissing('user_id');
    }

    public function test_staff_on_another_venues_card_is_told_their_venue_after_the_right_password(): void
    {
        $staff = $this->makeAccount('staff');   // venue 1 = Bahay Alumni
        $other = $this->venueId('USEP-VENUES');
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD, 'department' => $other])
            ->assertOk()->assertSee('This account belongs to Bahay Alumni.')
            ->assertSee('value="' . $other . '" checked', false)   // the pick is kept
            ->assertSessionMissing('user_id');
        // a wrong password never names the venue
        $this->post($this->url(), ['email' => $staff->email, 'password' => 'wrong-password', 'department' => $other])
            ->assertSee('Invalid email or password.')->assertDontSee('This account belongs to');
    }

    public function test_staff_on_their_own_card_signs_in(): void
    {
        $staff = $this->makeAccount('staff');
        $this->post($this->url(), ['email' => $staff->email, 'password' => self::PASSWORD, 'department' => 1])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('user_id', $staff->id)->assertSessionHas('department', 1);
    }

    public function test_an_admin_may_pick_any_card(): void
    {
        foreach (['BAHAY-ALUMNI', 'USEP-VENUES', 'USEP-HOSTEL'] as $code) {
            $this->flushSession();
            $admin = $this->makeAccount('admin');   // no 2FA yet: the password step leads to the phone set-up
            $this->post($this->url(), ['email' => $admin->email, 'password' => self::PASSWORD, 'department' => $this->venueId($code)])
                ->assertRedirect($this->url());
            $this->assertSame($this->venueId($code), session('tfa_pending')['department']);
        }
    }
}
