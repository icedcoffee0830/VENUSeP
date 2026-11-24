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
        if ($connected > 0 && Servers::protectedSaving() === false) {
            Servers::setProtected(true);
            ActivityQueries::add('saving_protected', 'Database server', 'A copy is back: saving waits for a copy again', 0);
        }
        Cache::put(self::KEY, ['problems' => $problems, 'at' => time()], 180);
        return $problems ? count($problems) . ' problem(s): ' . implode(' ', $problems) : 'every copy is copying';
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
