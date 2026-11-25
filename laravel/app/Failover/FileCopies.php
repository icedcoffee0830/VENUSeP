<?php

namespace App\Failover;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/* Photos, IDs and receipts on every laptop (failover plan §5). They are files, not database
   rows, so replication does not carry them. Every minute the task (venusep:failover-tick):
   1. lists this laptop's folders (the same list a backup carries, backup_file_sets(), minus
      the backups themselves) and compares it with last minute's list: a file that is gone
      was deleted here, so it gets a tombstone, kept 7 days;
   2. for each other laptop: asks for its list, sends what it lacks or has older, and sends
      the tombstones, so a deleted ID (the keep-for-a-while rule) disappears there too.
   Nothing in the upload code changes. A file can lag up to a minute.
   The laptops talk over HTTP to each other's VENUSeP (/failover/inbox), every message
   encrypted with the shared APP_KEY: only a laptop with the key can send or read one. */
final class FileCopies
{
    private const TOMB_DAYS = 7;

    /* ---- what is here ---- */

    /* 'set/relative/path' => [size, mtime] */
    public static function listing(): array
    {
        legacy('backup');
        $out = [];
        foreach (backup_file_sets() as $set => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                $key = $set . '/' . str_replace('\\', '/', substr($f->getPathname(), strlen($dir) + 1));
                if ($f->isFile() && self::path($key) !== null) {
                    $out[$key] = [$f->getSize(), $f->getMTime()];
                }
            }
        }
        return $out;
    }

    /* The file a key names, or null: an unknown set, "..", or a backup. Every incoming path passes here. */
    public static function path(string $key): ?string
    {
        legacy('backup');
        $key = str_replace('\\', '/', $key);
        if (str_contains($key, '..') || str_contains($key, "\0") || str_starts_with($key, 'private/backups/') || str_contains($key, ':')) {
            return null;
        }
        foreach (backup_file_sets() as $set => $dir) {
            if (str_starts_with($key, $set . '/') && strlen($key) > strlen($set) + 1) {
                return rtrim(str_replace('\\', '/', $dir), '/') . '/' . substr($key, strlen($set) + 1);
            }
        }
        return null;
    }

    /* What to send a laptop (pure): the files it lacks or has older, and the tombstones for files
       it still has from before the deletion. */
    public static function plan(array $mine, array $theirs, array $tombs): array
    {
        $put = [];
        foreach ($mine as $k => [$size, $mtime]) {
            if (!isset($theirs[$k]) || $theirs[$k][1] < $mtime) {
                $put[] = $k;
            }
        }
        $del = [];
        foreach ($tombs as $k => $at) {
            if (isset($theirs[$k]) && $theirs[$k][1] <= $at) {
                $del[] = $k;
            }
        }
        return ['put' => $put, 'del' => $del];
    }

    /* ---- the minute's sweep ---- */

    public static function sweep(): string
    {
        $now = self::listing();
        $dir = storage_path('app/failover');
        @mkdir($dir, 0775, true);
        $prev = json_decode((string) @file_get_contents($dir . '/files.json'), true);
        $tombs = json_decode((string) @file_get_contents($dir . '/deleted.json'), true) ?: [];
        if (is_array($prev)) {
            foreach (array_diff_key($prev, $now) as $k => $_) {
                $tombs[$k] = time();
            }
        }
        $tombs = array_filter(array_diff_key($tombs, $now), fn ($at) => $at > time() - self::TOMB_DAYS * 86400);
        file_put_contents($dir . '/files.json', json_encode($now));
        file_put_contents($dir . '/deleted.json', json_encode($tombs));

        $peers = array_filter(ServerFile::laptops(), fn ($ip) => !Servers::isLocal($ip));
        $said = [];
        foreach ($peers as $ip) {
            try {
                $theirs = self::send($ip, ['type' => 'list']);
                $p = self::plan($now, (array) ($theirs['files'] ?? []), $tombs);
                foreach ($p['put'] as $k) {
                    self::send($ip, ['type' => 'put', 'path' => $k, 'mtime' => $now[$k][1], 'data' => base64_encode((string) file_get_contents(self::path($k)))]);
                }
                foreach ($p['del'] as $k) {
                    self::send($ip, ['type' => 'del', 'path' => $k, 'at' => $tombs[$k]]);
                }
                $said[] = $ip . ': ' . count($p['put']) . ' sent, ' . count($p['del']) . ' removed';
            } catch (Throwable $e) {
                $said[] = $ip . ': not reached (' . $e->getMessage() . ')';
            }
        }
        return $said ? implode('; ', $said) : 'no other laptops in the list';
    }

    /* After a switch: tell every other laptop of the list which server is the main now. A laptop
       that is off is skipped (it is told by hand later: Switch to this on the main). Returns the
       laptops told. */
    public static function tellMain(string $main): array
    {
        $told = [];
        foreach (array_filter(ServerFile::laptops(), fn ($ip) => !Servers::isLocal($ip)) as $ip) {
            try {
                self::send($ip, ['type' => 'server', 'db_host' => $main, 'laptops' => ServerFile::laptops()]);
                $told[] = $ip;
            } catch (Throwable) {
                // off or not on the hotspot
            }
        }
        return $told;
    }

    /* ---- the wire ---- */

    private static function send(string $ip, array $msg): array
    {
        $port = (int) config('venusep.web_port', 8100);
        $res = Http::connectTimeout(3)->timeout(30)->withBody(Crypt::encryptString(json_encode($msg + ['sent' => time()])), 'text/plain')
            ->post("http://{$ip}:{$port}/failover/inbox");
        if (!$res->successful()) {
            throw new \RuntimeException('HTTP ' . $res->status());
        }
        return (array) json_decode(Crypt::decryptString($res->body()), true);
    }

    /* POST /failover/inbox: another laptop of the group. A body this laptop can't decrypt, or one
       older than 2 minutes (a replay), is refused. */
    public static function inbox(Request $request)
    {
        try {
            $m = json_decode(Crypt::decryptString($request->getContent()), true);
        } catch (Throwable) {
            return response('', 403);
        }
        if (!is_array($m) || abs(time() - (int) ($m['sent'] ?? 0)) > 120) {
            return response('', 403);
        }
        $reply = fn (array $r) => response(Crypt::encryptString(json_encode($r)), 200, ['Content-Type' => 'text/plain']);
        if (($m['type'] ?? '') === 'list') {
            return $reply(['files' => self::listing()]);
        }
        /* another laptop switched: this laptop's website follows the new main at once (the
           two-laptop test: a laptop that never heard of a switch kept using its own locked copy) */
        if (($m['type'] ?? '') === 'server') {
            if (!ServerFile::isHost($m['db_host'] ?? null)) {
                return response('', 422);
            }
            ServerFile::write(['db_host' => $m['db_host'], 'laptops' => array_values(array_unique([...ServerFile::laptops(),
                ...array_filter((array) ($m['laptops'] ?? []), [ServerFile::class, 'isHost'])]))], 'another laptop');
            return $reply(['ok' => true]);
        }
        $file = self::path((string) ($m['path'] ?? ''));
        if ($file === null) {
            return response('', 422);
        }
        if ($m['type'] === 'put') {
            $bytes = base64_decode((string) ($m['data'] ?? ''), true);
            if ($bytes === false || strlen($bytes) > DOC_MAX_BYTES) {
                return response('', 422);
            }
            /* deleted here after that version was made: our tombstone is on its way to them */
            $tombs = json_decode((string) @file_get_contents(storage_path('app/failover/deleted.json')), true) ?: [];
            if (($tombs[$m['path']] ?? 0) >= (int) $m['mtime']) {
                return $reply(['ok' => false, 'deleted' => true]);
            }
            @mkdir(dirname($file), 0700, true);
            $tmp = $file . '.part-' . bin2hex(random_bytes(3));
            file_put_contents($tmp, $bytes);
            @unlink($file);
            rename($tmp, $file);
            touch($file, (int) $m['mtime']);
            return $reply(['ok' => true]);
        }
        if ($m['type'] === 'del') {
            if (is_file($file) && filemtime($file) <= (int) ($m['at'] ?? 0)) {
                @unlink($file);
            }
            return $reply(['ok' => true]);
        }
        return response('', 422);
    }
}
