<?php
/* =====================================================================
   BACKUP & RESTORE (decided 2026-10-27) — the engine behind
   admin/backup-restore.php and admin/backup-action.php.

   ONE BACKUP = ONE .tar.gz holding:
     database.sql     the whole `venusep` database (mysqldump --databases:
                      tables, procedures, functions, triggers), restorable
                      into an empty server
     private/         the private documents folder (IDs, receipts, the mail
                      log — doc_root()), minus backups/ itself
     photos/rooms     assets/img/venues/rooms   (room photos + 360°)
     photos/covers    assets/img/venues/covers  (venue cover photos)
     photos/avatars   assets/img/avatars        (profile pictures)
     manifest.json    who made it, when, and the LAYOUT VERSION
   NOT included: the code (git has it) and the secret config files
   (includes/mail-config.php, google-config.php) — those belong to each
   machine and must never travel in a downloadable file.

   .tar.gz because PHP's zip extension is off on this XAMPP; Phar + zlib
   are built in. Stored in doc_root()/backups — OUTSIDE the web root, so a
   backup (every ID and password hash in the system) is only ever reached
   through admin/backup-action.php's admin check. The newest BACKUP_KEEP
   are kept.

   THE LAYOUT VERSION is the highest venusep_migration_NN.sql in the
   project. A backup only restores into the SAME version: an older dump
   would bring back procedures and columns the current code no longer
   matches, and that breaks in ways nobody would trace to the restore.

   RESTORE: make a fresh "pre-restore" backup FIRST (no safety copy, no
   restore), load the database, replace the private files and the three
   photo folders with the backup's, then sign everyone out — sessions and
   remember-me tokens from after the backup must not survive into the
   restored world.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/documents.php';   /* doc_root() */

const BACKUP_KEEP = 10;
const BACKUP_APP  = 'VENUSeP';
const BACKUP_NAME_RE = '/^venusep-backup-\d{8}-\d{6}-(manual|pre-restore|uploaded)\.tar\.gz$/';

function backup_dir() {
    $d = doc_root() . '/backups';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/* The layout version: the highest venusep_migration_NN.sql in the project. */
function backup_schema_version() {
    $max = 0;
    /* LARAVEL: the migration files sit in the project root, one level above
       laravel/ (read only). */
    foreach (glob(dirname(base_path()) . '/venusep_migration_*.sql') ?: [] as $f) {
        if (preg_match('/_(\d+)\.sql$/', $f, $m)) {
            $max = max($max, (int) $m[1]);
        }
    }
    return $max;
}

/* What a backup carries besides the database: [name in the archive => folder]. */
function backup_file_sets() {
    /* LARAVEL: the photos are served from laravel/public/assets. */
    return [
        'private'        => doc_root(),
        'photos/rooms'   => public_path('assets/img/venues/rooms'),
        'photos/covers'  => public_path('assets/img/venues/covers'),
        'photos/avatars' => public_path('assets/img/avatars'),
    ];
}

/* mysqldump / mysql from XAMPP's own mysql/bin (or VENUSEP_MYSQL_BIN). */
function backup_mysql_bin($tool) {
    $env = getenv('VENUSEP_MYSQL_BIN');
    /* LARAVEL: the XAMPP root is found from PHP itself (php/php.exe on the
       command line, apache/bin/httpd.exe under Apache) — the app folder can
       now sit at any depth under htdocs. */
    $dir = $env ?: dirname(dirname(PHP_BINARY)) . '/mysql/bin';
    $p = rtrim($dir, '/\\') . '/' . $tool . (DIRECTORY_SEPARATOR === '\\' ? '.exe' : '');
    return is_file($p) ? $p : null;
}

/* Run a MySQL client tool with no shell in between. stdin/stdout may be files.
   The password (if any) travels in the environment, never on a command line
   another process could read. Returns '' on success, else the error text. */
function backup_run(array $cmd, $stdinFile = null, $stdoutFile = null) {
    $cfg = venusep_db_config();
    $desc = [
        0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
        1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $env = getenv();
    if ($cfg['pass'] !== '') {
        $env['MYSQL_PWD'] = $cfg['pass'];
    }
    $proc = proc_open($cmd, $desc, $pipes, null, $env);
    if (!is_resource($proc)) {
        return 'Could not start ' . basename($cmd[0]) . '.';
    }
    if ($stdinFile === null) { fclose($pipes[0]); }
    if ($stdoutFile === null) { stream_get_contents($pipes[1]); fclose($pipes[1]); }
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return $code === 0 ? '' : (trim((string) $err) ?: basename($cmd[0]) . ' exited with code ' . $code . '.');
}

function backup_rmtree($dir, $keepTopLevel = []) {
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..' || in_array($n, $keepTopLevel, true)) {
            continue;
        }
        $p = $dir . '/' . $n;
        if (is_dir($p) && !is_link($p)) {
            backup_rmtree($p);
            @rmdir($p);
        } else {
            @unlink($p);
        }
    }
}

function backup_copytree($from, $to, $skipTopLevel = []) {
    if (!is_dir($from)) {
        return;
    }
    if (!is_dir($to)) {
        @mkdir($to, 0775, true);
    }
    foreach (scandir($from) as $n) {
        if ($n === '.' || $n === '..' || in_array($n, $skipTopLevel, true)) {
            continue;
        }
        $a = $from . '/' . $n;
        $b = $to . '/' . $n;
        if (is_dir($a)) {
            backup_copytree($a, $b);
        } else {
            copy($a, $b);
        }
    }
}

function backup_tempdir() {
    $d = rtrim(sys_get_temp_dir(), '/\\') . '/venusep-bk-' . bin2hex(random_bytes(6));
    mkdir($d, 0700, true);
    return $d;
}

/* Make a backup. $kind: manual | pre-restore. Returns the file name, or null
   with $error set. */
function backup_create($kind, $userId, $userLabel, &$error) {
    @set_time_limit(600);
    $error = '';
    $dump = backup_mysql_bin('mysqldump');
    if ($dump === null) {
        $error = 'mysqldump was not found (looked in XAMPP\'s mysql/bin).';
        return null;
    }
    $cfg  = venusep_db_config();
    $work = backup_tempdir();
    try {
        $err = backup_run([$dump, '--host=' . $cfg['host'], '--user=' . $cfg['user'],
            '--default-character-set=utf8mb4', '--single-transaction', '--routines', '--triggers', '--events',
            '--add-drop-database', '--hex-blob', '--databases', $cfg['name']], null, $work . '/database.sql');
        if ($err !== '' || !is_file($work . '/database.sql') || filesize($work . '/database.sql') < 1000) {
            $error = 'The database could not be dumped: ' . ($err ?: 'the dump came out empty.');
            return null;
        }
        foreach (backup_file_sets() as $name => $path) {
            backup_copytree($path, $work . '/' . $name, $name === 'private' ? ['backups'] : []);
        }
        $manifest = [
            'app'            => BACKUP_APP,
            'kind'           => $kind,
            'created_at'     => date('Y-m-d H:i:s'),
            'created_by'     => $userLabel,
            'created_by_id'  => (int) $userId,
            'schema_version' => backup_schema_version(),
            'database'       => $cfg['name'],
        ];
        file_put_contents($work . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $dir  = backup_dir();
        $base = 'venusep-backup-' . date('Ymd-His') . '-' . $kind;
        /* Built NEXT TO the temp folder, then MOVED into backups/. The phar
           extension caches an archive's index by path for the rest of the
           request: reading the .tar.gz at the path it was compressed at
           returned garbage (offsets from the uncompressed .tar). The moved
           file's path was never cached, so any later read parses it fresh. */
        $tarPath = $work . '.tar';
        $tar = new PharData($tarPath);
        $tar->buildFromDirectory($work);
        $tar->compress(Phar::GZ);            // writes $work.tar.gz beside it
        unset($tar);
        @unlink($tarPath);
        $file = $base . '.tar.gz';
        if (!is_file($work . '.tar.gz') || !rename($work . '.tar.gz', $dir . '/' . $file)) {
            @unlink($work . '.tar.gz');
            $error = 'The backup file could not be written.';
            return null;
        }
        $manifest['size'] = filesize($dir . '/' . $file);
        file_put_contents($dir . '/' . $base . '.json', json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        backup_prune();
        return $file;
    } catch (Throwable $e) {
        error_log('VENUSeP backup_create: ' . $e->getMessage());
        $error = 'The backup could not be made: ' . $e->getMessage();
        return null;
    } finally {
        backup_rmtree($work);
        @rmdir($work);
    }
}

/* Every backup, newest first: [file, manifest + size]. */
function backup_list() {
    $out = [];
    foreach (glob(backup_dir() . '/venusep-backup-*.tar.gz') ?: [] as $p) {
        $file = basename($p);
        if (!preg_match(BACKUP_NAME_RE, $file)) {
            continue;
        }
        $side = substr($p, 0, -strlen('.tar.gz')) . '.json';
        $m = is_file($side) ? json_decode((string) file_get_contents($side), true) : null;
        $out[] = ['file' => $file, 'meta' => is_array($m) ? $m : ['size' => filesize($p)], 'mtime' => filemtime($p)];
    }
    usort($out, function ($a, $b) { return strcmp($b['file'], $a['file']); });
    return $out;
}

function backup_path($file) {
    return preg_match(BACKUP_NAME_RE, (string) $file) && is_file(backup_dir() . '/' . $file) ? backup_dir() . '/' . $file : null;
}

function backup_delete($file) {
    $p = backup_path($file);
    if ($p === null) {
        return false;
    }
    @unlink($p);
    @unlink(substr($p, 0, -strlen('.tar.gz')) . '.json');
    return true;
}

/* Keep the newest BACKUP_KEEP. */
function backup_prune() {
    $all = backup_list();
    foreach (array_slice($all, BACKUP_KEEP) as $b) {
        backup_delete($b['file']);
    }
}

/* Read a backup's manifest straight from the archive (an uploaded file has no
   side-car). Returns null if it is not a VENUSeP backup. */
function backup_read_manifest($archivePath) {
    try {
        $a = new PharData($archivePath);
        if (!isset($a['manifest.json']) || !isset($a['database.sql'])) {
            return null;
        }
        $m = json_decode($a['manifest.json']->getContent(), true);
        return (is_array($m) && ($m['app'] ?? '') === BACKUP_APP) ? $m : null;
    } catch (Throwable $e) {
        return null;
    }
}

/* Restore one backup file. Returns the pre-restore backup's name, or null
   with $error set. Everyone — including the admin who asked — is signed out
   at the end. */
function backup_restore($archivePath, $userId, $userLabel, &$error) {
    @set_time_limit(900);
    $error = '';
    $manifest = backup_read_manifest($archivePath);
    if ($manifest === null) {
        $error = 'That file is not a VENUSeP backup.';
        return null;
    }
    $now = backup_schema_version();
    if ((int) ($manifest['schema_version'] ?? -1) !== $now) {
        $error = 'That backup was made with database layout version ' . (int) ($manifest['schema_version'] ?? 0)
               . ', and this system is on version ' . $now . '. Restoring it would bring back tables and procedures '
               . 'the current code does not match, so it was refused.';
        return null;
    }
    $mysql = backup_mysql_bin('mysql');
    if ($mysql === null) {
        $error = 'The mysql client was not found (looked in XAMPP\'s mysql/bin).';
        return null;
    }

    $work = backup_tempdir();
    try {
        $a = new PharData($archivePath);
        /* only the names a VENUSeP backup contains — nothing outside them is
           ever written, whatever the archive holds */
        foreach (new RecursiveIteratorIterator($a) as $entry) {
            $rel = str_replace('\\', '/', substr($entry->getPathname(), strlen('phar://' . str_replace('\\', '/', $archivePath)) + 1));
            if (strpos($rel, '..') !== false || !preg_match('#^(database\.sql|manifest\.json|private/|photos/(rooms|covers|avatars)/)#', $rel)) {
                $error = 'That backup contains an unexpected file (' . $rel . '), so it was not restored.';
                return null;
            }
        }
        $a->extractTo($work, null, true);
        unset($a);

        /* 1. the safety copy — no safety copy, no restore */
        $pre = backup_create('pre-restore', $userId, $userLabel, $preErr);
        if ($pre === null) {
            $error = 'A safety backup of the current data could not be made, so nothing was restored. ' . $preErr;
            return null;
        }

        /* 2. the database (the dump drops and recreates `venusep` itself) */
        $cfg = venusep_db_config();
        $err = backup_run([$mysql, '--host=' . $cfg['host'], '--user=' . $cfg['user'], '--default-character-set=utf8mb4'],
                          $work . '/database.sql', null);
        if ($err !== '') {
            $error = 'The database could not be restored: ' . $err . ' The safety backup ' . $pre . ' holds the data from just before.';
            return null;
        }

        /* 3. the files — each folder replaced by the backup's copy */
        foreach (backup_file_sets() as $name => $path) {
            $keep = $name === 'private' ? ['backups'] : [];
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            backup_rmtree($path, $keep);
            backup_copytree($work . '/' . $name, $path, $keep);
        }

        /* 4. everyone signs in again */
        backup_sign_everyone_out();
        return $pre;
    } catch (Throwable $e) {
        error_log('VENUSeP backup_restore: ' . $e->getMessage());
        $error = 'The restore failed: ' . $e->getMessage();
        return null;
    } finally {
        backup_rmtree($work);
        @rmdir($work);
    }
}

/* Every session file and every remember-me token goes: the restored data is a
   different world from the one those sessions were opened in. */
function backup_sign_everyone_out() {
    $pdo = venusep_db();
    if ($pdo !== null) {
        try { $pdo->exec('DELETE FROM remember_tokens'); } catch (PDOException $e) { /* the table may predate migration 04 */ }
    }
    /* LARAVEL: the sessions are Laravel's (config/session.php), not PHP's
       sess_* files — clear whichever store is in use. */
    $driver = config('session.driver');
    if ($driver === 'file') {
        foreach (glob(rtrim(config('session.files'), '/\\') . '/*') ?: [] as $f) {
            if (is_file($f) && basename($f) !== '.gitignore') {
                @unlink($f);
            }
        }
    } elseif ($driver === 'database' && $pdo !== null) {
        try { $pdo->exec('DELETE FROM ' . config('session.table', 'sessions')); } catch (PDOException $e) { /* no sessions table */ }
    }
}
