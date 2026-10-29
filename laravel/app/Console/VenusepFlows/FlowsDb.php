<?php

namespace App\Console\VenusepFlows;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/* The flow tester's only door to a database (php artisan venusep:flows).
   Every method refuses a database whose name does not start with one of the
   ALLOWED prefixes — the real `venusep` and the dev copy `venusep_laravel` can
   never be reset, re-passworded or queried from here. */
final class FlowsDb
{
    public const ALLOWED = ['venusep_laravel_flows', 'venusep_laravel_test'];

    /* every account that has a password gets this one on the flows copy */
    public const PASSWORD = 'FlowTest#2026';

    public const DEFAULT_SNAPSHOT = 'C:/xamp5/htdocs/VENUSeP-laravel-helpers/_tools/venusep_snapshot.sql';

    public const DEFAULT_MYSQL = 'C:/xamp5/mysql/bin/mysql.exe';

    private const CONN = 'venusep_flows';

    public static function allowed(string $db): bool
    {
        $db = strtolower(trim($db));
        if ($db === '' || !preg_match('/^[a-z0-9_]+$/', $db)) {
            return false;
        }
        foreach (self::ALLOWED as $prefix) {
            if (str_starts_with($db, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function guard(string $db): void
    {
        if (!self::allowed($db)) {
            throw new RuntimeException("Refusing the database '{$db}': the flow tester only uses databases whose name starts with "
                . implode(' or ', self::ALLOWED) . '.');
        }
    }

    /* A connection to $db with the default connection's host and credentials. */
    public static function connection(string $db): Connection
    {
        self::guard($db);
        $base = config('database.connections.' . config('database.default'));
        config(['database.connections.' . self::CONN => array_merge($base, ['database' => $db])]);
        DB::purge(self::CONN);
        $conn = DB::connection(self::CONN);
        $actual = (string) $conn->selectOne('SELECT DATABASE() AS d')->d;
        self::guard($actual);

        return $conn;
    }

    /* Only one plain SELECT (or WITH … SELECT), run inside a READ ONLY transaction. */
    public static function assertSelect(string $sql): string
    {
        $s = trim(preg_replace('~/\*.*?\*/|--[^\n]*|#[^\n]*~s', ' ', $sql));
        $s = rtrim($s, "; \t\r\n");
        if (!preg_match('/^(select|with)\b/i', $s)) {
            throw new RuntimeException('db() is read-only: only a SELECT is allowed.');
        }
        if (str_contains($s, ';')) {
            throw new RuntimeException('db() runs one statement: remove the ";".');
        }
        if (preg_match('/\b(into\s+(outfile|dumpfile|@)|for\s+update|lock\s+in\s+share\s+mode|sleep\s*\(|benchmark\s*\()/i', $s)) {
            throw new RuntimeException('db() is read-only: that SELECT form is not allowed.');
        }

        return $s;
    }

    /** @return list<array<string, mixed>> */
    public static function select(string $db, string $sql, array $params = []): array
    {
        $s = self::assertSelect($sql);
        $conn = self::connection($db);
        $conn->statement('START TRANSACTION READ ONLY');
        try {
            $rows = $conn->select($s, $params);
        } finally {
            $conn->statement('ROLLBACK');
        }

        return array_map(fn ($r) => (array) $r, $rows);
    }

    /* Load the snapshot into $db (it drops and re-creates every table, view and
       routine), then apply the flow's settings and the known password. The
       database must already exist with utf8mb4_unicode_ci (the stored triggers
       break under the server default general_ci). */
    public static function reset(string $db, string $snapshot, string $mysql, array $settings = []): void
    {
        self::guard($db);
        if (!is_file($snapshot)) {
            throw new RuntimeException("Snapshot not found: {$snapshot} (use --snapshot or FLOWS_SNAPSHOT).");
        }
        $conn = self::connection($db);
        $coll = $conn->selectOne('SELECT DEFAULT_COLLATION_NAME AS c FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?', [$db]);
        if (!$coll || $coll->c !== 'utf8mb4_unicode_ci') {
            throw new RuntimeException("The database '{$db}' must use utf8mb4_unicode_ci (it uses " . ($coll->c ?? '?') . '). Re-create it: '
                . "CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
        }

        $cfg = $conn->getConfig();
        $cmd = [$mysql, '--user=' . $cfg['username'], '--host=' . $cfg['host'], '--port=' . $cfg['port'], '--default-character-set=utf8mb4'];
        if ((string) ($cfg['password'] ?? '') !== '') {
            $cmd[] = '--password=' . $cfg['password'];
        }
        $cmd[] = $db;
        $in = fopen($snapshot, 'rb');
        $proc = new Process($cmd, null, null, $in, 300);
        $proc->run();
        fclose($in);
        if (!$proc->isSuccessful()) {
            throw new RuntimeException('Loading the snapshot failed: ' . trim($proc->getErrorOutput() ?: $proc->getOutput()));
        }

        $conn = self::connection($db);   // fresh connection: the tables were just re-created
        foreach ($settings as $key => $value) {
            $n = $conn->update('UPDATE system_settings SET setting_value = ? WHERE setting_key = ?', [(string) $value, (string) $key]);
            if ($n === 0 && !$conn->selectOne('SELECT 1 AS x FROM system_settings WHERE setting_key = ?', [(string) $key])) {
                throw new RuntimeException("Unknown setting '{$key}' (see the system_settings table).");
            }
        }
        $conn->update('UPDATE users SET password_hash = ? WHERE password_hash IS NOT NULL',
            [password_hash(self::PASSWORD, PASSWORD_ARGON2ID)]);
    }

    /* The current authenticator code for $email (from its stored TOTP secret). */
    public static function totp(string $db, string $email, ?int $step = null): array
    {
        $secret = self::connection($db)->selectOne('SELECT totp_secret AS s FROM users WHERE email = ?', [$email])->s ?? null;
        if (!$secret) {
            throw new RuntimeException("{$email} has no TOTP secret.");
        }
        legacy('totp');
        $step ??= intdiv(time(), 30);

        return ['code' => totp_code($secret, $step), 'step' => $step];
    }
}
