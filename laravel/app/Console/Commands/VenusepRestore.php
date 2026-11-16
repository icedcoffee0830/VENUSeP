<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/* Restore a backup from the command line: the way back when nobody can sign in
   (the Backup & Restore page needs a working database to sign in at all).
   Same engine as the page: backup_restore() refuses another database or another
   layout version, makes a safety copy first, then signs everyone out. */
class VenusepRestore extends Command
{
    protected $signature = 'venusep:restore
        {file? : a backup name from the list, or the path to a .tar.gz (a USB copy)}
        {--no-safety-copy : skip the safety copy, for a database too damaged to back up}
        {--force : do not ask (for scripts)}';

    protected $description = 'Restore a backup without signing in (no file: list the backups)';

    public function handle(): int
    {
        legacy('auth', 'backup');
        $file = $this->argument('file');
        if ($file === null) {
            $rows = array_map(fn ($b) => [$b['file'], $b['meta']['created_at'] ?? '', $b['meta']['created_by'] ?? ''], backup_list());
            $rows ? $this->table(['Backup', 'Made', 'By'], $rows) : $this->line('No backups yet.');
            $this->line('Restore one with: php artisan venusep:restore <backup name>');
            return self::SUCCESS;
        }

        $path = backup_path($file) ?? (is_file($file) ? realpath($file) : null);
        $manifest = $path !== null ? backup_read_manifest($path) : null;
        if ($manifest === null) {
            $this->error($path === null ? 'No such backup: ' . $file : 'That file is not a VENUSeP backup.');
            return self::FAILURE;
        }

        $db = venusep_db_config()['name'];
        $this->warn('This replaces ALL data in "' . $db . '" (and the uploaded files and photos) with the backup made '
            . ($manifest['created_at'] ?? '?') . '. Everyone is signed out.');
        $safety = !$this->option('no-safety-copy');
        if (!$safety) {
            $this->warn('No safety copy will be made: what is in the database now is lost.');
        }
        if (!$this->option('force') && $this->ask('Type the database name (' . $db . ') to go on') !== $db) {
            $this->line('Nothing was restored.');
            return self::FAILURE;
        }

        $pre = backup_restore($path, 0, 'command line', $err, $safety);
        if ($pre === null) {
            $this->error($err);
            if ($safety && str_starts_with($err, 'A safety backup')) {
                $this->line('If the database is too damaged to back up, run again with --no-safety-copy.');
            }
            return self::FAILURE;
        }
        error_log('VENUSeP: backup ' . basename($path) . ' restored from the command line; safety copy ' . ($pre ?: 'skipped'));
        $this->info('Restored. ' . ($pre !== '' ? 'The data from just before is in ' . $pre . '.' : 'No safety copy was made.'));
        return self::SUCCESS;
    }
}
