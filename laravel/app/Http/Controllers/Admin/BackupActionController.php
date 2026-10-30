<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\JsonEndpoint;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;
use Throwable;

/* =====================================================================
   BACKUP ACTIONS (was admin/backup-action.php, 2026-10-27) — the only door
   to legacy/includes/backup.php.

   POST _token, action=create                      staff AND admins
   GET  ?download=<file>                           admins only
   POST _token, action=delete, file                admins only
   POST _token, action=restore, password,          admins only
        file=<a saved backup>  OR  FILES[archive] (a downloaded one, uploaded)
   Replies with JSON (the download streams the file).

   Staff may only BACK UP: a backup holds every ID and password hash (taking
   one off the machine is an admin decision) and a restore rewinds everything.
   RESTORE re-asks for the admin's password (the shared users.reauth_*
   lockout) and signs EVERYONE out afterwards — this admin included.
   ===================================================================== */
class BackupActionController
{
    use JsonEndpoint;

    private function bk(int $status, array $data): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function __invoke(Request $request)
    {
        legacy('auth', 'backup');

        $type = session('account_type');
        if (!session()->has('user_id') || !in_array($type, ['admin', 'staff'], true)) {
            return $this->bk(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
        }
        $isAdmin = $type === 'admin';
        $actor = (int) session('user_id');
        $pdo = venusep_db();
        $actorLabel = 'user #' . $actor;
        if ($pdo !== null) {
            $who = $pdo->prepare('SELECT email FROM users WHERE id = :u');
            $who->execute([':u' => $actor]);
            $actorLabel = (string) ($who->fetchColumn() ?: $actorLabel);
        }

        /* ---- download: admins only; streams the file ---- */
        if ($request->isMethod('get') && $request->query->has('download')) {
            if (!$isAdmin) {
                return $this->bk(403, ['ok' => false, 'message' => 'Only an administrator can download a backup.']);
            }
            $path = backup_path((string) $request->query('download'));
            if ($path === null) {
                return $this->bk(404, ['ok' => false, 'message' => 'That backup no longer exists.']);
            }
            return response()->download($path, basename($path), [
                'Content-Type' => 'application/gzip',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        if (!$request->isMethod('post')) {
            return $this->bk(405, ['ok' => false, 'message' => 'Use POST.'])->header('Allow', 'GET, POST');
        }
        if (!$this->csrfOk($request)) {
            return $this->bk(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
        }
        $action = (string) $request->input('action', '');

        if ($action === 'create') {
            $file = backup_create('manual', $actor, $actorLabel, $err);
            if ($file === null) {
                return $this->bk(500, ['ok' => false, 'message' => $err]);
            }
            return $this->bk(200, ['ok' => true, 'file' => $file, 'message' => 'Backup made: ' . $file]);
        }

        if (!$isAdmin) {
            return $this->bk(403, ['ok' => false, 'message' => 'Only an administrator can do that.']);
        }

        if ($action === 'delete') {
            if (!backup_delete((string) $request->input('file', ''))) {
                return $this->bk(404, ['ok' => false, 'message' => 'That backup no longer exists.']);
            }
            return $this->bk(200, ['ok' => true, 'message' => 'Backup deleted.']);
        }

        if ($action !== 'restore') {
            return $this->bk(400, ['ok' => false, 'message' => 'Unknown action.']);
        }

        /* ---- RESTORE ---- */
        /* which backup: a saved one, or an uploaded file (kept as an "uploaded" backup) */
        $archive = null;
        $upload = $request->file('archive');            // null when no file was sent (UPLOAD_ERR_NO_FILE)
        if ($upload !== null) {
            if ($upload->getError() !== UPLOAD_ERR_OK) {
                return $this->bk(400, ['ok' => false, 'message' => $upload->getError() === UPLOAD_ERR_INI_SIZE || $upload->getError() === UPLOAD_ERR_FORM_SIZE
                    ? 'That file is larger than this server accepts (' . ini_get('upload_max_filesize') . '). Copy it into the backups folder instead.'
                    : 'The file did not upload. Try again.']);
            }
            if (!preg_match('/\.tar\.gz$/i', (string) $upload->getClientOriginalName())) {
                return $this->bk(400, ['ok' => false, 'message' => 'Choose a VENUSeP backup file (.tar.gz).']);
            }
        } else {
            $archive = backup_path((string) $request->input('file', ''));
            if ($archive === null) {
                return $this->bk(404, ['ok' => false, 'message' => 'Pick a backup to restore.']);
            }
        }

        $password = (string) $request->input('password', '');
        if ($password === '' || $pdo === null) {
            return $this->bk(400, ['ok' => false, 'error' => 'no_password', 'message' => 'Enter your admin password to confirm the restore.']);
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
                return $this->bk(403, ['ok' => false, 'message' => 'Only an administrator can restore a backup.']);
            }
            if ($no = reauth_verify($pdo, $me, $password)) {
                return isset($no['locked'])
                    ? $this->bk(423, ['ok' => false, 'error' => 'locked', 'message' => 'Too many wrong passwords. Try again in ' . $no['locked'] . ' seconds.'])
                    : $this->bk(401, ['ok' => false, 'error' => 'wrong_password',
                        'message' => 'Wrong password. ' . $no['attemptsLeft'] . ' attempt(s) left before a temporary lock.']);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('VENUSeP backup-action (re-auth): ' . $e->getMessage());
            return $this->bk(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was restored.']);
        }

        /* an uploaded file is kept among the backups (the newest 10, like the
           others), then restored from there */
        $uploaded = $archive === null;
        if ($uploaded) {
            $name = 'venusep-backup-' . date('Ymd-His') . '-uploaded.tar.gz';
            $dest = backup_dir() . '/' . $name;
            try {
                $upload->move(backup_dir(), $name);
            } catch (Throwable $e) {
                return $this->bk(500, ['ok' => false, 'message' => 'The uploaded file could not be stored.']);
            }
            $m = backup_read_manifest($dest);
            if ($m === null) {
                @unlink($dest);
                return $this->bk(400, ['ok' => false, 'message' => 'That file is not a VENUSeP backup.']);
            }
            $m['size'] = filesize($dest);
            file_put_contents(substr($dest, 0, -strlen('.tar.gz')) . '.json', json_encode($m + ['uploaded_by' => $actorLabel], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $archive = $dest;
            backup_prune();
        }

        $pre = backup_restore($archive, $actor, $actorLabel, $err);
        if ($pre === null) {
            /* a refused upload is not a backup of this system: it must not stay
               in the list (the admin still has the file they chose) */
            if ($uploaded) {
                backup_delete(basename($archive));
            }
            return $this->bk(409, ['ok' => false, 'message' => $err]);
        }
        /* backup_sign_everyone_out() emptied the session store; this request's own
           session would be written back at the end of it, so end it here too. */
        venusep_logout();
        error_log('VENUSeP: backup ' . basename($archive) . ' restored by ' . $actorLabel . '; safety copy ' . $pre);
        return $this->bk(200, ['ok' => true, 'signedOut' => true, 'safetyBackup' => $pre,
            'message' => 'Restored from ' . basename($archive) . '. Everyone has been signed out, you included. '
                       . 'The data from just before is kept as ' . $pre . '.']);
    }
}
