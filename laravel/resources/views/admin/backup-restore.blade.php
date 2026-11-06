{{-- Backup & Restore (was admin/backup-restore.php). Every button posts to admin.backup-action,
     which re-checks who may do what — this page only decides what to SHOW:
       staff   "Back up now", and the list of saved backups
       admins  that, plus Download, Restore (also from an uploaded file) and Delete --}}
@extends('layouts.admin')
@section('title', 'Backup & Restore')
@section('active', 'Backup & Restore')

@push('head')
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset_stamped('assets/js/confirm-modal.js') }}"></script>
@endpush

@push('styles')
    <!-- this page's lines of the old [0] SHELL CSS that differ from the shared shell -->
    <link rel="stylesheet" href="{{ asset_stamped('assets/css/admin/backup-restore.css') }}" />

    <!-- [1] PAGE CSS -->
    
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
              @fragment('br-list')
              <div data-live="br-list">
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
              </div>
              @endfragment
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
  /* Values from PHP for assets/js/admin/backup-restore.js */
  var backupRestorePage = {
    csrf: @json($brCsrf),
    backupActionUrl: @json(route('admin.backup-action')),
    adminLoginUrl: @json(route('admin.admin-login')),
  };
</script>
<script src="{{ asset_stamped('assets/js/admin/backup-restore.js') }}"></script>
@endpush
