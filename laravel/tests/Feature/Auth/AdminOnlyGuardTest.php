<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/* Bug 5, server side: every ADMIN-ONLY page and endpoint refuses a signed-in
   STAFF member, whatever the sidebar shows. "Admin-only" = what the old site
   guarded with admin_require_login(['admin']) / admin_is_admin() / an
   account_type = 'admin' re-check (DB-DECISIONS #16, #24, #25). Shared pages
   where staff get a read-only or smaller view (Payment Settings, Backup &
   Restore, Venue Management) are NOT admin-only and are not listed here. */
class AdminOnlyGuardTest extends TestCase
{
    private const TOKEN = 'admin-only-guard-test-token';

    private function asStaff(): static
    {
        return $this->actingAsStaff()->withSession(['_token' => self::TOKEN]);
    }

    private function postAsStaff(string $route, array $data = [])
    {
        return $this->asStaff()->post(route($route), $data + ['_token' => self::TOKEN], ['Accept' => 'application/json']);
    }

    public function test_admin_only_pages_send_staff_to_the_dashboard(): void
    {
        $this->asStaff()->get(route('admin.staff'))->assertRedirect(route('admin.dashboard'));
        $this->asStaff()->get(route('admin.admin-register'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_staff_and_admin_account_endpoints_refuse_staff(): void
    {
        $this->postAsStaff('admin.staff-save', ['action' => 'suspend', 'user_id' => 74])->assertStatus(403);
        $this->postAsStaff('root.register-submit', ['kind' => 'staff', 'full_name' => 'X Y', 'email' => 'x@test.local'])->assertStatus(403);
        $this->postAsStaff('root.register-submit', ['kind' => 'admin', 'full_name' => 'X Y', 'email' => 'x@test.local'])->assertStatus(403);
        $this->postAsStaff('admin.venue-save', ['venue_id' => '1', 'name' => 'Bahay Alumni', 'move_staff' => '[74]'])->assertStatus(403);
    }

    public function test_settings_endpoints_refuse_staff(): void
    {
        $this->postAsStaff('admin.refund-switch', ['enabled' => '1', 'password' => 'x'])->assertStatus(403);
        $this->postAsStaff('admin.discount-save', ['percent' => '30', 'reason' => 'x'])->assertStatus(403);
        $this->postAsStaff('admin.demo-mode-switch', ['enabled' => '1', 'password' => 'x'])->assertStatus(403);
        $this->postAsStaff('admin.gcash-account-save', ['venue_id' => '1', 'password' => 'x'])->assertStatus(403);
        $this->postAsStaff('admin.catalog-delete', ['kind' => 'room', 'id' => '1', 'confirm' => 'delete'])->assertStatus(403);
    }

    public function test_backup_download_delete_and_restore_refuse_staff(): void
    {
        $this->asStaff()->get(route('admin.backup-action', ['download' => 'x.tar.gz']))->assertStatus(403);
        $this->postAsStaff('admin.backup-action', ['action' => 'delete', 'file' => 'x.tar.gz'])->assertStatus(403);
        $this->postAsStaff('admin.backup-action', ['action' => 'restore', 'file' => 'x.tar.gz', 'password' => 'x'])->assertStatus(403);
    }
}
