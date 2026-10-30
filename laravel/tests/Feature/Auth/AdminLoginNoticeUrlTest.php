<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/* The sign-in form posts back to its own URL. After being sent to
   admin-login?suspended=1 or ?tfa=required, the notice used to be set on the
   POST as well, which skipped the sign-in: the page only showed the notice again. */
class AdminLoginNoticeUrlTest extends TestCase
{
    use MakesAccounts;

    public function test_signing_in_from_the_suspended_notice_url_works(): void
    {
        $staff = $this->makeAccount('staff');
        $url = route('admin.admin-login') . '?suspended=1';
        $this->get($url)->assertSee('Your account has been suspended.');
        $this->post($url, ['email' => $staff->email, 'password' => self::PASSWORD, 'department' => 1])
            ->assertRedirect(route('admin.dashboard'))->assertSessionHas('user_id', $staff->id);
    }

    public function test_signing_in_from_the_two_step_notice_url_reaches_the_phone_set_up(): void
    {
        $admin = $this->makeAccount('admin');
        $url = route('admin.admin-login') . '?tfa=required';
        $this->get($url)->assertSee('Admin accounts now need two-step verification.');
        $this->post($url, ['email' => $admin->email, 'password' => self::PASSWORD, 'department' => 1])
            ->assertRedirect(route('admin.admin-login'));
        $this->assertSame('enroll', session('tfa_pending')['mode']);
    }
}
