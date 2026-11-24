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

    /* Aron 2026-10-31: backups are the admin's alone. */
    public function test_staff_are_kept_out_of_backups(): void
    {
        $this->actingAsStaff()->get(route('admin.backup-restore'))->assertRedirect(route('admin.dashboard'));
    }

    public function test_admin_sees_restore(): void
    {
        $this->actingAsAdmin()->get(route('admin.backup-restore'))->assertOk()
            ->assertSee('Restore from a downloaded backup')->assertSee('The newest 10 are kept.')
            ->assertSee(json_encode(route('admin.backup-action')), false)
            /* the sidebar marks this tab: the label's "&" once kept it from matching */
            ->assertSee('<a href="' . route('admin.backup-restore') . '" class="active" aria-current="page">', false);
    }

    public function test_action_guards(): void
    {
        $this->post(route('admin.backup-action'), ['action' => 'create'])->assertStatus(401)
            ->assertExactJson(['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        $this->actingAsStaff()->withTestCsrf();
        $this->get(route('admin.backup-action', ['download' => 'x']))->assertStatus(403)
            ->assertJson(['message' => 'Only the super admin can use backups.']);
        foreach (['create', 'delete', 'restore'] as $action) {
            $this->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => $action, 'file' => 'x', 'password' => 'x'])
                ->assertStatus(403)->assertJson(['message' => 'Only the super admin can use backups.']);
        }
        $this->flushSession();
        $this->actingAsAdmin()->withTestCsrf();
        $this->get(route('admin.backup-action'))->assertStatus(405)->assertHeader('Allow', 'GET, POST');
        $this->post(route('admin.backup-action'), ['_token' => 'bad', 'action' => 'create'])->assertStatus(400)
            ->assertJson(['message' => 'This page has expired. Reload it and try again.']);
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

    /* A dump names its own database and the mysql client follows it, so a backup of
       another copy would be restored over THAT database. Refused before the safety
       copy; the name used here does not exist, so even an unfixed restore only fails.
       The stored upload is pruned like any backup (newest 10) and, once refused,
       removed from the list. */
    public function test_routine_backups_never_prune_the_pre_restore_copy(): void
    {
        legacy('backup');
        $dir = backup_dir();
        $before = glob($dir . '/*') ?: [];
        $safety = $dir . '/venusep-backup-19990101-000000-pre-restore.tar.gz';
        file_put_contents($safety, 'x');
        for ($i = 0; $i < 12; $i++) {
            file_put_contents($dir . sprintf('/venusep-backup-20000101-0000%02d-manual.tar.gz', $i), 'x');
        }
        try {
            backup_prune();
            $this->assertFileExists($safety);
            $this->assertFileDoesNotExist($dir . '/venusep-backup-20000101-000000-manual.tar.gz');
        } finally {
            foreach (array_diff(glob($dir . '/*') ?: [], $before) as $f) {
                @unlink($f);
            }
        }
    }

    /* The daily task names its files "-auto": they once failed the name check, so they never
       showed on the page and were never pruned. Now they show, and are kept 7 on their own. */
    public function test_daily_backups_show_and_never_push_out_hand_made_ones(): void
    {
        legacy('backup');
        $dir = backup_dir();
        $before = glob($dir . '/*') ?: [];
        for ($i = 0; $i < 9; $i++) {
            file_put_contents($dir . sprintf('/venusep-backup-20000102-0000%02d-auto.tar.gz', $i), 'x');
        }
        $manual = $dir . '/venusep-backup-20000101-000000-manual.tar.gz';
        file_put_contents($manual, 'x');
        try {
            $this->assertContains('venusep-backup-20000102-000008-auto.tar.gz', array_column(backup_list(), 'file'));
            $this->assertNotNull(backup_path('venusep-backup-20000102-000008-auto.tar.gz'));
            backup_prune();
            $this->assertFileExists($manual);    // older than every daily one, still kept
            $this->assertFileExists($dir . '/venusep-backup-20000102-000002-auto.tar.gz');
            $this->assertFileDoesNotExist($dir . '/venusep-backup-20000102-000001-auto.tar.gz');
            $this->assertFileDoesNotExist($dir . '/venusep-backup-20000102-000000-auto.tar.gz');
            $this->actingAsAdmin()->get(route('admin.backup-restore'))->assertOk()
                ->assertSee('Automatic daily backup')->assertSee('the newest 7');
        } finally {
            foreach (array_diff(glob($dir . '/*') ?: [], $before) as $f) {
                @unlink($f);
            }
        }
    }

    public function test_restore_refuses_a_backup_of_another_database(): void
    {
        legacy('backup');
        $dir = backup_dir();
        $before = glob($dir . '/*') ?: [];
        $src = $this->otherDatabaseBackup();
        /* older backups up to ten (a real one may already be here): storing the upload makes eleven, pruned to the newest 10 */
        $older = 10 - count(glob($dir . '/*.tar.gz') ?: []);
        for ($i = 0; $i < $older; $i++) {
            file_put_contents($dir . sprintf('/venusep-backup-20000101-0000%02d-manual.tar.gz', $i), 'x');
        }

        $this->useKnownAdminPassword();
        try {
            $this->actingAsAdmin()->withTestCsrf()->post(route('admin.backup-action'), [
                '_token' => 'test-csrf-token', 'action' => 'restore', 'password' => $this->pw,
                'archive' => new UploadedFile($src . '.tar.gz', 'venusep-backup.tar.gz', 'application/gzip', null, true),
            ])->assertStatus(409)->assertJson(['ok' => false,
                'message' => 'That backup is of the database "venusep_admina_zz_other", and this system runs on "'
                           . DB::connection()->getDatabaseName() . '". Restoring it would overwrite "venusep_admina_zz_other", so it was refused.']);
            $made = array_diff(glob($dir . '/*') ?: [], $before);
            $this->assertSame([], array_values(array_filter($made, fn ($f) => str_contains($f, 'pre-restore'))), 'no safety copy was needed');
            $this->assertSame([], array_values(array_filter($made, fn ($f) => str_contains($f, 'uploaded'))), 'the refused upload is not kept');
            $this->assertFileDoesNotExist($dir . '/venusep-backup-20000101-000000-manual.tar.gz', 'storing the upload pruned to the newest 10');
            $this->assertFileExists($dir . '/venusep-backup-20000101-000001-manual.tar.gz');
        } finally {
            foreach (array_diff(glob($dir . '/*') ?: [], $before) as $f) {
                @unlink($f);
            }
            foreach ([$src . '/manifest.json', $src . '/database.sql', $src . '.tar', $src . '.tar.gz', $src . '-usb.tar.gz'] as $f) {
                @unlink($f);
            }
            @rmdir($src);
        }
    }

    /* A VENUSeP backup of ANOTHER database: $src.tar.gz (the caller removes $src.*). */
    private function otherDatabaseBackup(): string
    {
        $src = sys_get_temp_dir() . '/venusep-admina-' . bin2hex(random_bytes(4));
        mkdir($src);
        file_put_contents($src . '/manifest.json', json_encode(['app' => 'VENUSeP', 'kind' => 'manual',
            'schema_version' => backup_schema_version(), 'database' => 'venusep_admina_zz_other']));
        file_put_contents($src . '/database.sql', "USE `venusep_admina_zz_other`;\n");
        $tar = new \PharData($src . '.tar');
        $tar->buildFromDirectory($src);
        $tar->compress(\Phar::GZ);
        unset($tar);
        return $src;
    }

    /* The command line restores when nobody can sign in. It lists, asks for the database
       name, and goes through the same checks as the page. */
    public function test_the_command_line_restore_lists_asks_and_refuses_like_the_page(): void
    {
        legacy('backup');
        $dir = backup_dir();
        $before = glob($dir . '/*') ?: [];
        $src = $this->otherDatabaseBackup();
        /* read from a copy: phar keeps a stale index for the path it just compressed (see backup_create) */
        copy($src . '.tar.gz', $src . '-usb.tar.gz');
        file_put_contents($dir . '/venusep-backup-20000103-000000-manual.tar.gz', 'x');
        $db = DB::connection()->getDatabaseName();
        try {
            $this->artisan('venusep:restore')->expectsOutputToContain('venusep-backup-20000103-000000-manual.tar.gz')->assertExitCode(0);
            $this->artisan('venusep:restore', ['file' => 'nope.tar.gz'])->expectsOutputToContain('No such backup')->assertExitCode(1);
            $this->artisan('venusep:restore', ['file' => 'venusep-backup-20000103-000000-manual.tar.gz'])
                ->expectsOutputToContain('not a VENUSeP backup')->assertExitCode(1);
            $this->artisan('venusep:restore', ['file' => $src . '-usb.tar.gz'])
                ->expectsQuestion('Type the database name (' . $db . ') to go on', 'wrong')
                ->expectsOutputToContain('Nothing was restored')->assertExitCode(1);
            $this->artisan('venusep:restore', ['file' => $src . '-usb.tar.gz', '--force' => true])
                ->expectsOutputToContain('Restoring it would overwrite "venusep_admina_zz_other", so it was refused')->assertExitCode(1);
            $made = array_diff(glob($dir . '/*') ?: [], $before);
            $this->assertSame([], array_values(array_filter($made, fn ($f) => str_contains($f, 'pre-restore'))), 'nothing got as far as a safety copy');
        } finally {
            foreach (array_diff(glob($dir . '/*') ?: [], $before) as $f) {
                @unlink($f);
            }
            foreach ([$src . '/manifest.json', $src . '/database.sql', $src . '.tar', $src . '.tar.gz', $src . '-usb.tar.gz'] as $f) {
                @unlink($f);
            }
            @rmdir($src);
        }
    }

    /* ...while a real backup of this system's own database passes that check. */
    public function test_a_real_dump_names_only_this_database(): void
    {
        legacy('backup');
        $res = $this->actingAsAdmin()->withTestCsrf()
            ->post(route('admin.backup-action'), ['_token' => 'test-csrf-token', 'action' => 'create']);
        $res->assertOk();
        $path = backup_path($res->json('file'));
        $sql = sys_get_temp_dir() . '/venusep-admina-' . bin2hex(random_bytes(4)) . '.sql';
        try {
            file_put_contents($sql, (new \PharData($path))['database.sql']->getContent());
            $this->assertStringContainsString('USE `' . DB::connection()->getDatabaseName() . '`', file_get_contents($sql));
            $this->assertNull(backup_dump_other_database($sql, DB::connection()->getDatabaseName()));
            $this->assertSame(DB::connection()->getDatabaseName(), backup_dump_other_database($sql, 'venusep'));
        } finally {
            backup_delete(basename($path));
            @unlink($sql);
        }
    }

    public function test_staff_makes_a_backup_admin_downloads_and_deletes_it(): void
    {
        $this->assertStringStartsWith('venusep_laravel_test', DB::connection()->getDatabaseName());   // never the real database

        $res = $this->actingAsAdmin()->withTestCsrf()
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
