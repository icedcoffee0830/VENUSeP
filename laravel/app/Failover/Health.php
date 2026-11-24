<?php

namespace App\Failover;

use App\Queries\ActivityQueries;
use Illuminate\Support\Facades\Cache;
use Throwable;

/* "Is every copy still copying?" (failover plan §4). The 1-minute task asks; pages only read
   the answer from the cache, so a page view never queries another laptop.
   Semi-sync on the main only proves a copy RECEIVED a change, not that it applied it (lab
   two §4), so each copy is tested too. A copy found unlocked is locked again (lab two saw
   read_only flip back to 0). While saving is unprotected, protection comes back on its own
   as soon as a copy is connected again (decided 2026-11-16). */
final class Health
{
    private const KEY = 'failover.health';
    private const PAUSED = 'failover.paused';   // the main was locked because no copy was connected

    /* Problems, in words; [] = healthy. Only for a group of two or more laptops. */
    public static function check(): string
    {
        $copies = array_values(array_filter(ServerFile::laptops(), fn ($ip) => !Servers::isMain($ip)));
        if (!$copies) {
            Cache::forget(self::KEY);
            return 'no copies in the list';
        }
        $problems = [];
        $connected = 0;
        foreach ($copies as $name) {
            $r = Servers::probe($name);
            if (!$r['reachable']) {
                $problems[] = "The copy on {$name} is not answering. Check that the laptop is on, on the hotspot, and MySQL is running.";
            } elseif ($r['own']) {
                $problems[] = "The copy on {$name} has changes of its own. Rebuild it on the Server page.";
            } elseif ($r['role'] !== 'copy' || !$r['running']) {
                $problems[] = "The live copy on {$name} stopped" . ($r['error'] ? ': ' . $r['error'] : '.') . ' It is not a safe copy right now.';
            } else {
                $connected++;
                if (!$r['readOnly']) {
                    Servers::lock($name);
                    $problems[] = "The copy on {$name} was unlocked; it was locked again.";
                }
            }
        }
        /* No copy connected while saving is protected: lock the main instead of letting every save
           wait. The website answers one request at a time, so one waiting save would freeze the whole
           site, the Server page included. Locked, saves get "not saved, try again" and reads work. */
        $protected = Servers::protectedSaving();
        if ($connected === 0 && $protected === true && !Cache::get(self::PAUSED)) {
            Servers::lock(Servers::main());
            Cache::forever(self::PAUSED, true);
            ActivityQueries::add('saving_paused', 'Database server', 'No copy is connected: saving is paused until one is back', 0);
        }
        if ($connected === 0 && Cache::get(self::PAUSED)) {
            $problems[] = 'Saving is paused: no copy is connected. Bring a copy back, or use “Allow saving without a copy” on the Server page.';
        }
        if ($connected > 0 && $protected === false) {
            Servers::setProtected(true);
            ActivityQueries::add('saving_protected', 'Database server', 'A copy is back: saving waits for a copy again', 0);
        }
        if ($connected > 0 && self::resume()) {
            ActivityQueries::add('saving_resumed', 'Database server', 'A copy is back: saving works again', 0);
        }
        Cache::put(self::KEY, ['problems' => $problems, 'at' => time()], 180);
        return $problems ? count($problems) . ' problem(s): ' . implode(' ', $problems) : 'every copy is copying';
    }

    /* Lift the pause (a copy is back, or the super admin allowed saving without a copy). True = there was one. */
    public static function resume(): bool
    {
        if (!Cache::pull(self::PAUSED)) {
            return false;
        }
        Servers::lock(Servers::main(), false);
        return true;
    }

    /* For the banner: problems, [] = fine, or null = nothing heard for 3 minutes in a group of
       two or more laptops (the task is not running). False = no group, show nothing. */
    public static function last(): array|null|false
    {
        if (count(ServerFile::laptops()) < 2) {
            return false;
        }
        return Cache::get(self::KEY)['problems'] ?? null;
    }
}
