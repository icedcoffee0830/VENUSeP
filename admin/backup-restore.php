<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* =====================================================================
   BACKUP & RESTORE (2026-10-27). The engine is includes/backup.php; every
   button here posts to admin/backup-action.php, which re-checks who may do
   what — this page only decides what to SHOW:
     staff   "Back up now", and the list of saved backups
     admins  that, plus Download, Restore (also from an uploaded file) and Delete
   ===================================================================== */
require_once __DIR__ . '/../includes/backup.php';
$brIsAdmin  = admin_is_admin();
$brBackups  = backup_list();
$brVersion  = backup_schema_version();
$brCsrf     = csrf_token();
function br_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function br_size($b) { return $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB'; }
$brKinds = ['manual' => 'Backup', 'pre-restore' => 'Safety copy before a restore', 'uploaded' => 'Uploaded file'];
?>
<!DOCTYPE html>
<html lang="en">
  <head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Backup &amp; Restore</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="../assets/css/confirm-modal.css" />
    <script src="../assets/js/confirm-modal.js"></script>

    <!-- [0] SHELL CSS — the team header bar + sidebar + layout (same block on every page) -->
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { background: #fff; color: var(--venusep-text); font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; overflow-x: hidden; }
      .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; background: #fff; }
      .container-fluid { width: 100%; padding-inline: 30px; }
      .app-content-header { padding: 26px 0 0; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
        .container-fluid { padding-inline: 16px; }
      }
    </style>

    <!-- [1] PAGE CSS -->
    <style>
      :root { --br-border: #e5e5e5; --br-muted: #6b675f; --br-text: #1c1b19; --br-red: #a11626; }
      .br-head h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.25rem; }
      .br-head p { color: var(--br-muted); font-size: 0.88rem; margin: 0 0 1.4rem; max-width: 70ch; line-height: 1.55; }
      .br-card { border: 1px solid var(--br-border); border-radius: 12px; padding: 18px 20px; margin-bottom: 18px; background: #fff; box-shadow: 0 1px 2px rgba(15,23,42,.04); max-width: 980px; }
      .br-card h2 { font-size: 1rem; font-weight: 700; margin: 0 0 6px; }
      .br-card p { font-size: 0.86rem; color: var(--br-muted); line-height: 1.6; margin: 0 0 12px; }
      .br-list { margin: 0 0 12px; padding-left: 1.1rem; font-size: 0.85rem; color: var(--br-text); line-height: 1.7; }
      .br-btn { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 14px; border-radius: 9px; border: 1px solid #d7d2ca; background: #fff; color: var(--br-text); font: 600 0.84rem Inter, system-ui, sans-serif; cursor: pointer; text-decoration: none; }
      .br-btn:hover { background: #f6f3ef; }
      .br-btn-primary { background: var(--br-dark, #1f1e1e); border-color: #1f1e1e; color: #fff; }
      .br-btn-primary:hover { background: #343333; }
      .br-btn-danger { color: var(--br-red); border-color: rgba(161,22,38,.35); }
      .br-btn-danger:hover { background: #fcf2f3; }
      .br-btn[disabled] { opacity: .5; cursor: wait; }
      .br-table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
      .br-table th { text-align: left; font-size: 0.72rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: #a39c94; padding: 8px 10px; border-bottom: 1px solid var(--br-border); }
      .br-table td { padding: 10px; border-bottom: 1px solid #f2efeb; vertical-align: middle; }
      .br-table td.br-actions { white-space: nowrap; text-align: right; }
      .br-table .br-btn { height: 32px; padding: 0 10px; font-size: 0.8rem; }
      .br-tag { display: inline-block; padding: 2px 8px; border-radius: 999px; font-size: 0.72rem; font-weight: 650; background: #f2efe9; color: #4a463f; }
      .br-tag.is-safety { background: #fdf3e6; color: #8a5a12; }
      .br-tag.is-old { background: #fcecec; color: #b23a3a; }
      .br-note { font-size: 0.82rem; color: var(--br-muted); background: #f8f6f2; border-radius: 9px; padding: 10px 12px; }
      .br-warn { font-size: 0.84rem; color: #8a5a12; background: #fdf7ee; border: 1px solid #f3e3c8; border-radius: 9px; padding: 10px 12px; margin-bottom: 12px; line-height: 1.55; }
      .br-busy { position: fixed; inset: 0; z-index: 2800; background: rgba(20,14,12,.45); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; }
      .br-busy div { background: #fff; border-radius: 14px; padding: 22px 26px; font: 600 0.95rem Inter, system-ui, sans-serif; color: #1f1e1e; box-shadow: 0 24px 70px rgba(0,0,0,.3); text-align: center; max-width: 340px; line-height: 1.5; }
      @media (max-width: 760px) { .br-table .br-hide-sm { display: none; } }
    </style>
  </head>
  <body>
    <div class="app-wrapper">
      <?php include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Backup & Restore'; include __DIR__ . '/../includes/sidebar.php'; ?>
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="br-head">
              <h1>Backup &amp; Restore</h1>
              <p>A backup is one file with everything the system has stored: the database, the uploaded IDs and receipts, and the room, venue and profile photos. It is kept on this computer, outside the website folder. The newest <?php echo (int) BACKUP_KEEP; ?> are kept.</p>
            </div>

            <section class="br-card" aria-labelledby="brMakeTitle">
              <h2 id="brMakeTitle">Make a backup</h2>
              <p>Takes a few seconds. Bookings and payments made while it runs are still saved.</p>
              <ul class="br-list">
                <li>Included: the whole database, the private documents (IDs, GCash receipts, the mail log) and every uploaded photo.</li>
                <li>Not included: the program itself (it is in git) and the Gmail / Google sign-in secrets, which stay on this machine.</li>
              </ul>
              <button type="button" class="br-btn br-btn-primary" id="brCreate"><i class="bi bi-database-add" aria-hidden="true"></i>Back up now</button>
            </section>

            <section class="br-card" aria-labelledby="brListTitle">
              <h2 id="brListTitle">Saved backups</h2>
              <?php if (!$brIsAdmin): ?>
                <p class="br-note">Only an administrator can download, restore or delete a backup.</p>
              <?php endif; ?>
              <?php if (!$brBackups): ?>
                <p>No backups yet.</p>
              <?php else: ?>
                <table class="br-table">
                  <thead><tr><th>Made</th><th>Kind</th><th class="br-hide-sm">By</th><th class="br-hide-sm">Size</th><?php if ($brIsAdmin): ?><th></th><?php endif; ?></tr></thead>
                  <tbody>
                  <?php foreach ($brBackups as $b): $m = $b['meta']; $kind = $m['kind'] ?? 'manual'; $ver = isset($m['schema_version']) ? (int) $m['schema_version'] : null; ?>
                    <tr data-file="<?php echo br_e($b['file']); ?>">
                      <td><?php echo br_e(isset($m['created_at']) ? date('M j, Y · g:i A', strtotime($m['created_at'])) : date('M j, Y · g:i A', $b['mtime'])); ?></td>
                      <td><span class="br-tag<?php echo $kind === 'pre-restore' ? ' is-safety' : ''; ?>"><?php echo br_e($brKinds[$kind] ?? $kind); ?></span>
                        <?php if ($ver !== null && $ver !== $brVersion): ?><span class="br-tag is-old" title="Made with layout version <?php echo $ver; ?>; this system is on <?php echo $brVersion; ?>">Older layout — cannot restore</span><?php endif; ?></td>
                      <td class="br-hide-sm"><?php echo br_e($m['created_by'] ?? '—'); ?></td>
                      <td class="br-hide-sm"><?php echo br_e(br_size((int) ($m['size'] ?? 0))); ?></td>
                      <?php if ($brIsAdmin): ?>
                      <td class="br-actions">
                        <a class="br-btn" href="backup-action.php?download=<?php echo urlencode($b['file']); ?>"><i class="bi bi-download" aria-hidden="true"></i>Download</a>
                        <button type="button" class="br-btn" data-restore="<?php echo br_e($b['file']); ?>"<?php echo ($ver !== null && $ver !== $brVersion) ? ' disabled title="Older layout"' : ''; ?>><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Restore</button>
                        <button type="button" class="br-btn br-btn-danger" data-delete="<?php echo br_e($b['file']); ?>" aria-label="Delete this backup"><i class="bi bi-trash" aria-hidden="true"></i></button>
                      </td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>

            <?php if ($brIsAdmin): ?>
            <section class="br-card" aria-labelledby="brUploadTitle">
              <h2 id="brUploadTitle">Restore from a downloaded backup</h2>
              <p>For a backup file kept somewhere else (a USB drive, Google Drive). It must come from this same version of the system.</p>
              <div class="br-warn"><strong>A restore replaces everything</strong> — every booking, payment, account and setting made after that backup is gone. A safety copy of the current data is made first, and everyone is signed out afterwards, you included.</div>
              <input type="file" id="brUpload" accept=".gz,application/gzip" />
              <button type="button" class="br-btn br-btn-danger" id="brUploadGo" style="margin-left:8px"><i class="bi bi-upload" aria-hidden="true"></i>Restore this file</button>
            </section>
            <?php endif; ?>
          </div>
        </div>
      </main>
    </div>

    <script>
      (function () {
        const CSRF = <?php echo json_encode($brCsrf); ?>;
        function busy(text) {
          const d = document.createElement('div');
          d.className = 'br-busy';
          d.innerHTML = '<div></div>';
          d.firstChild.textContent = text;
          document.body.appendChild(d);
          return function () { d.remove(); };
        }
        function post(fields, file) {
          const body = new FormData();
          body.append('csrf', CSRF);
          Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
          if (file) body.append('archive', file, file.name);
          return fetch('backup-action.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .catch(function () { return { ok: false, message: 'Could not reach the server.' }; });
        }

        document.getElementById('brCreate').addEventListener('click', function () {
          const done = busy('Making the backup…');
          post({ action: 'create' }).then(function (res) {
            done();
            if (!res.ok) { venusepAlert(res.message || 'The backup was not made.'); return; }
            venusepAlert('The backup is saved on this computer.', { tone: 'ok', title: 'Backup made' }).then(function () { location.reload(); });
          });
        });

        document.querySelectorAll('[data-delete]').forEach(function (b) {
          b.addEventListener('click', async function () {
            if (!(await venusepConfirm('This backup file is removed for good.', { title: 'Delete this backup?', okLabel: 'Delete', danger: true }))) return;
            post({ action: 'delete', file: b.dataset.delete }).then(function (res) {
              if (!res.ok) { venusepAlert(res.message || 'Nothing was deleted.'); return; }
              location.reload();
            });
          });
        });

        /* A restore asks for the admin's own password — the server re-checks it
           with the shared lockout — then signs everyone out. */
        async function restore(fields, file, what) {
          const pw = await venusepPrompt(
            'Everything made after ' + what + ' is replaced: bookings, payments, accounts and settings. A safety copy of the current data is made first. Everyone is signed out afterwards, you included.',
            { title: 'Restore this backup?', label: 'Your admin password', inputType: 'password',
              autocomplete: 'current-password', okLabel: 'Restore', danger: true });
          if (pw === null) return;
          const done = busy('Restoring… this can take a minute. Do not close this page.');
          fields.action = 'restore';
          fields.password = pw;
          const res = await post(fields, file);
          done();
          if (!res.ok) { venusepAlert(res.message || 'Nothing was restored.'); return; }
          venusepAlert(res.message, { tone: 'ok', title: 'Restored' }).then(function () { location.href = 'admin-login.php'; });
        }
        document.querySelectorAll('[data-restore]').forEach(function (b) {
          b.addEventListener('click', function () {
            const row = b.closest('tr');
            restore({ file: b.dataset.restore }, null, 'the backup of ' + row.cells[0].textContent.trim());
          });
        });
        const up = document.getElementById('brUploadGo');
        if (up) up.addEventListener('click', function () {
          const f = document.getElementById('brUpload').files[0];
          if (!f) { venusepAlert('Choose a backup file first.'); return; }
          restore({}, f, 'that backup');
        });
      })();
    </script>
  </body>
</html>
