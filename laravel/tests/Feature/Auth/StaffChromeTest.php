<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/* Bug 5 (HANDOFF §6): the admin header shows WHO is signed in (not the
   placeholder "Administrator"), and the sidebar does not offer staff the
   admin-only page (Staff Management, `staff:admin`). */
class StaffChromeTest extends TestCase
{
    use MakesAccounts;

    private function header(string $html): string
    {
        $this->assertSame(1, preg_match('~<nav class="app-header">(.*?)</nav>~s', $html, $m), 'header not found');

        return $m[1];
    }

    private function sidebar(string $html): string
    {
        $this->assertSame(1, preg_match('~<aside class="sidebar" id="hdSidebar">(.*?)</aside>~s', $html, $m), 'sidebar not found');

        return $m[1];
    }

    public function test_staff_see_their_own_name_and_no_admin_only_page(): void
    {
        $html = $this->actingAsStaff()->get(route('admin.dashboard'))->assertOk()->getContent();   // staff@gmail.com = Marites Robles

        $header = $this->header($html);
        $this->assertStringContainsString('<span class="hd-username">Marites Robles</span>', $header);
        $this->assertStringContainsString('>MR</span>', $header);
        $this->assertStringContainsString('Staff', $header);
        $this->assertStringNotContainsString('Administrator', $header);

        $sidebar = $this->sidebar($html);
        $this->assertStringNotContainsString(route('admin.staff'), $sidebar);
        $this->assertStringContainsString(route('admin.payment-settings'), $sidebar);   // staff see it read-only (#16)
        $this->assertStringContainsString(route('admin.backup-restore'), $sidebar);     // staff may back up (#25)
    }

    public function test_an_admin_sees_their_name_the_admin_role_and_staff_management(): void
    {
        $html = $this->actingAsAdmin()->get(route('admin.dashboard'))->assertOk()->getContent();   // admin@gmail.com: no staff row

        $header = $this->header($html);
        $this->assertStringContainsString('<span class="hd-username">admin</span>', $header);      // the username, as on the profile page
        $this->assertStringContainsString('Administrator', $header);
        $this->assertStringContainsString(route('admin.staff'), $this->sidebar($html));
    }
}
