<?php

namespace App\Console\Commands;

use App\Failover\Group;
use Illuminate\Console\Command;

/* start-venusep.bat runs this after MySQL and the website are up: find the other laptops on
   this hotspot by name, work out the main, bring every address up to date (App\Failover\Group). */
class VenusepFailoverStart extends Command
{
    protected $signature = 'venusep:failover-start';
    protected $description = 'Find the other laptops on this hotspot and bring every address up to date';

    public function handle(): int
    {
        foreach (Group::start() as $line) {
            $this->line('  ' . $line);
        }
        return self::SUCCESS;
    }
}
