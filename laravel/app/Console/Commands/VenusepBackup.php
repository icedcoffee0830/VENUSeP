<?php

namespace App\Console\Commands;

use App\Queries\ActivityQueries;
use Illuminate\Console\Command;

/* The nightly backup. Windows Task Scheduler runs this; see SETUP.md. */
class VenusepBackup extends Command
{
    protected $signature = 'venusep:backup';
    protected $description = 'Make a backup now (the nightly task runs this)';

    public function handle(): int
    {
        legacy('auth', 'backup');
        $err = '';
        $file = backup_create('auto', 0, 'automatic daily backup', $err);
        if ($file === null) {
            $this->error('The backup failed: ' . $err);
            return self::FAILURE;
        }
        backup_prune();
        ActivityQueries::add('backup_made', 'Backup', 'The nightly task made the backup ' . $file, 0);
        $this->info('Backup made: ' . $file);
        return self::SUCCESS;
    }
}
