@extends('layouts.admin')
@section('title', 'Customer Management')
@section('active', 'Customer Management')

@push('head')
  <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Customer Management"/>
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

.ms-auto {
  margin-left: auto !important;
}

.align-items-center {
  align-items: center !important;
}

.d-flex {
  display: flex !important;
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

.staff-content {
  min-height: calc(100vh - var(--venusep-header-height));
  padding: 26px 30px 40px !important;
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

#customers-table {
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

.tabulator .badge {
  border-radius: 6px;
  font-weight: 700;
}

@media (max-width: 767.98px) {
  :root {
    --venusep-sidebar-width: 0px;
    --venusep-header-height: 56px;
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
}

  @include('partials.tabulator-pager-css')
    </style>
@endpush

@section('content')
    <main class="app-main">
      <div class="app-content staff-content">
        <div class="container-fluid">
          <div class="page-heading">
            <h1>Customer Management</h1>
            <nav aria-label="breadcrumb">
              <ol class="breadcrumb">
                <li class="breadcrumb-item">Dashboard</li>
                <li class="breadcrumb-item active" aria-current="page">Customer Management</li>
              </ol>
            </nav>
          </div>

          <section class="staff-panel">
            <div class="staff-panel-header">
              <h2>Customer accounts</h2>
              <div class="input-group input-group-sm table-search">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input id="table-filter" type="search" class="form-control" placeholder="Filter rows..." aria-label="Filter rows"/>
              </div>
            </div>
            <div class="staff-panel-body">
              <div id="customers-table"></div>
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
  @fragment('cm-rows')<script type="application/json" data-live="cm-rows">{!! json_encode($cmRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>@endfragment
  <script>
    const statusBadge = (cell) => {
      const value = cell.getValue();
      return `<span class="badge text-bg-${value === 'Active' ? 'success' : 'secondary'}">${value}</span>`;
    };

    document.addEventListener('DOMContentLoaded', () => {
      /* The real customer accounts, from `users` + `customers`. Walk-ins
         (no user_id) never appear here — see admin/customer-management.php. */
      const data = Live.data('cm-rows');

      /* Every write goes to admin/customer-save.php, which re-checks that the
         caller is admin or staff. That check lives there, not here — this is
         a convenience, not a boundary. */
      const customerAction = function (body) {
        return Live.send(@json(route('admin.customer-save')), body, function () { table.replaceData(Live.data('cm-rows')); });   /* same page, sort and filters */
      };

      const toggleActive = async function (row) {
        const disable = row.status === 'Active';
        const ok = await venusepConfirm(
          disable
            ? (row.open ? 'Warning: they have ' + row.open + ' upcoming or unpaid booking(s), which stay as they are. ' : '') + 'They will be signed out immediately and cannot sign in or book again until re-enabled. Their existing bookings and history are unaffected.'
            : 'They will be able to sign in and book again.',
          { title: (disable ? 'Disable ' : 'Re-enable ') + row.name + '?', okLabel: disable ? 'Disable' : 'Re-enable', danger: disable });
        if (!ok) return;
        const body = new FormData();
        body.append('action', 'set_active');
        body.append('user_id', row.userId);
        body.append('active', disable ? '0' : '1');
        customerAction(body);
      };

      const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
      const PILL = 'cursor:pointer;background:#fff;border-radius:999px;padding:.4rem .8rem;min-height:32px;font-size:.76rem;font-weight:600;border:1px solid ';

      /* Block or lift at one office: a reason either way. The customer never sees it. */
      const blockAction = async function (row, act, venueId) {
        const b = row.blocks.find(function (x) { return x.venueId === venueId; });
        const reason = act === 'block'
          ? await venusepPrompt('They can still sign in and see everything, but cannot make new bookings at ' + b.office + ', online or at the counter. A pending request still blocked at its start is cancelled. The reason is kept; they never see it.',
              { title: 'Block ' + row.name + ' at ' + b.office + '?', label: 'Reason', okLabel: 'Block', danger: true })
          : await venusepPrompt('Blocked by ' + b.by + ' on ' + b.at + ': ' + b.reason + '. They can book at ' + b.office + ' again.',
              { title: 'Lift the block on ' + row.name + '?', label: 'Reason for lifting', okLabel: 'Lift block' });
        if (reason === null) return;
        const body = new FormData();
        body.append('action', act);
        body.append('customer_id', row.id);
        body.append('venue_id', venueId);
        body.append('reason', reason);
        customerAction(body);
      };

      /* Correct the email of an account made at the counter: the new address, then the reason. */
      const fixEmailAction = async function (row) {
        const email = await venusepPrompt('Made at the counter and never signed into, so the email can still be corrected. The account’s password and any reset link stop working, and the welcome email goes to the new address. Now: ' + row.email,
          { title: 'Fix the email of ' + row.name + '?', label: 'Correct email', placeholder: 'name@example.com', okLabel: 'Next' });
        if (email === null) return;
        const reason = await venusepPrompt('Changing ' + row.email + ' to ' + email.trim() + '. The reason is kept with the correction.',
          { title: 'Why is it being corrected?', label: 'Reason', okLabel: 'Fix email' });
        if (reason === null) return;
        const body = new FormData();
        body.append('action', 'fix_email');
        body.append('customer_id', row.id);
        body.append('email', email);
        body.append('reason', reason);
        customerAction(body);
      };

      const table = new Tabulator('#customers-table', {
        data: data,
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        paginationSizeSelector: [10, 25, 50, 100],
        movableColumns: true,
        columns: [
          { title: '#', field: 'id', width: 60, headerSort: true },
          { title: 'Name', field: 'name', headerFilter: 'input', minWidth: 160 },
          {
            title: 'Email', field: 'email', headerFilter: 'input', minWidth: 200,
            /* a counter account never signed into: its group's admin may correct a wrong email */
            formatter: function (cell) {
              const row = cell.getRow().getData();
              return esc(row.email) + (row.fixEmail
                ? '<div style="margin-top:.25rem"><button type="button" class="btn-cust-fix-email" style="' + PILL + '#d9d4cc;color:#4a4440">Fix email</button></div>' : '');
            },
            cellClick: function (e, cell) {
              if (e.target.closest('.btn-cust-fix-email')) fixEmailAction(cell.getRow().getData());
            },
          },
          { title: 'Phone', field: 'phone', width: 140 },
          { title: 'USeP', field: 'usep', width: 80, hozAlign: 'center' },
          { title: 'Bookings', field: 'bookings', width: 100, hozAlign: 'center' },
          { title: 'Joined', field: 'joined', width: 115, hozAlign: 'center' },
          { title: 'Last sign-in', field: 'lastLogin', width: 120, hozAlign: 'center' },
          {
            title: 'Status',
            field: 'status',
            formatter: statusBadge,
            headerFilter: 'list',
            headerFilterParams: { values: ['', 'Active', 'Disabled'] },
            width: 120,
            hozAlign: 'center',
          },
          {
            /* per office (DB-DECISIONS 28): only the offices this admin may block at */
            title: 'Office blocks',
            field: 'blocks',
            minWidth: 190,
            headerSort: false,
            formatter: function (cell) {
              return cell.getValue().map(function (b) {
                return b.on
                  ? '<div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;margin:.15rem 0"><span class="badge text-bg-danger" title="' +
                      esc('By ' + b.by + ', ' + b.at + ': ' + b.reason) + '">Blocked · ' + esc(b.office) + '</span>' +
                      '<button type="button" class="btn-cust-block" data-act="lift" data-venue="' + b.venueId + '" style="' + PILL + '#c3e0cd;color:#1c7a4f">Lift block</button></div>'
                  : '<div style="margin:.15rem 0"><button type="button" class="btn-cust-block" data-act="block" data-venue="' + b.venueId + '" style="' + PILL + '#e3c3c3;color:#b23a3a">Block at ' + esc(b.office) + '</button></div>';
              }).join('');
            },
            cellClick: function (e, cell) {
              const btn = e.target.closest('.btn-cust-block');
              if (btn) blockAction(cell.getRow().getData(), btn.dataset.act, Number(btn.dataset.venue));
            },
          },
@if ($cmMaySuspend)
          {
            title: '',
            field: 'userId',
            width: 120,
            hozAlign: 'center',
            headerSort: false,
            formatter: function (cell) {
              const row = cell.getRow().getData();
              const disable = row.status === 'Active';
              return '<button type="button" class="btn-cust-toggle" style="cursor:pointer;border:1px solid ' +
                (disable ? '#e3c3c3;color:#b23a3a' : '#c3e0cd;color:#1c7a4f') +
                ';background:#fff;border-radius:999px;padding:.4rem .8rem;min-height:32px;font-size:.76rem;font-weight:600">' +
                (disable ? 'Disable' : 'Enable') + '</button>';
            },
            cellClick: function (e, cell) {
              if (!e.target.closest('.btn-cust-toggle')) return;
              toggleActive(cell.getRow().getData());
            },
          },
@endif
        ],
      });

      document.getElementById('table-filter').addEventListener('input', (event) => {
        const value = event.target.value;
        if (value) {
          table.setFilter([
            [
              { field: 'name', type: 'like', value: value },
              { field: 'email', type: 'like', value: value },
              { field: 'phone', type: 'like', value: value },
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
