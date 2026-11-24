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
        return self::isHost($host) ? $host : null;
    }

    public static function isHost(mixed $host): bool
    {
        return is_string($host) && preg_match('/^[A-Za-z0-9.:-]{1,253}$/', $host) === 1;
    }

    /* The laptops of the group, in the order they were added (the page names them A, B, C...). */
    public static function laptops(): array
    {
        return array_values(array_filter((array) (self::read()['laptops'] ?? []), [self::class, 'isHost']));
    }

    /* The only writer: merge $changes, version + 1, under a lock, written to a temporary file
       and renamed over the old one, so a reader never sees half a file. Returns the new content. */
    public static function write(array $changes, string $by): array
    {
        $path = self::path();
        @mkdir(dirname($path), 0775, true);
        $lock = fopen($path . '.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $old = self::read($path) ?? [];
            $data = array_merge($old, $changes, [
                'version' => (int) ($old['version'] ?? 0) + 1,
                'changed_at' => date('Y-m-d H:i:s'),
                'changed_by' => $by,
            ]);
            $tmp = $path . '.tmp-' . bin2hex(random_bytes(4));
            file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            /* Windows refuses the rename while a reader has the file open: a few short retries */
            for ($i = 0; !@rename($tmp, $path) && $i < 5; $i++) {
                usleep(50_000);
            }
            @unlink($tmp);
            return $data;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
