<?php

namespace App\Failover;

/* THE ONE PLACE the database address can come from besides .env (failover, plan §1).
   storage/app/failover/server.json holds the main database's address; the super admin's
   Switch writes it (a later piece). config/database.php reads dbHost() on every request
   (the team never runs config:cache), so a switch reaches the next request. No file, or a
   file that does not hold an address: null, and .env's DB_HOST is used, as before. */
final class ServerFile
{
    public static function path(): string
    {
        return env('VENUSEP_SERVER_FILE') ?: storage_path('app/failover/server.json');
    }

    public static function read(?string $path = null): ?array
    {
        $path ??= self::path();
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($path), true);
        return is_array($data) ? $data : null;
    }

    /* An IP or a host name, nothing else: this string becomes the PDO host. */
    public static function dbHost(?string $path = null): ?string
    {
        $host = self::read($path)['db_host'] ?? null;
        return is_string($host) && preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host) ? $host : null;
    }
}
