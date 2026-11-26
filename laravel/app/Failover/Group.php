<?php

namespace App\Failover;

use App\Queries\ActivityQueries;
use Throwable;

/* "Find each other on start" (start-venusep.bat, artisan venusep:failover-start). The phone's
   hotspot hands out new addresses almost every time it is switched on, so a laptop is known by
   its Windows name, not its address. On start this laptop scans its own hotspot, recognises every
   VENUSeP database by name, works out which one is the main, and brings every address up to date:
   its own website's note, the laptop list, the copies' copying, and the other laptops' notes.
   It never makes two mains: a main is only unlocked when every laptop it knew answers, none of
   them already takes saves, and the main holds the newest data. */
final class Group
{
    /* What to do, from the reports of the servers found (Servers::probe) and the laptop names this
       laptop knew before (pure: tests/Unit/Failover). Returns
       ['main' => ip|null, 'unlock' => bool, 'repoint' => [ip], 'join' => [ip], 'note' => text|null]. */
    public static function plan(array $reports, array $knownNames, int $expected = 0): array
    {
        $found = array_values(array_filter($reports, fn ($r) => $r['reachable'] && $r['role'] !== null));
        $plan = ['main' => null, 'unlock' => false, 'repoint' => [], 'join' => [], 'note' => null];
        if (!$found) {
            $plan['note'] = 'No VENUSeP database answered on this hotspot (is MySQL running?).';
            return $plan;
        }
        $mains = array_values(array_filter($found, fn ($r) => $r['role'] === 'main'));
        if (count($mains) > 1) {
            $plan['note'] = 'Two laptops take saves: ' . implode(' and ', array_map(fn ($r) => $r['name'] . ' (' . $r['ip'] . ')', $mains))
                . '. Nothing was changed; on the Server page, keep the one with the newest bookings.';
            return $plan;
        }
        usort($found, fn ($a, $b) => Servers::seq($b['pos']) <=> Servers::seq($a['pos']));
        $newest = $found[0];
        if ($mains) {
            $main = $mains[0];
        } elseif ($newest['role'] === 'locked') {
            /* locked after a restart, and nobody has newer data: the main of last time */
            $main = $newest;
            $missing = array_diff($knownNames, array_column($found, 'name'));
            if (!$missing && count($found) < $expected) {
                $missing = [($expected - count($found)) . ' more laptop' . ($expected - count($found) === 1 ? '' : 's') . ' from the list'];
            }
            $plan['unlock'] = !$missing;
            if ($missing) {
                $plan['note'] = 'Waiting for ' . implode(', ', $missing) . ': ' . $main['name'] . ' stays locked until every laptop is on. If '
                    . (count($missing) === 1 ? 'it is' : 'they are') . ' off for good, press “Unlock saving” on ' . $main['name'] . '.';
            }
        } else {
            $plan['note'] = 'No main on this hotspot: the newest data is on the copy ' . $newest['name'] . ' (' . $newest['ip']
                . '). If the main broke, switch to that copy on the Server page.';
            return $plan;
        }
        $plan['main'] = $main['ip'];
        foreach ($found as $r) {
            if ($r['ip'] === $main['ip']) {
                continue;
            }
            if ($r['role'] === 'copy' && $r['of'] !== $main['ip']) {
                $plan['repoint'][] = $r['ip'];   // a copy still copying from yesterday's address
            } elseif ($r['role'] === 'locked') {
                $plan['join'][] = $r['ip'];      // an old main that came back
            }
        }
        return $plan;
    }

    /* Find, decide, act. Lines for the start window. */
    public static function start(): array
    {
        $said = [];
        $mine = Servers::myAddresses()[0] ?? null;
        if (!$mine) {
            return ['This laptop is not on a network. Connect to the hotspot and start again.'];
        }
        $said[] = 'This laptop: ' . gethostname() . ' at ' . $mine;
        $ips = array_values(array_unique([$mine, ...Servers::find()]));
        $reports = array_values(array_filter(array_map([Servers::class, 'probe'], $ips), fn ($r) => $r['reachable'] && $r['name']));
        foreach ($reports as $r) {
            $said[] = 'Found ' . $r['name'] . ' at ' . $r['ip'] . ': ' . ['main' => 'takes saves', 'copy' => 'a copy', 'locked' => 'locked'][$r['role']];
        }
        $before = (array) (ServerFile::read()['names'] ?? []);   // ip => name, as last seen
        /* a note from before names were kept has only addresses: wait for as many laptops as it listed */
        $plan = self::plan($reports, array_values($before), $before ? 0 : count(ServerFile::laptops()));

        /* today's addresses; a laptop that is off keeps its name (under its old address), so the
           next start still waits for it before unlocking a main */
        $names = array_combine(array_column($reports, 'ip'), array_column($reports, 'name'));
        $changes = ['laptops' => array_keys($names), 'names' => $names + array_filter($before, fn ($n) => !in_array($n, $names, true))];
        if ($plan['main']) {
            $changes['db_host'] = $plan['main'];
        }
        ServerFile::write($changes, 'start');

        if ($plan['main']) {
            $mainName = $names[$plan['main']];
            $said[] = 'The main is ' . $mainName . ' (' . $plan['main'] . '). This laptop\'s website uses it.';
            if ($plan['unlock']) {
                Servers::lock($plan['main'], false);
                $said[] = 'Unlocked ' . $mainName . ': every laptop answered and it holds the newest data.';
            }
            foreach ($plan['repoint'] as $ip) {
                $r = Servers::copyFrom($ip, $plan['main'], false);
                $said[] = $names[$ip] . ': ' . ($r['ok'] ? 'copies from ' . $mainName . ' at its new address' : 'NOT re-pointed: ' . $r['message']);
            }
            foreach ($plan['join'] as $ip) {
                $r = Servers::copyFrom($ip, $plan['main'], true);
                $said[] = $names[$ip] . ': ' . ($r['ok'] ? 'joined as a copy of ' . $mainName : 'NOT a copy: ' . $r['message']);
            }
            $told = FileCopies::tellMain($plan['main']);
            if ($told) {
                $said[] = 'Told ' . implode(', ', array_map(fn ($ip) => $names[$ip] ?? $ip, $told)) . ' which laptop is the main.';
            }
            try {
                config(['database.connections.mysql.host' => $plan['main']]);
                \Illuminate\Support\Facades\DB::purge('mysql');
                ActivityQueries::add('server_started', 'Database server', implode(' ', array_slice($said, 1)), 0);
            } catch (Throwable) {
                // the main is still locked (waiting for a laptop): nothing to log into yet
            }
        }
        if ($plan['note']) {
            $said[] = $plan['note'];
        }
        return $said;
    }
}
