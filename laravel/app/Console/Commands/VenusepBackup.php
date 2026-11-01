<?php

namespace App\Console\Commands;

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
        $file = backup_create('auto', 0, 'nightly task', $err);
        if ($file === null) {
            $this->error('The backup failed: ' . $err);
            return self::FAILURE;
        }
        backup_prune();
        $this->info('Backup made: ' . $file);
        return self::SUCCESS;
    }
}
