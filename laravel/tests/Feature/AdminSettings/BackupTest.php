<?php

namespace Tests\Feature\AdminSettings;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/* admin/backup-restore.php + admin/backup-action.php. A restore is NEVER run
   here (it would reload the database); only its guards and re-auth. The one
   real backup is made of this worktree's test database and deleted again. */
class BackupTest extends TestCase
{
    use AdminTestHelpers;

    protected function tearDown(): void
    {
        $this->restoreAdminPassword();
        parent::tearDown();
    }

    public function test_page_guards(): void
    {
        $this->get(route('admin.backup-restore'))->assertRedirect(route('admin.admin-login'));
        $this->actingAsCustomer()->get(route('admin.backup-restore'))->assertRedirect(route('admin.admin-login'));
    }

    public function test_staff_sees_back_up_only(): void
    {
        $this->actingAsStaff()->get(route('admin.backup-restore'))->assertOk()
            ->assertSee('Backup &amp; Restore', false)->assertSee('Back up now')
            ->assertSee('Only an administrator can download, restore or delete a backup.')
            ->assertDontSee('Restore from a downloaded backup');
    }

    public function test_admin_sees_restore(): void
    {
        $this->actingAsAdmin()->get(route('admin.backup-restore'))->assertOk()
            ->assertSee('Restore from a downloaded backup')->assertSee('The newest 10 are kept.')
            ->assertSee(json_encode(route('admin.backup-action')), false);
    }

    public function test_action_guards(): void
    {
        $this->post(route('admin.backup-action'), ['action' => 'create'])->assertStatus(401)
            ->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        $this->actingAsStaff()->withTestCsrf();
        $this->get(route('admin.backup-action'))->assertStatus(405)->assertHeader('Allow', 'GET, POST');
        $this->get(route('admin.backup-action', ['download' => 'x']))->assertStatus(403)
            ->assertJson(['message' => 'Only an administrator can download a backup.']);
        $this->post(route('admin.backup-action'), ['_token' => 'bad', 'action' => 'create'])->assertStatus(400)
            ->assertJson(['message' => 'This page has expired. Reload it and try again.']);
        $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'delete', 'file' => 'x'])
            ->assertStatus(403)->assertJson(['message' => 'Only an administrator can do that.']);
        $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'restore', 'password' => 'x'])
            ->assertStatus(403);
    }

    public function test_restore_guards_never_restore(): void
    {
        $this->useKnownAdminPassword();
        $this->actingAsAdmin()->withTestCsrf();
        $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'restore', 'file' => 'nope.tar.gz', 'password' => $this->pw])
            ->assertStatus(404)->assertJson(['message' => 'Pick a backup to restore.']);
        $this->post(route('admin.backup-action'), [
            '_token' => 'test-csrf-token', 'action' => 'restore', 'password' => 'wrong',
            'archive' => UploadedFile::fake()->create('notes.txt', 1),
        ])->assertStatus(400)->assertJson(['message' => 'Choose a VENUSeP backup file (.tar.gz).']);
        $this->post(route('admin.backup-action'), [
            '_token' => 'test-csrf-token', 'action' => 'restore', 'password' => 'wrong',
            'archive' => UploadedFile::fake()->create('venusep.tar.gz', 1),
        ])->assertStatus(401)->assertJson(['error' => 'wrong_password',
            'message' => 'Wrong password. 4 attempt(s) left before a temporary lock.']);
        $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'bogus'])
            ->assertStatus(400)->assertJson(['message' => 'Unknown action.']);
    }

    public function test_staff_makes_a_backup_admin_downloads_and_deletes_it(): void
    {
        $this->assertStringStartsWith('venusep_laravel_test', DB::connection()->getDatabaseName());   // never the real database

        $res = $this->actingAsStaff()->withTestCsrf()
            ->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'create']);
        $res->assertOk()->assertJson(['ok' => true]);
        $file = $res->json('file');
        $this->assertMatchesRegularExpression('/^venusep-backup-\d{8}-\d{6}-manual\.tar\.gz$/', $file);
        $dir = storage_path('app/venusep-private/backups');
        $this->assertFileExists($dir . '/' . $file);   // inside laravel/storage, not the old repo

        $this->flushSession();
        $this->actingAsAdmin()->withTestCsrf();
        $this->get(route('admin.backup-action', ['download' => $file]))->assertOk()
            ->assertHeader('Content-Type', 'application/gzip')
            ->assertDownload($file);
        $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'delete', 'file' => $file])
            ->assertOk()->assertJson(['ok' => true, 'message' => 'Backup deleted.']);
        $this->assertFileDoesNotExist($dir . '/' . $file);
    }
}
