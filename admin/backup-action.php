<?php
/* =====================================================================
   BACKUP ACTIONS (2026-10-27) — the only door to includes/backup.php.

   POST csrf, action=create                      staff AND admins
   GET  ?download=<file>                         admins only
   POST csrf, action=delete, file                admins only
   POST csrf, action=restore, password,          admins only
        file=<a saved backup>  OR  FILES[archive] (a downloaded one, uploaded)
   Replies with JSON (the download streams the file).

   WHY STAFF MAY ONLY BACK UP: a backup holds every customer's ID and every
   password hash, so taking one OFF the machine is an admin decision; and a
   restore rewinds every account, booking and setting — including bringing
   back an account an admin suspended since. Making a fresh copy, though, is
   harmless and worth letting anyone on staff do.

   RESTORE re-asks for the admin's password, with the same users.reauth_*
   lockout as the refund switch and the GCash accounts (one shared budget),
   and signs EVERYONE out afterwards — this admin included.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup.php';

function bk_reply($status, array $data) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

venusep_session_start();
$type = $_SESSION['account_type'] ?? null;
if (!isset($_SESSION['user_id']) || !in_array($type, ['admin', 'staff'], true)) {
    bk_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
$isAdmin = $type === 'admin';
$actor = (int) $_SESSION['user_id'];
$pdo = venusep_db();
$actorLabel = 'user #' . $actor;
if ($pdo !== null) {
    $who = $pdo->prepare('SELECT email FROM users WHERE id = :u');
    $who->execute([':u' => $actor]);
    $actorLabel = (string) ($who->fetchColumn() ?: $actorLabel);
}

/* ---- download: admins only; streams the file ---- */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['download'])) {
    if (!$isAdmin) {
        bk_reply(403, ['ok' => false, 'message' => 'Only an administrator can download a backup.']);
    }
    $path = backup_path((string) $_GET['download']);
    if ($path === null) {
        bk_reply(404, ['ok' => false, 'message' => 'That backup no longer exists.']);
    }
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($path);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: GET, POST');
    bk_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    bk_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}
$action = (string) ($_POST['action'] ?? '');

if ($action === 'create') {
    $file = backup_create('manual', $actor, $actorLabel, $err);
    if ($file === null) {
        bk_reply(500, ['ok' => false, 'message' => $err]);
    }
    bk_reply(200, ['ok' => true, 'file' => $file, 'message' => 'Backup made: ' . $file]);
}

if (!$isAdmin) {
    bk_reply(403, ['ok' => false, 'message' => 'Only an administrator can do that.']);
}

if ($action === 'delete') {
    if (!backup_delete((string) ($_POST['file'] ?? ''))) {
        bk_reply(404, ['ok' => false, 'message' => 'That backup no longer exists.']);
    }
    bk_reply(200, ['ok' => true, 'message' => 'Backup deleted.']);
}

if ($action !== 'restore') {
    bk_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
}

/* ---- RESTORE ---- */
/* which backup: a saved one, or an uploaded file (kept as an "uploaded" backup) */
$archive = null;
if (!empty($_FILES['archive']) && $_FILES['archive']['error'] !== UPLOAD_ERR_NO_FILE) {
    if ($_FILES['archive']['error'] !== UPLOAD_ERR_OK) {
        bk_reply(400, ['ok' => false, 'message' => $_FILES['archive']['error'] === UPLOAD_ERR_INI_SIZE || $_FILES['archive']['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'That file is larger than this server accepts (' . ini_get('upload_max_filesize') . '). Copy it into the backups folder instead.'
            : 'The file did not upload. Try again.']);
    }
    if (!preg_match('/\.tar\.gz$/i', (string) $_FILES['archive']['name'])) {
        bk_reply(400, ['ok' => false, 'message' => 'Choose a VENUSeP backup file (.tar.gz).']);
    }
} else {
    $archive = backup_path((string) ($_POST['file'] ?? ''));
    if ($archive === null) {
        bk_reply(404, ['ok' => false, 'message' => 'Pick a backup to restore.']);
    }
}

$password = (string) ($_POST['password'] ?? '');
if ($password === '' || $pdo === null) {
    bk_reply(400, ['ok' => false, 'error' => 'no_password', 'message' => 'Enter your admin password to confirm the restore.']);
}
/* the shared re-entry lockout — 5 wrong = a lock, each longer than the last */
try {
    $pdo->beginTransaction();
    $u = $pdo->prepare(
        'SELECT id, password_hash, account_type, is_active, reauth_failed_attempts, reauth_lock_level,
                CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END AS lock_seconds
           FROM users WHERE id = :id FOR UPDATE'
    );
    $u->execute([':id' => $actor]);
    $me = $u->fetch();
    if (!$me || !(bool) $me['is_active'] || $me['account_type'] !== 'admin') {
        $pdo->rollBack();
        bk_reply(403, ['ok' => false, 'message' => 'Only an administrator can restore a backup.']);
    }
    if ((int) $me['lock_seconds'] > 0) {
        $pdo->rollBack();
        bk_reply(423, ['ok' => false, 'error' => 'locked', 'message' => 'Too many wrong passwords. Try again in ' . (int) $me['lock_seconds'] . ' seconds.']);
    }
    if (!password_verify($password, (string) $me['password_hash'])) {
        $attempts = (int) $me['reauth_failed_attempts'] + 1;
        $ladder = [10, 30, 60, 300, 900];
        if ($attempts >= 5) {
            $level = min((int) $me['reauth_lock_level'] + 1, 255);
            $seconds = $ladder[min($level, count($ladder)) - 1];
            $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = :l, reauth_locked_until = DATE_ADD(NOW(), INTERVAL :s SECOND) WHERE id = :id')
                ->execute([':l' => $level, ':s' => $seconds, ':id' => $actor]);
            $pdo->commit();
            bk_reply(423, ['ok' => false, 'error' => 'locked', 'message' => 'Too many wrong passwords. Try again in ' . $seconds . ' seconds.']);
        }
        $pdo->prepare('UPDATE users SET reauth_failed_attempts = :a WHERE id = :id')->execute([':a' => $attempts, ':id' => $actor]);
        $pdo->commit();
        bk_reply(401, ['ok' => false, 'error' => 'wrong_password',
            'message' => 'Wrong password. ' . (5 - $attempts) . ' attempt(s) left before a temporary lock.']);
    }
    $pdo->prepare('UPDATE users SET reauth_failed_attempts = 0, reauth_lock_level = 0, reauth_locked_until = NULL WHERE id = :id')->execute([':id' => $actor]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP backup-action (re-auth): ' . $e->getMessage());
    bk_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was restored.']);
}

/* an uploaded file is kept among the backups, then restored from there */
if ($archive === null) {
    $name = 'venusep-backup-' . date('Ymd-His') . '-uploaded.tar.gz';
    $dest = backup_dir() . '/' . $name;
    if (!move_uploaded_file($_FILES['archive']['tmp_name'], $dest)) {
        bk_reply(500, ['ok' => false, 'message' => 'The uploaded file could not be stored.']);
    }
    $m = backup_read_manifest($dest);
    if ($m === null) {
        @unlink($dest);
        bk_reply(400, ['ok' => false, 'message' => 'That file is not a VENUSeP backup.']);
    }
    $m['size'] = filesize($dest);
    file_put_contents(substr($dest, 0, -strlen('.tar.gz')) . '.json', json_encode($m + ['uploaded_by' => $actorLabel], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $archive = $dest;
}

$pre = backup_restore($archive, $actor, $actorLabel, $err);
if ($pre === null) {
    bk_reply(409, ['ok' => false, 'message' => $err]);
}
error_log('VENUSeP: backup ' . basename($archive) . ' restored by ' . $actorLabel . '; safety copy ' . $pre);
bk_reply(200, ['ok' => true, 'signedOut' => true, 'safetyBackup' => $pre,
    'message' => 'Restored from ' . basename($archive) . '. Everyone has been signed out, you included. '
               . 'The data from just before is kept as ' . $pre . '.']);
