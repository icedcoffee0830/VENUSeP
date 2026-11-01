<?php

namespace App\Http\Controllers\Admin;

/* Backup & Restore (was admin/backup-restore.php). Route middleware: staff.
   The engine is legacy/includes/backup.php; every button posts to
   BackupActionController, which re-checks who may do what. */
class BackupRestoreController
{
    public function __invoke()
    {
        legacy('backup');

        return view('admin.backup-restore', [
            'brIsAdmin' => admin_is_admin(),
            'brBackups' => backup_list(),
            'brVersion' => backup_schema_version(),
            'brCsrf'    => csrf_token(),
            'brSize'    => function ($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; },
            'brKinds'   => ['manual' => 'Backup', 'pre-restore' => 'Safety copy before a restore', 'uploaded' => 'Uploaded file'],
        ]);
    }
}
