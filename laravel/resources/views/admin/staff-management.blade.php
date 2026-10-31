@extends('layouts.admin')
@section('title', 'Staff Management')
@section('active', 'Staff Management')

@push('head')
  <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
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

.app-main {
  margin-left: var(--venusep-sidebar-width) !important;
  padding-top: var(--venusep-header-height) !important;
  width: calc(100vw - var(--venusep-sidebar-width)) !important;
  min-height: 100vh;
  background: var(--venusep-cream);
}

.container-fluid {
  width: 100%;
  padding-inline: 1.35rem;
}

.navbar-nav {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.navbar .container-fluid {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.ms-auto {
  margin-left: auto !important;
}

.align-items-center {
  align-items: center !important;
}

.d-flex {
  display: flex !important;
}

.app-header .nav-link {
  color: #fff;
  font-size: 0.95rem;
  line-height: 1;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.45rem;
}

.sidebar-toggle {
  width: 38px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: #34312f;
  border: 1px solid #46413d;
  cursor: default;
  pointer-events: none;
}

.user-menu {
  gap: 0.45rem;
  margin-left: 0.7rem;
  color: #fff;
  font-weight: 700;
  font-size: 0.82rem;
}

.user-avatar {
  width: 34px;
  height: 34px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  background: #6f7983;
  border: 1px solid #9aa2aa;
  color: #d8dde2;
  font-size: 0.32rem;
}

.sidebar {
  position: fixed;
  left: 0;
  top: 0;
  width: var(--venusep-sidebar-width);
  height: 100vh;
  background: var(--venusep-black);
  color: #fff;
  z-index: 1035;
  border-right: 1px solid #2d2b29;
}

.logo {
  height: var(--venusep-header-height);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 14px 20px;
  border-bottom: 1px solid #333;
}

.logo img {
  display: block;
  max-width: 150px;
  max-height: 36px;
  width: auto;
  height: auto;
}

.menu {
  list-style: none;
  padding: 15px 10px;
  margin: 0;
}

.menu li {
  margin-bottom: 8px;
}

.menu a {
  display: flex;
  align-items: center;
  gap: 15px;
  height: 42px;
  padding: 0 15px;
  color: #e5e5e5;
  text-decoration: none;
  border-radius: 10px;
  transition: 0.2s;
}

.menu a:hover {
  background: #343333;
}

.menu a.active {
  background: #343333;
  border: 1px solid #ffffff40;
}

.menu i {
  width: 20px;
  text-align: center;
  font-size: 18px;
}

.menu span {
  font-size: 14px;
}

.staff-content {
  min-height: calc(100vh - var(--venusep-header-height));
  padding: 26px 30px 40px !important; /* uniform content inset — 26px top, 30px sides, same as every admin page */
  background: var(--venusep-cream);
}

.staff-content > .container-fluid {
  width: 100% !important;
  max-width: none !important;
  margin: 0 !important;
  padding: 0 !important;
}

.page-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 24px !important;
}

.page-heading h1 {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 800;
}

.breadcrumb {
  display: flex;
  gap: 0.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.82rem;
}

.breadcrumb-item + .breadcrumb-item::before {
  content: "/";
  margin-right: 0.4rem;
  color: #777;
}

.breadcrumb a {
  color: #005dff;
  text-decoration: none;
}

.staff-panel {
  width: 100%;
  overflow: hidden;
  background: #fff;
  border: 1px solid var(--venusep-border);
  border-radius: 10px;
  box-shadow: 0 18px 34px rgba(31, 30, 30, 0.06);
}

.staff-panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.9rem 1rem;
  background: #fff;
  border-bottom: 1px solid var(--venusep-border);
}

.staff-panel-header h2 {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 800;
}

.table-search {
  width: min(100%, 18rem);
}

.staff-panel-tools {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.6rem;
}

.staff-panel-tools .table-search {
  width: 16rem;
  flex: none;
}

@media (max-width: 640px) {
  .staff-panel-header { flex-wrap: wrap; }
  .staff-panel-tools { flex-wrap: wrap; width: 100%; justify-content: flex-start; }
  .staff-panel-tools .table-search { width: 100%; flex: 1 1 100%; }
}

.btn-staff-add {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  height: 32px;
  padding: 0 0.85rem;
  border-radius: 8px;
  background: #a11626;
  color: #fff;
  font-size: 0.82rem;
  font-weight: 700;
  text-decoration: none;
  white-space: nowrap;
}

.btn-staff-add:hover {
  background: #7d0f1e;
  color: #fff;
}

.input-group-text,
.form-control {
  border-color: var(--venusep-border);
  font: inherit;
}

.form-control:focus {
  border-color: #1d1b1b;
  box-shadow: 0 0 0 0.15rem rgba(29, 27, 27, 0.14);
}

.staff-panel-body {
  padding: 1rem;
}

#users-table {
  font-family: inherit;
}

.tabulator {
  border: 0;
  background: #fff;
  font-size: 0.88rem;
}

.tabulator .tabulator-header {
  border-bottom: 1px solid #dfe3e6;
  background: #fff;
  color: #2b2f33;
  font-weight: 800;
}

.tabulator .tabulator-header .tabulator-col,
.tabulator .tabulator-header .tabulator-col-row-handle {
  background: #fff;
  border-right: 0;
}

.tabulator .tabulator-tableholder .tabulator-table {
  color: #25292d;
  background: #fff;
}

.tabulator-row {
  background: #fff !important;
  border-bottom: 1px solid #ece8e2;
}

.tabulator-row.tabulator-row-even {
  background: #fff !important;
}

.tabulator-row.tabulator-row-odd {
  background: #fff !important;
}

.tabulator-row:hover {
  background: #faf9f7 !important;
}

.tabulator-row .tabulator-cell {
  border-right: 0;
  padding: 0.75rem 1rem;
}

.tabulator .tabulator-footer {
  border-top: 1px solid #e5e8eb;
  background: #fff;
  padding: 0.8rem 0;
}

.tabulator .tabulator-footer .tabulator-page-size {
  min-height: 34px;
  margin-inline: 0.4rem;
  padding: 0.35rem 2rem 0.35rem 0.75rem;
  border: 1px solid var(--venusep-border);
  border-radius: 7px;
  background-color: #fff;
  color: #1d1b1b;
  font: inherit;
  font-weight: 700;
}

.tabulator .tabulator-footer .tabulator-pages {
  display: inline-flex;
  gap: 0.35rem;
  align-items: center;
  margin-left: 0.55rem;
}

.tabulator .tabulator-footer .tabulator-page {
  min-width: 34px;
  min-height: 34px;
  margin: 0;
  padding: 0.35rem 0.7rem;
  border: 1px solid var(--venusep-border);
  border-radius: 7px;
  background: #fff;
  color: #1d1b1b;
  font: inherit;
  font-weight: 800;
  line-height: 1;
  transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.tabulator .tabulator-footer .tabulator-page:hover:not(:disabled) {
  border-color: #1d1b1b;
  background: #f8f7f5;
  color: #000;
}

.tabulator .tabulator-footer .tabulator-page.active {
  border-color: #1d1b1b;
  background: #1d1b1b;
  color: #fff;
}

.tabulator .tabulator-footer .tabulator-page:disabled {
  cursor: not-allowed;
  opacity: 0.45;
}

.tabulator .tabulator-footer .tabulator-paginator {
  color: #2b2f33;
  font-weight: 700;
}

.badge-role {
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0.25rem 0.55rem;
  border-radius: 6px;
  background: #1d1b1b;
  color: #fff;
  font-weight: 700;
}

.tabulator .badge {
  border-radius: 6px;
  font-weight: 700;
}

@media (max-width: 767.98px) {
  :root {
    --venusep-sidebar-width: 0px;
    --venusep-header-height: 56px;
  }

  .sidebar {
    transform: translateX(-100%);
  }

  .app-header {
    left: 0;
  }

  .app-main {
    margin-left: 0 !important;
    width: 100vw !important;
  }

  .staff-content {
    padding: 1.1rem 1rem 3rem !important;
  }

  .page-heading {
    align-items: flex-start;
    flex-direction: column;
    margin-bottom: 1.5rem !important;
  }

  .staff-panel-header {
    align-items: stretch;
    flex-direction: column;
  }

  .table-search {
    width: 100%;
  }

  .user-name {
    display: none;
  }
}

  </style>
@endpush

@section('content')
    <main class="app-main">
      <div class="app-content staff-content">
        <div class="container-fluid">
          <div class="page-heading">
            <h1>Staff Management</h1>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Dashboard</li>
                <li class="breadcrumb-item active" aria-current="page">Staff Management</li>
              </ol>
            </nav>
          </div>

          <section class="staff-panel">
            <div class="staff-panel-header">
              <h2>Users</h2>
              <div class="staff-panel-tools">
                <div class="input-group input-group-sm table-search">
                  <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                  <input id="table-filter" type="search" class="form-control" placeholder="Filter rows..." aria-label="Filter rows"/>
                </div>
                <!-- the way to the account form (2026-10-28): it is admin-only, and so is this page -->
                <a class="btn-staff-add" href="{{ route('admin.admin-register') }}"><i class="bi bi-person-plus" aria-hidden="true"></i>Add account</a>
              </div>
            </div>
            <div class="staff-panel-body">
              <div id="users-table"></div>
            </div>
          </section>
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
  <script>
    const roleBadge = (cell) => `<span class="badge-role">${cell.getValue()}</span>`;
    const statusBadge = (cell) => {
      const value = cell.getValue();
      const map = { Active: 'success', Invited: 'info', Suspended: 'secondary' };
      const color = map[value] || 'secondary';
      return `<span class="badge text-bg-${color}">${value}</span>`;
    };

    document.addEventListener('DOMContentLoaded', () => {
      /* The real staff-side accounts, from `users` + `staff`. */
      const data = {!! json_encode($smRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
      const venues = {!! json_encode($smVenues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
      const CSRF = {!! json_encode(csrf_token()) !!};
      /* No "Unassigned" (2026-10-27): every staff member handles exactly one
         venue, and staff-save.php refuses an empty one. Admins handle all. */
      const venueEditorValues = {};
      const venueFilterValues = { '': 'All Venues' };
      venues.forEach(function (venue) {
        venueEditorValues[String(venue.id)] = venue.name;
        venueFilterValues[String(venue.id)] = venue.name;
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
        body.append('_token', CSRF);   /* Laravel's CSRF check reads _token (the old field was csrf) */
        return fetch(@json(route('admin.staff-save')), { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
          .then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Nothing was changed.'); return false; }
            if (typeof onOk === 'function') onOk(res);
            return true;
          })
          .catch(function () { venusepAlert('Could not reach the server, so nothing was changed.'); return false; });
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
        staffAction(body, function () { window.location.reload(); });
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

      const table = new Tabulator('#users-table', {
        data: data,
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        paginationSizeSelector: [10, 25, 50, 100],
        movableColumns: true,
        columns: [
          { title: '#', field: 'id', width: 70, headerSort: true },
          { title: 'Name', field: 'name', headerFilter: 'input' },
          { title: 'Email', field: 'email', headerFilter: 'input' },
          {
            title: 'Role',
            field: 'role',
            formatter: roleBadge,
            headerFilter: 'list',
            headerFilterParams: { values: ['', 'Staff'] },
            width: 150,
            hozAlign: 'center',
          },
          {
            title: 'Venue',
            field: 'venueId',
            formatter: function (cell) {
              if (cell.getRow().getData().role !== 'Staff') return 'All venues';
              return escText(venueEditorValues[String(cell.getValue())] || 'Choose a venue');
            },
            editor: 'list',
            editorParams: { values: venueEditorValues, itemFormatter: escText },
            editable: function (cell) { return cell.getRow().getData().role === 'Staff'; },
            headerFilter: 'list',
            headerFilterParams: { values: venueFilterValues, clearable: true, itemFormatter: escText },
            headerFilterFunc: function (filterValue, rowValue) {
              if (!filterValue) return true;
              return String(rowValue) === String(filterValue);
            },
            width: 175,
            cellEdited: function (cell) {
              const row = cell.getRow().getData();
              const body = new FormData();
              body.append('action', 'set_venue');
              body.append('user_id', row.id);
              body.append('venue_id', cell.getValue() || '');
              staffAction(body).then(function () { window.location.reload(); });
            },
          },
          {
            title: 'Status',
            field: 'status',
            formatter: statusBadge,
            headerFilter: 'list',
            /* 'Invited' is gone: users.is_active is a boolean, and nothing in
               the system can send an invitation, so no account could ever have
               entered or left that state. */
            headerFilterParams: { values: ['', 'Active', 'Suspended'] },
            width: 140,
            hozAlign: 'center',
          },
          {
            title: 'Last sign-in',
            field: 'lastLogin',
            width: 130,
            hozAlign: 'center',
          },
          {
            title: '',
            field: 'id',
            width: 155,
            hozAlign: 'center',
            headerSort: false,
            formatter: function (cell) {
              const row = cell.getRow().getData();
              /* No button on your own row. The server refuses it anyway, but
                 offering a click that can only fail is worse than not offering
                 it — and this is the click that would end your own access. */
              if (row.isSelf) {
                return '<span style="color:#8a857d;font-size:.78rem">you</span>';
              }
              const suspend = row.status === 'Active';
              const reset = row.role === 'Staff'
                ? '<button type="button" class="btn-staff-reset" style="cursor:pointer;border:1px solid #d5d0c8;color:#1f1e1e;background:#fff;border-radius:999px;padding:.2rem .7rem;font-size:.76rem;font-weight:600">Reset password</button>'
                : '';
              return '<div style="display:flex;flex-direction:column;align-items:center;gap:.3rem">' + reset + '<button type="button" class="btn-staff-toggle" style="cursor:pointer;border:1px solid ' +
                (suspend ? '#e3c3c3;color:#b23a3a' : '#c3e0cd;color:#1c7a4f') +
                ';background:#fff;border-radius:999px;padding:.2rem .7rem;font-size:.76rem;font-weight:600">' +
                (suspend ? 'Suspend' : 'Reactivate') + '</button></div>';
            },
            cellClick: function (e, cell) {
              if (e.target.closest('.btn-staff-reset')) { resetPassword(cell.getRow().getData()); return; }
              if (!e.target.closest('.btn-staff-toggle')) return;
              toggleActive(cell.getRow().getData());
            },
          },
        ],
      });

      document.getElementById('table-filter').addEventListener('input', (event) => {
        const value = event.target.value;
        if (value) {
          table.setFilter([
            [
              { field: 'name', type: 'like', value: value },
              { field: 'email', type: 'like', value: value },
              { field: 'role', type: 'like', value: value },
              { field: 'venue', type: 'like', value: value },
              { field: 'status', type: 'like', value: value },
            ],
          ]);
        } else {
          table.clearFilter();
        }
      });
    });
  </script>
@endpush
