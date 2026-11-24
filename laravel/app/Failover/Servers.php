<?php

namespace App\Failover;

use PDO;
use PDOException;
use Throwable;

/* The database servers of the laptop group (failover plan §2): Test, Find, Switch, Make it a
   copy, Rebuild, and "saving without a copy". Each call opens its own short connection to
   the server it talks to (3 s timeout), signed in as the admin account (config
   venusep.db_admin_*, the website's own until set): it reads and changes replication.
   Every step is one the labs proved on MariaDB 10.4.32 (_tools/lab/two and four,
   LAB-REPORT.md), and every step is safe to run twice, so a Switch that stopped half-way is
   fixed by pressing Switch again. All servers use the same port (DB_PORT). */
final class Servers
{
    /* ---- pure helpers (tests/Unit/Failover) ---- */

    /* "0-1-45" or "0-1-45,1-2-7" -> 45: the sequence number in domain 0, the only one VENUSeP uses. */
    public static function seq(string $gtidPos): int
    {
        foreach (explode(',', $gtidPos) as $g) {
            $p = explode('-', trim($g));
            if (count($p) === 3 && $p[0] === '0') {
                return (int) $p[2];
            }
        }
        return 0;
    }

    /* A copy that took a change of its own (someone wrote to it directly): what it has
       applied differs from what it received from a main (lab two §7). The main always wins,
       so such a copy is rebuilt, never switched to. */
    public static function ownChanges(string $currentPos, string $slavePos): bool
    {
        $norm = function (string $p) {
            $a = array_filter(array_map('trim', explode(',', $p)));
            sort($a);
            return implode(',', $a);
        };
        return $norm($currentPos) !== $norm($slavePos);
    }

    /* Of the copies' reports, the one that holds the most changes (emergency switch). */
    public static function furthest(array $reports): ?string
    {
        $best = null;
        foreach ($reports as $r) {
            if (($r['role'] ?? null) === 'copy' && ($best === null || self::seq($r['slavePos']) > self::seq($best['slavePos']))) {
                $best = $r;
            }
        }
        return $best['ip'] ?? null;
    }

    /* ---- this laptop ---- */

    /* This laptop's addresses on a private network (the hotspot). First the one its traffic
       leaves by (a UDP "connect" sends nothing, it only picks the route), so a VirtualBox or
       VPN adapter is not shown as the address. */
    public static function myAddresses(): array
    {
        static $ips = null;
        if ($ips !== null) {
            return $ips;
        }
        $all = (array) @gethostbynamel(gethostname());
        if ($u = @stream_socket_client('udp://8.8.8.8:53', $no, $err, 1)) {
            array_unshift($all, explode(':', (string) stream_socket_get_name($u, false))[0]);
            fclose($u);
        }
        return $ips = array_values(array_unique(array_filter($all,
            fn ($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE))));
    }

    public static function main(): string
    {
        return (string) config('database.connections.mysql.host');
    }

    /* The main, also when one side says 127.0.0.1 and the other this laptop's network address. */
    public static function isMain(string $ip): bool
    {
        return $ip === self::main() || (self::isLocal($ip) && self::isLocal(self::main()));
    }

    public static function isLocal(string $ip): bool
    {
        return self::isLoopback($ip) || in_array($ip, self::myAddresses(), true);
    }

    public static function isLoopback(string $ip): bool
    {
        return in_array($ip, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /* ---- Test ---- */

    /* One server's report: what the page shows and what Switch relies on.
       role: main (takes the saves) | copy | locked (read-only and copying from nobody: a main
       after a restart, or an old main that came back) | null (not reachable). */
    public static function probe(string $ip): array
    {
        legacy('backup');   // backup_schema_version()
        $r = ['ip' => $ip, 'reachable' => false, 'level' => null, 'role' => null, 'readOnly' => null,
            'running' => false, 'behind' => null, 'own' => false, 'of' => null, 'error' => null,
            'slavePos' => '', 'ioPos' => '', 'checks' => [], 'safe' => false, 'current' => self::isMain($ip)];
        try {
            $db = self::pdo($ip);
            $r['reachable'] = true;
            $r['level'] = (int) $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'schema_version'")->fetchColumn();
            $slave = $db->query('SHOW SLAVE STATUS')->fetch() ?: null;
            $r['readOnly'] = (int) $db->query('SELECT @@read_only')->fetchColumn() === 1;   // in its own query (lab four)
            $r['role'] = $slave ? 'copy' : ($r['readOnly'] ? 'locked' : 'main');
            if ($slave) {
                $r['of'] = (string) $slave['Master_Host'];
                $r['running'] = $slave['Slave_IO_Running'] === 'Yes' && $slave['Slave_SQL_Running'] === 'Yes';
                $r['error'] = trim($slave['Last_SQL_Error'] ?: $slave['Last_IO_Error']) ?: null;
                $r['slavePos'] = (string) $db->query('SELECT @@gtid_slave_pos')->fetchColumn();
                $r['ioPos'] = (string) $slave['Gtid_IO_Pos'];
                $r['own'] = self::ownChanges((string) $db->query('SELECT @@gtid_current_pos')->fetchColumn(), $r['slavePos'])
                    || in_array((int) $slave['Last_SQL_Errno'], [1950], true) || in_array((int) $slave['Last_IO_Errno'], [1236], true);
                try {
                    $mainPos = (string) self::pdo($r['of'])->query('SELECT @@gtid_binlog_pos')->fetchColumn();
                    $r['behind'] = max(0, self::seq($mainPos) - self::seq($r['slavePos']));
                } catch (Throwable) {
                    $r['behind'] = null;   // its main is gone: judged by what it received (emergency)
                }
            }
        } catch (Throwable $e) {
            $r['checks'][] = [false, self::why($e)];
            return $r;
        }
        return self::judge($r);
    }

    /* The check lines and "safe to switch to", from a report. */
    private static function judge(array $r): array
    {
        $need = backup_schema_version();
        $c = [[$r['level'] === $need, $r['level'] === $need
            ? "A VENUSeP database, at migration {$need}, the same as this system"
            : ($r['level'] ? "At migration {$r['level']}; this system needs {$need}" : 'A VENUSeP database without its migration number: run migration 22 on it')]];
        $current = self::isMain($r['ip']);
        if ($r['role'] === 'main') {
            $c[] = [true, $current ? 'The main: it takes every save' : 'Takes saves: this system can point at it'];
        } elseif ($r['role'] === 'locked') {
            $c[] = [$current, $current ? 'Locked after a restart: switch to it to unlock saving' : 'Locked and copying from nobody: make it a copy first'];
        } else {
            $applied = self::seq($r['ioPos']) <= self::seq($r['slavePos']);
            $c[] = match (true) {
                !$r['running'] && $r['behind'] !== null => [false, 'Copy stopped' . ($r['error'] ? ': ' . $r['error'] : '')],
                $r['behind'] === null => [$applied, $applied ? 'A copy; its main is not answering, and it applied everything it received' : 'A copy; its main is not answering, and it is still applying changes'],
                $r['behind'] > 0 => [false, $r['behind'] . ($r['behind'] === 1 ? ' change' : ' changes') . ' behind the main'],
                default => [true, 'A copy, up to date with the main'],
            };
            $c[] = [$r['readOnly'], $r['readOnly'] ? 'Locked: nobody can change it directly' : 'Not locked: someone could change it directly'];
            $c[] = [!$r['own'], $r['own'] ? 'Has changes of its own: rebuild it' : 'No changes of its own'];
        }
        $r['checks'] = $c;
        $r['safe'] = !in_array(false, array_column($c, 0), true) && !($r['role'] === 'main' && $current);
        return $r;
    }

    private static function why(Throwable $e): string
    {
        $code = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
        return match (true) {
            $code === 1049 => 'No VENUSeP database on this server',
            $code === 1045, $code === 1130 => 'The server answered but would not let this system sign in (check its accounts, SETUP.md)',
            $code === 1146 => 'A database with this name, but not a VENUSeP one',
            default => 'Can’t reach it: is the laptop on, on the hotspot, and is MySQL running?',
        };
    }

    /* ---- Find ---- */

    /* Addresses on this laptop's own /24 where MySQL answers. Only ever this laptop's own
       network (a phone hotspot), never wider; about 5 seconds. Not for school Wi-Fi. */
    public static function find(): array
    {
        $found = [];
        foreach (self::myAddresses() as $mine) {
            $net = substr($mine, 0, strrpos($mine, '.') + 1);
            foreach (array_chunk(range(1, 254), 64) as $batch) {
                $open = [];
                foreach ($batch as $n) {
                    $s = @stream_socket_client("tcp://{$net}{$n}:" . self::port(), $no, $err, 0.6, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT);
                    if ($s) {
                        $open[$net . $n] = $s;
                    }
                }
                $until = microtime(true) + 0.6;
                while ($open && microtime(true) < $until) {
                    $w = array_values($open);
                    $r = $e = null;
                    if (!@stream_select($r, $w, $e, 0, 100_000)) {
                        continue;
                    }
                    foreach ($w as $s) {
                        $ip = array_search($s, $open, true);
                        if (@stream_socket_get_name($s, true) !== false) {
                            $found[] = $ip;
                        }
                        fclose($s);
                        unset($open[$ip]);
                    }
                }
                array_map('fclose', $open);
            }
        }
        return array_values(array_unique($found));
    }

    /* ---- Switch ---- */

    /* Make $target the main. Planned (the main answers) or emergency (it doesn't). Returns
       ['ok' => bool, 'steps' => [[name, ok, note]...], 'mode' => planned|emergency|unlock].
       At every moment at most one server takes saves: the old main is locked before the
       target is unlocked, and the address is saved only after the target is unlocked. */
    public static function switchTo(string $target, array $laptops): array
    {
        $main = self::main();
        $steps = [];
        /* $soft: the switch still counts if it fails (a copy that is off); shown as "needs attention" (null) */
        $step = function (string $name, callable $do, bool $soft = false) use (&$steps): bool {
            try {
                $note = $do();
                $steps[] = [$name, true, is_string($note) ? $note : null];
                return true;
            } catch (Throwable $e) {
                $steps[] = [$name, $soft ? null : false, $e instanceof PDOException ? self::why($e) : $e->getMessage()];
                return false;
            }
        };
        $done = function (string $mode) use (&$steps): array {   // by reference: an arrow fn would copy the empty list
            return ['ok' => $steps && !in_array(false, array_column($steps, 1), true), 'steps' => $steps, 'mode' => $mode];
        };

        $t = self::probe($target);
        if (!$t['safe']) {
            $steps[] = ['Test ' . $target, false, implode('; ', array_column(array_filter($t['checks'], fn ($c) => !$c[0]), 1)) ?: 'It is not safe to switch to.'];
            return $done('none');
        }
        $to = self::pdo($target);

        /* the main itself, locked after a restart: only unlock it */
        if (self::isMain($target)) {
            $step('Unlock ' . $target, fn () => $to->exec('SET GLOBAL read_only = 0'));
            return $done('unlock');
        }
        /* already a main (another laptop switched to it): only point this system at it, never two mains */
        if ($t['role'] === 'main') {
            $oldOpen = (function () use ($main) { try { return (int) self::pdo($main)->query('SELECT @@read_only')->fetchColumn() === 0; } catch (Throwable) { return false; } })();
            if ($oldOpen) {
                $steps[] = ['Check for two mains', false, $main . ' still takes saves too. Switch from the laptop that holds the newest data.'];
                return $done('none');
            }
            $step('Save the new address', function () use ($target, $laptops) { ServerFile::write(['db_host' => $target, 'laptops' => array_values(array_unique([...$laptops, $target]))], 'switch'); });
            return $done('point');
        }

        try {
            $old = self::pdo($main);
        } catch (Throwable) {
            $old = null;
        }
        if ($old !== null) {
            if (!$step('Stop saving on the old main (' . $main . ')', fn () => $old->exec('SET GLOBAL read_only = 1'))) {
                return $done('planned');
            }
            $pos = (string) $old->query('SELECT @@gtid_binlog_pos')->fetchColumn();
            if (!$step('Wait until ' . $target . ' has every change', fn () => self::waitFor($to, $pos))) {
                $old->exec('SET GLOBAL read_only = 0');   // undo: the old main takes saves again
                return $done('planned');
            }
        } else {
            /* emergency: only the copy holding the most changes may take over */
            $copies = array_map([self::class, 'probe'], array_values(array_diff($laptops, [$main])));
            $best = self::furthest($copies);
            if ($best !== $target) {
                $steps[] = ['Pick the copy with the most changes', false, $best ? $best . ' has more changes: switch to that one.' : 'No copy answered.'];
                return $done('emergency');
            }
            if (!$step('Apply everything ' . $target . ' received', fn () => self::waitFor($to, $t['ioPos']))) {
                return $done('emergency');
            }
        }

        if (!$step('Make ' . $target . ' the main', function () use ($to) {
            $to->exec('STOP SLAVE');
            $to->exec('RESET SLAVE ALL');
            $to->exec('SET GLOBAL read_only = 0');
        })) {
            return $done($old ? 'planned' : 'emergency');
        }
        if ($old !== null) {
            $step('The old main (' . $main . ') becomes a copy', fn () => self::follow($old, $target, true), true);
        }
        foreach (array_diff($laptops, [$main, $target]) as $ip) {
            $step($ip . ' copies from ' . $target, fn () => self::follow(self::pdo($ip), $target, false), true);
        }
        $step('Save the new address', function () use ($target, $laptops) { ServerFile::write(['db_host' => $target, 'laptops' => array_values(array_unique([...$laptops, $target]))], 'switch'); });
        return $done($old ? 'planned' : 'emergency');
    }

    /* Point a server at $main and start copying. $fresh: it never copied from this main
       before (an old main), so it starts from what it already has (lab two: always set
       gtid_slave_pos first; RESET SLAVE ALL keeps an old one). Locked afterwards, and checked
       3 s later: lab two saw read_only flip back to 0. */
    private static function follow(PDO $db, string $main, bool $fresh): ?string
    {
        $db->exec('STOP SLAVE');
        $db->exec('SET GLOBAL read_only = 1');
        if ($fresh) {
            $db->exec('SET GLOBAL gtid_slave_pos = @@gtid_binlog_pos');
        }
        $db->prepare('CHANGE MASTER TO MASTER_HOST = ?, MASTER_PORT = ?, MASTER_USER = ?, MASTER_PASSWORD = ?, MASTER_USE_GTID = slave_pos')
            ->execute([$main, self::port(), (string) config('venusep.db_repl_user'), (string) config('venusep.db_repl_pass')]);
        $db->exec('START SLAVE');
        sleep(3);
        $s = $db->query('SHOW SLAVE STATUS')->fetch() ?: [];
        if (in_array((int) ($s['Last_SQL_Errno'] ?? 0), [1950], true) || in_array((int) ($s['Last_IO_Errno'] ?? 0), [1236], true)) {
            $db->exec('STOP SLAVE');
            throw new \RuntimeException('It has changes the main never got. The main always wins: rebuild it.');
        }
        if ((int) $db->query('SELECT @@read_only')->fetchColumn() !== 1) {
            $db->exec('SET GLOBAL read_only = 1');
        }
        return ($s['Slave_IO_Running'] ?? '') === 'Yes' ? null : 'Started; it is still connecting (' . ($s['Last_IO_Error'] ?? '') . ')';
    }

    private static function waitFor(PDO $db, string $pos): void
    {
        $st = $db->prepare('SELECT MASTER_GTID_WAIT(?, 10)');
        $st->execute([$pos]);
        if ((int) $st->fetchColumn() !== 0) {
            throw new \RuntimeException('It did not catch up within 10 seconds. Nothing was switched.');
        }
    }

    /* ---- Make it a copy, Rebuild ---- */

    public static function makeCopy(string $ip): array
    {
        try {
            if (self::isMain($ip)) {
                return ['ok' => false, 'message' => 'That is the main.'];
            }
            return ['ok' => true, 'message' => self::follow(self::pdo($ip), self::main(), true) ?? 'It copies from the main now.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e instanceof PDOException ? self::why($e) : $e->getMessage()];
        }
    }

    /* Throw the copy's database away and load the main's (the "has changes of its own" case).
       The same commands as SETUP.md's first copy: the dump carries where to start copying. */
    public static function rebuild(string $ip): array
    {
        legacy('backup');
        $main = self::main();
        if (self::isMain($ip)) {
            return ['ok' => false, 'message' => 'That is the main.'];
        }
        $cfg = venusep_db_admin_config();
        $file = tempnam(sys_get_temp_dir(), 'venusep-copy-');
        try {
            self::pdo($ip)->exec('STOP SLAVE');
            $dump = backup_mysql_bin('mysqldump');
            $mysql = backup_mysql_bin('mysql');
            $err = backup_run([$dump, '--host=' . $main, '--port=' . self::port(), '--user=' . $cfg['user'], '--single-transaction', '--gtid',
                '--master-data=1', '--routines', '--triggers', '--add-drop-database', '--databases', $cfg['name']], null, $file)
                ?: backup_run([$mysql, '--host=' . $ip, '--port=' . self::port(), '--user=' . $cfg['user'],
                    '--init-command=SET sql_log_bin=0', '--default-character-set=utf8mb4'], $file);
            if ($err !== '') {
                return ['ok' => false, 'message' => 'The copy could not be loaded: ' . $err];
            }
            return ['ok' => true, 'message' => self::follow(self::pdo($ip), $main, false) ?? 'Rebuilt: it copies from the main again.'];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e instanceof PDOException ? self::why($e) : $e->getMessage()];
        } finally {
            @unlink($file);
        }
    }

    public static function lock(string $ip, bool $locked = true): void
    {
        self::pdo($ip)->exec('SET GLOBAL read_only = ' . ($locked ? 1 : 0));
    }

    /* ---- Saving without a copy ---- */

    /* true = protected: a save waits until a copy has it (semi-sync on the main). */
    public static function protectedSaving(): ?bool
    {
        try {
            $db = self::pdo(self::main());
            if ((int) $db->query('SELECT @@log_bin')->fetchColumn() !== 1) {
                return null;   // no binary log: copying is not set up on this laptop (SETUP.md)
            }
            return (int) $db->query('SELECT @@rpl_semi_sync_master_enabled')->fetchColumn() === 1;
        } catch (Throwable) {
            return null;
        }
    }

    /* Off = "allow saving without a copy": also lifts the pause Health puts on when no copy is connected. */
    public static function setProtected(bool $on): void
    {
        $db = self::pdo(self::main());
        $db->exec('SET GLOBAL rpl_semi_sync_master_enabled = ' . ($on ? 1 : 0));
        if (!$on && \Illuminate\Support\Facades\Cache::pull(Health::PAUSED)) {
            $db->exec('SET GLOBAL read_only = 0');
        }
    }

    /* ---- connection ---- */

    private static function port(): int
    {
        return (int) config('database.connections.mysql.port', 3306);
    }

    private static function pdo(string $ip): PDO
    {
        legacy('db');
        $c = venusep_db_admin_config();
        return new PDO('mysql:host=' . $ip . ';port=' . self::port() . ';dbname=' . $c['name'] . ';charset=utf8mb4', $c['user'], $c['pass'], [
            PDO::ATTR_TIMEOUT => 3,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
