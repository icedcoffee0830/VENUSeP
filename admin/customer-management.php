<?php
require_once __DIR__ . '/../includes/auth.php';
/* ADMIN AND STAFF. Unlike Staff Management (admin-only — staff managing
   their own side would be a conflict of interest), disabling a CUSTOMER is
   ordinary front-desk work: the same people who already verify a customer's
   identity at the counter (admin/customer-verify.php) are the ones who need
   to shut off an account that is abusing the system, e.g. repeated no-shows
   or a fraudulent GCash receipt. admin_require_login()'s default already
   covers both roles. */
admin_require_login();
?>
<?php
/* THE REAL CUSTOMER ROSTER — account holders only. Walk-ins (customers with
   no user_id, no login — see customer-search.php) are excluded on purpose:
   there is no account there to disable, and "Suspended" would be a status
   this page invented rather than one the database can represent. */
require_once __DIR__ . '/../includes/db.php';

$cmRows = [];
try {
    $cmPdo = venusep_db_or_fail();
    $cmStmt = $cmPdo->query(
        "SELECT u.id AS user_id, u.email, u.is_active, u.last_login_at,
                c.id AS customer_id, c.full_name, c.phone, c.university_id_no, c.created_at,
                (SELECT COUNT(*) FROM bookings b WHERE b.customer_id = c.id) AS booking_count
           FROM customers c
           JOIN users u ON u.id = c.user_id
          WHERE u.account_type = 'customer'
          ORDER BY c.full_name"
    );
    foreach ($cmStmt as $r) {
        $cmRows[] = [
            'userId'    => (int) $r['user_id'],
            'id'        => (int) $r['customer_id'],
            'name'      => (string) $r['full_name'],
            'email'     => (string) $r['email'],
            'phone'     => (string) ($r['phone'] ?? ''),
            'usep'      => $r['university_id_no'] !== null && $r['university_id_no'] !== '' ? 'USeP' : '—',
            'status'    => $r['is_active'] ? 'Active' : 'Disabled',
            'bookings'  => (int) $r['booking_count'],
            'joined'    => $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '—',
            'lastLogin' => $r['last_login_at'] ? date('M j, Y', strtotime($r['last_login_at'])) : 'never',
        ];
    }
} catch (PDOException $e) {
    $cmRows = [];
}
$cmCsrf = csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Venusep | Customer Management</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes"/>
  <meta name="color-scheme" content="light"/>
  <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Customer Management"/>
  <meta name="supported-color-schemes" content="light"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5.0.18/index.css" crossorigin="anonymous" media="print" onload="this.media = 'all'"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/styles/overlayscrollbars.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous"/>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous"/>
  <style>
:root {
  --venusep-black: #1f1e1e;
  --venusep-cream: #ffffff;
  --venusep-border: #ddd7ce;
  --venusep-text: #050505;
  --venusep-sidebar-width: 235px;
  --venusep-header-height: 58px;
}

* {
  box-sizing: border-box;
}

html,
body {
  margin: 0;
  min-height: 100%;
}

body {
  color: var(--venusep-text);
  background: var(--venusep-cream);
  font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  font-size: 0.88rem;
  overflow-x: hidden;
}

.app-wrapper {
  --lte-sidebar-width: var(--venusep-sidebar-width);
  display: block;
  width: 100%;
  min-height: 100vh;
  background: var(--venusep-cream);
}

.app-header {
  position: fixed;
  top: 0;
  right: 0;
  left: var(--venusep-sidebar-width);
  z-index: 1030;
  min-height: var(--venusep-header-height);
  height: var(--venusep-header-height);
  background: var(--venusep-black);
  border-bottom: 1px solid #111;
  color: #fff;
  display: flex;
  align-items: center;
}

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

  </style>
</head>
<body>
  <div class="app-wrapper">
        <!-- shared header: edit ../includes/header.php and every page updates -->
    <?php include __DIR__ . '/../includes/header.php'; ?>

        <!-- shared sidebar: edit ../includes/sidebar.php and every page updates -->
    <?php $active = 'Customer Management'; include __DIR__ . '/../includes/sidebar.php'; ?>

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
  </div>

  <script src="https://cdn.jsdelivr.net/npm/overlayscrollbars@2.11.0/browser/overlayscrollbars.browser.es6.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.8/dist/umd/popper.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.min.js" crossorigin="anonymous"></script>
  <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
  <script>
    const statusBadge = (cell) => {
      const value = cell.getValue();
      return `<span class="badge text-bg-${value === 'Active' ? 'success' : 'secondary'}">${value}</span>`;
    };

    document.addEventListener('DOMContentLoaded', () => {
      /* The real customer accounts, from `users` + `customers`. Walk-ins
         (no user_id) never appear here — see admin/customer-management.php. */
      const data = <?php echo json_encode($cmRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
      const CSRF = <?php echo json_encode($cmCsrf); ?>;

      /* Every write goes to admin/customer-save.php, which re-checks that the
         caller is admin or staff. That check lives there, not here — this is
         a convenience, not a boundary. */
      const customerAction = function (body, onOk) {
        body.append('csrf', CSRF);
        return fetch('customer-save.php', { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
          .then(function (res) {
            if (!res.ok) { window.alert(res.message || 'Nothing was changed.'); return false; }
            if (typeof onOk === 'function') onOk(res);
            return true;
          })
          .catch(function () { window.alert('Could not reach the server, so nothing was changed.'); return false; });
      };

      const toggleActive = function (row) {
        const disable = row.status === 'Active';
        const ok = window.confirm(
          disable
            ? 'Disable ' + row.name + '?\n\nThey will be signed out immediately and cannot sign in or book again until re-enabled. Their existing bookings and history are unaffected.'
            : 'Re-enable ' + row.name + '?\n\nThey will be able to sign in and book again.');
        if (!ok) return;
        const body = new FormData();
        body.append('action', 'set_active');
        body.append('user_id', row.userId);
        body.append('active', disable ? '0' : '1');
        customerAction(body, function () { window.location.reload(); });
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
          { title: 'Name', field: 'name', headerFilter: 'input' },
          { title: 'Email', field: 'email', headerFilter: 'input' },
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
                ';background:#fff;border-radius:999px;padding:.2rem .7rem;font-size:.76rem;font-weight:600">' +
                (disable ? 'Disable' : 'Enable') + '</button>';
            },
            cellClick: function (e, cell) {
              if (!e.target.closest('.btn-cust-toggle')) return;
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
</body>
</html>
