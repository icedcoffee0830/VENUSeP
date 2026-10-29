{{-- Backup & Restore (was admin/backup-restore.php). Every button posts to admin.backup-action,
     which re-checks who may do what — this page only decides what to SHOW:
       staff   "Back up now", and the list of saved backups
       admins  that, plus Download, Restore (also from an uploaded file) and Delete --}}
@extends('layouts.admin')
@section('title', 'Backup & Restore')
@section('active', 'Backup & Restore')

@push('head')
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
@endpush

@push('styles')
    <!-- this page's lines of the old [0] SHELL CSS that differ from the shared shell -->
    <style>
      .app-content-header { padding: 26px 0 0; }
      @media (max-width: 767.98px) { .container-fluid { padding-inline: 16px; } }
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
@endpush

@section('content')
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="br-head">
              <h1>Backup &amp; Restore</h1>
              <p>A backup is one file with everything the system has stored: the database, the uploaded IDs and receipts, and the room, venue and profile photos. It is kept on this computer, outside the website folder. The newest {{ (int) BACKUP_KEEP }} are kept.</p>
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
              @if (!$brIsAdmin)
                <p class="br-note">Only an administrator can download, restore or delete a backup.</p>
              @endif
              @if (!$brBackups)
                <p>No backups yet.</p>
              @else
                <table class="br-table">
                  <thead><tr><th>Made</th><th>Kind</th><th class="br-hide-sm">By</th><th class="br-hide-sm">Size</th>@if ($brIsAdmin)<th></th>@endif</tr></thead>
                  <tbody>
                  @foreach ($brBackups as $b)
                  @php $m = $b['meta']; $kind = $m['kind'] ?? 'manual'; $ver = isset($m['schema_version']) ? (int) $m['schema_version'] : null; @endphp
                    <tr data-file="{{ $b['file'] }}">
                      <td>{{ isset($m['created_at']) ? date('M j, Y · g:i A', strtotime($m['created_at'])) : date('M j, Y · g:i A', $b['mtime']) }}</td>
                      <td><span class="br-tag{{ $kind === 'pre-restore' ? ' is-safety' : '' }}">{{ $brKinds[$kind] ?? $kind }}</span>
                        @if ($ver !== null && $ver !== $brVersion)<span class="br-tag is-old" title="Made with layout version {{ $ver }}; this system is on {{ $brVersion }}">Older layout — cannot restore</span>@endif</td>
                      <td class="br-hide-sm">{{ $m['created_by'] ?? '—' }}</td>
                      <td class="br-hide-sm">{{ $brSize((int) ($m['size'] ?? 0)) }}</td>
                      @if ($brIsAdmin)
                      <td class="br-actions">
                        <a class="br-btn" href="{{ route('admin.backup-action', ['download' => $b['file']]) }}"><i class="bi bi-download" aria-hidden="true"></i>Download</a>
                        <button type="button" class="br-btn" data-restore="{{ $b['file'] }}"{!! ($ver !== null && $ver !== $brVersion) ? ' disabled title="Older layout"' : '' !!}><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Restore</button>
                        <button type="button" class="br-btn br-btn-danger" data-delete="{{ $b['file'] }}" aria-label="Delete this backup"><i class="bi bi-trash" aria-hidden="true"></i></button>
                      </td>
                      @endif
                    </tr>
                  @endforeach
                  </tbody>
                </table>
              @endif
            </section>

            @if ($brIsAdmin)
            <section class="br-card" aria-labelledby="brUploadTitle">
              <h2 id="brUploadTitle">Restore from a downloaded backup</h2>
              <p>For a backup file kept somewhere else (a USB drive, Google Drive). It must come from this same version of the system.</p>
              <div class="br-warn"><strong>A restore replaces everything</strong> — every booking, payment, account and setting made after that backup is gone. A safety copy of the current data is made first, and everyone is signed out afterwards, you included.</div>
              <input type="file" id="brUpload" accept=".gz,application/gzip" />
              <button type="button" class="br-btn br-btn-danger" id="brUploadGo" style="margin-left:8px"><i class="bi bi-upload" aria-hidden="true"></i>Restore this file</button>
            </section>
            @endif
          </div>
        </div>
      </main>
@endsection

@push('scripts')
    <script>
      (function () {
        const CSRF = @json($brCsrf);
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
          body.append('_token', CSRF);
          Object.keys(fields).forEach(function (k) { body.append(k, fields[k]); });
          if (file) body.append('archive', file, file.name);
          return fetch(@json(route('admin.backup-action')), { method: 'POST', body: body, credentials: 'same-origin' })
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
          venusepAlert(res.message, { tone: 'ok', title: 'Restored' }).then(function () { location.href = @json(route('admin.admin-login')); });
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
@endpush
