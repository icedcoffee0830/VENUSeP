<?php

namespace App\Console\Commands;

use App\Failover\FileCopies;
use App\Failover\Health;
use App\Failover\SuperAdminCopy;
use Illuminate\Console\Command;
use Throwable;

/* Every minute, on every laptop of the group (Windows Task Scheduler, SETUP.md): keep this
   laptop ready for an emergency. Each part runs on its own, so one failing never stops the next. */
class VenusepFailoverTick extends Command
{
    protected $signature = 'venusep:failover-tick';
    protected $description = 'Failover upkeep, every minute: the super admin\'s emergency sign-in copy, the file copies and the copies\' health';

    public function handle(): int
    {
        $parts = [
            'emergency sign-in' => fn () => SuperAdminCopy::refresh(),
            'files' => fn () => FileCopies::sweep(),
            'copies' => fn () => Health::check(),
            /* emails another laptop could not send (no mail settings there): sent from here if this one can */
            'emails' => function () { legacy('receipt-emails'); return mail_send_waiting() . ' waiting email(s) sent'; },
        ];
        $failed = false;
        foreach ($parts as $name => $run) {
            try {
                $this->line($name . ': ' . $run());
            } catch (Throwable $e) {
                $failed = true;
                $this->error($name . ': ' . $e->getMessage());   // the database is down: keep the last copy
            }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
