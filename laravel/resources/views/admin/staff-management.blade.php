@extends('layouts.admin')
@section('title', 'Staff Management')
@section('active', 'Staff Management')

@push('head')
  <meta name="theme-color" content="#7d1120" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Staff Management"/>
  <meta name="supported-color-schemes" content="light"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}"/>
@endpush

@push('styles')
  <style>
@include('partials.people-css-shell')
@include('partials.list-page-css')
.sm-halls-names { flex: 1 1 0; color: #6e6a64; font-size: 12.5px; }
.sm-halls-count { flex: none; border-radius: 999px; }
@media (max-width: 767.98px) {
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="role"] { grid-column: 2; grid-row: 1; justify-self: end; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="venueId"] { grid-column: 1; grid-row: 2; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="status"] { grid-column: 2; grid-row: 2; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="hallNames"] { order: 5; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="actions"] { order: 6; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="venueId"]::before { content: "Office"; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="hallNames"]::before { content: "Halls"; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field="status"]::before { content: "Status"; }
  .sm-halls-count { flex: none; min-height: 44px; }
}

  @include('partials.tabulator-pager-css')
    </style>
@endpush

@section('content')
    <main class="app-main">
      <div class="app-content staff-content">
        <div class="container-fluid">
          <header class="lp-head">
            <div>
              <h1>Staff Management</h1>
              <p>Accounts, halls and covers for the offices you manage.</p>
            </div>
            <!-- the way to the account form (2026-10-28): it is admin-only, and so is this page -->
            <a class="btn-staff-add" href="{{ route('admin.admin-register') }}"><i class="bi bi-person-plus" aria-hidden="true"></i>Add account</a>
          </header>

@php $smTab = in_array(request('tab'), ['covers', 'moves'], true) ? request('tab') : 'people'; @endphp
          @fragment('sm-tabs')
          @php
            $smWaiting = fn ($list) => count(array_filter($list, fn ($x) => $x['canAnswer']));
            $smTabs = [['people', 'People', count($smRows), 0], ['covers', 'Covers', count($smCover['covers']), $smWaiting($smCover['covers'])],
                       ['moves', 'Moves between groups', count($smCover['moves']), $smWaiting($smCover['moves'])]];
          @endphp
          <nav class="lp-seg" aria-label="Staff Management sections" data-live="sm-tabs">
@foreach ($smTabs as [$key, $label, $count, $waiting])
            <a href="{{ route('admin.staff', ['tab' => $key]) }}" data-tab="{{ $key }}"@if ($smTab === $key) aria-current="page"@endif>{{ $label }}
@if ($waiting)
              <span class="vz-chip vz-chip-amber">{{ $waiting }} waiting for you</span>
@else
              <span class="vz-chip vz-chip-grey">{{ $count }}</span>
@endif
            </a>
@endforeach
          </nav>
          @endfragment

          <section class="staff-panel" data-tab-panel="people"@if ($smTab !== 'people') hidden @endif>
            <div class="lp-tools">
              <label class="lp-search"><i class="bi bi-search" aria-hidden="true"></i><input id="table-filter" type="search" placeholder="Search name or email…" aria-label="Search name or email" autocomplete="off"/></label>
              <button type="button" class="lp-filters-btn" aria-expanded="false"><i class="bi bi-sliders" aria-hidden="true"></i>Filters</button>
              <div class="lp-field"><label for="smRole">Role</label><select id="smRole" data-filter="role"><option value="">All roles</option><option>Staff</option><option>Admin</option><option>Super admin</option></select></div>
              <div class="lp-field"><label for="smOffice">Office</label><select id="smOffice" data-filter="venueId"><option value="">All offices</option>@foreach ($smVenues as $venue)<option value="{{ $venue['id'] }}">{{ $venue['name'] }}</option>@endforeach</select></div>
              <div class="lp-field"><label for="smStatus">Status</label><select id="smStatus" data-filter="status"><option value="">All statuses</option><option>Active</option><option>Suspended</option></select></div>
            </div>
            <div id="users-table" class="lp-table"></div>
          </section>
@include('partials.staff-covers')
        </div>
      </div>
    </main>
@endsection

@push('scripts')
  <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
  <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
  <script src="{{ asset('assets/js/list-page.js') }}"></script>
  @fragment('sm-rows')<script type="application/json" data-live="sm-rows">{!! json_encode($smRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      /* The real staff-side accounts, from `users` + `staff`. */
      const data = Live.data('sm-rows');
      const venues = {!! json_encode($smVenues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
      /* No "Unassigned" (2026-10-27): every staff member handles exactly one
         venue, and staff-save.php refuses an empty one. The list holds only the
         offices this admin may move staff to (DB-DECISIONS 28). */
      const venueEditorValues = {};
      venues.forEach(function (venue) {
        venueEditorValues[String(venue.id)] = venue.name;
      });
      /* A venue name is typed by an admin: it is text, never markup. Tabulator
         puts a formatter's string and each list item's label in as HTML, so both
         are escaped; the labels themselves stay plain (the list's input shows
         them as typed). */
      const escText = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      };

      /* Every write goes to admin/staff-save.php, which re-checks that the
         caller is an admin and refuses the two ways an admin could lock
         everyone out: suspending themselves, or suspending the last active
         admin. Those checks live there and not here — this is a convenience,
         not a boundary. */
      const staffAction = function (body, onOk) {
        return Live.post(@json(route('admin.staff-save')), body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Nothing was changed.'); return false; }
          if (typeof onOk === 'function') onOk(res);
          return true;
        });
      };
      /* Everything on this page from the server again, keeping the table's page, sort and filters. */
      window.smRedraw = function () {
        return Live.refresh().then(function () {
          table.replaceData(Live.data('sm-rows'));
          window.smCoverRead();
        });
      };

      const toggleActive = async function (row) {
        const suspend = row.status === 'Active';
        const ok = await venusepConfirm(
          suspend
            ? 'They will no longer be able to sign in. Everything they have already approved or confirmed keeps their name on it.'
            : 'They will be able to sign in again.',
          { title: (suspend ? 'Suspend ' : 'Reactivate ') + row.name + '?', okLabel: suspend ? 'Suspend' : 'Reactivate', danger: suspend });
        if (!ok) return;
        const body = new FormData();
        body.append('action', 'set_active');
        body.append('user_id', row.id);
        body.append('active', suspend ? '0' : '1');
        staffAction(body, window.smRedraw);
      };

      const resetPassword = async function (row) {
        const pw = await venusepPrompt(
          'Choose a temporary password and give it to them yourself. They must replace it when they sign in. Their old password stops working now.',
          { title: 'Reset the password of ' + row.name + '?', label: 'Temporary password (at least 8 characters)',
            autocomplete: 'off', okLabel: 'Reset password', danger: true });
        if (pw === null) return;
        const body = new FormData();
        body.append('action', 'reset_password');
        body.append('user_id', row.id);
        body.append('new_password', pw);
        staffAction(body, function (res) { venusepAlert(res.message, { tone: 'ok', title: 'Password reset' }); });
      };

      /* Hand over the super admin (DB-DECISIONS 28): a reason, then your password. No undo. */
      const handOver = async function (row) {
        const reason = await venusepPrompt(
          row.name + ' becomes the super admin. Your account is suspended at once and you are signed out. This cannot be undone.',
          { title: 'Hand over the super admin to ' + row.name + '?', label: 'Reason', okLabel: 'Next', danger: true });
        if (reason === null) return;
        const pw = await venusepPrompt('Both of you are emailed.',
          { title: 'Confirm the handover', label: 'Your password', inputType: 'password',
            autocomplete: 'current-password', okLabel: 'Hand over', danger: true });
        if (pw === null) return;
        const body = new FormData();
        body.append('action', 'handover');
        body.append('user_id', row.id);
        body.append('reason', reason);
        body.append('password', pw);
        staffAction(body, function (res) {
          venusepAlert(res.message, { tone: 'ok', title: 'Handed over' })
            .then(function () { window.location.href = @json(route('admin.admin-login')); });
        });
      };


      /* a row's buttons, as many as this person allows */
      const rowActions = function (row) {
        /* No button on your own row. The server refuses it anyway, but offering a click
           that can only fail is worse than not offering it, and this is the click that
           would end your own access. */
        if (row.isSelf) return '<span class="lp-acts"><span class="lp-you">This is you</span></span>';
        if (!row.canManage) return '';
        const active = row.status === 'Active';
        const staffActive = row.role === 'Staff' && active;
        return '<div class="lp-acts">'
          + (row.canHandOver ? '<button type="button" class="lp-btn btn-staff-handover">Make super admin</button>' : '')
          + '<button type="button" class="lp-btn btn-staff-reset">Reset password</button>'
          + (staffActive ? '<button type="button" class="lp-btn btn-staff-cover">Set up cover</button><button type="button" class="lp-btn btn-staff-move">Move group</button>' : '')
          + '<button type="button" class="lp-btn btn-staff-toggle ' + (active ? 'lp-btn-no">Suspend' : 'lp-btn-ok">Reactivate') + '</button></div>';
      };

      const hallsCell = function (r) {
        if (r.wholeHostel) return '<span class="vz-chip vz-chip-grey">Whole hostel</span>';
        if (r.role === 'Super admin') return '<span class="sm-halls-names">All halls</span>';
        if (r.role === 'Admin') return '<span class="sm-halls-names">All halls of ' + escText(r.venue) + '</span>';
        const n = r.halls.length;
        const edit = function (text, icon) {
          return r.canHalls ? '<button type="button" class="lp-btn sm-halls-edit sm-halls-count" aria-label="Change the halls of ' + escText(r.name) + '">' + text + ' <i class="bi ' + icon + '" aria-hidden="true"></i></button>' : '';
        };
        if (!n) return '<div class="lp-row"><span class="vz-chip vz-chip-amber">No halls</span>' + edit('Assign', 'bi-plus') + '</div>';
        const count = n + (n === 1 ? ' hall' : ' halls');
        return '<div class="lp-row" title="' + escText(r.hallNames) + '">' + (edit(count, 'bi-pencil') || '<strong>' + count + '</strong>')
          + '<span class="sm-halls-names lp-ell">' + escText(r.hallNames) + '</span></div>';
      };

      const table = new Tabulator('#users-table', Object.assign({
        data: data,
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        paginationSizeSelector: [10, 25, 50, 100],
        movableColumns: true,
        columns: [
          {
            title: 'Person', field: 'name', minWidth: 190,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              return '<div class="lp-stack"><span class="lp-name">' + escText(r.name) + '</span><span class="lp-sub">' + escText(r.email) + '</span></div>';
            },
          },
          {
            title: 'Role', field: 'role', width: 118,
            formatter: function (cell) { return '<span class="vz-chip vz-chip-' + (cell.getValue() === 'Staff' ? 'line' : 'crimson') + '">' + escText(cell.getValue()) + '</span>'; },
          },
          {
            title: 'Office',
            field: 'venueId',
            formatter: function (cell) { return escText(cell.getRow().getData().venue); },   // the office label, from the server
            editor: 'list',
            editorParams: { values: venueEditorValues, itemFormatter: escText },
            editable: function (cell) { const r = cell.getRow().getData(); return r.role === 'Staff' && r.canManage; },
            width: 112,
            tooltip: function (e, cell) { return cell.getRow().getData().venue; },   // a long office label is cut short in the cell
            cellEdited: function (cell) {
              const row = cell.getRow().getData();
              const body = new FormData();
              body.append('action', 'set_venue');
              body.append('user_id', row.id);
              body.append('venue_id', cell.getValue() || '');
              staffAction(body).then(window.smRedraw);   /* a refused move puts the cell back */
            },
          },
          {
            title: 'Halls', field: 'hallNames', headerSort: false, minWidth: 145, cssClass: 'lp-wide',
            formatter: function (cell) { return hallsCell(cell.getRow().getData()); },
            cellClick: function (e, cell) {
              if (e.target.closest('.sm-halls-edit')) window.smOpenHalls(cell.getRow().getData());
            },
          },
          {
            title: 'Status', field: 'status', width: 160,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              /* 'Invited' is gone: users.is_active is a boolean, and nothing in the system
                 can send an invitation, so no account could ever have been in that state. */
              return '<div class="lp-stack"><span class="vz-chip vz-chip-' + (r.status === 'Active' ? 'green' : 'grey') + '">' + escText(r.status) + '</span>'
                + '<span class="lp-sub">' + (r.lastLogin === 'never' ? 'Never signed in' : 'Signed in ' + escText(r.lastLogin)) + '</span></div>';
            },
          },
          {
            title: 'Actions',
            field: 'actions',
            width: 240,
            headerSort: false,
            formatter: function (cell) { return rowActions(cell.getRow().getData()); },
            cellClick: function (e, cell) {
              const row = cell.getRow().getData();
              if (e.target.closest('.btn-staff-reset')) resetPassword(row);
              else if (e.target.closest('.btn-staff-handover')) handOver(row);
              else if (e.target.closest('.btn-staff-cover')) window.smOpenCover(row);
              else if (e.target.closest('.btn-staff-move')) window.smOpenMove(row);
              else if (e.target.closest('.btn-staff-toggle')) toggleActive(row);
            },
          },
        ],
      }, venusepPager('people')));
      table.on('tableBuilt', () => table.element.prepend(table.element.querySelector('.tabulator-footer')));   /* the pager goes above the rows */

      /* the search and the three boxes work together */
      const search = document.getElementById('table-filter');
      const boxes = document.querySelectorAll('[data-filter]');
      const applyFilters = function () {
        const filters = [];
        if (search.value) filters.push([{ field: 'name', type: 'like', value: search.value }, { field: 'email', type: 'like', value: search.value }]);
        boxes.forEach(function (box) { if (box.value) filters.push({ field: box.dataset.filter, type: '=', value: box.value }); });
        table.setFilter(filters);
      };
      search.addEventListener('input', applyFilters);
      boxes.forEach(function (box) { box.addEventListener('change', applyFilters); });

      /* People / Covers / Moves: the address keeps the tab, so a refresh or the sidebar's link lands on it */
      document.addEventListener('click', function (event) {
        const link = event.target.closest('.lp-seg [data-tab]');
        if (!link) return;
        event.preventDefault();
        history.replaceState(null, '', link.href);
        document.querySelectorAll('.lp-seg [data-tab]').forEach(function (a) {
          if (a === link) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        document.querySelectorAll('[data-tab-panel]').forEach(function (panel) { panel.hidden = panel.dataset.tabPanel !== link.dataset.tab; });
        if (link.dataset.tab === 'people') table.redraw(true);   /* a table drawn while hidden has no sizes */
      });
    });
  </script>
@endpush
