<?php ?>
<!DOCTYPE html>
<html lang="en">
<head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
  <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
  <title>Venusep | Staff Management</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes"/>
  <meta name="color-scheme" content="light"/>
  <meta name="theme-color" content="#007bff" media="(prefers-color-scheme: light)"/>
  <meta name="theme-color" content="#1a1a1a" media="(prefers-color-scheme: dark)"/>
  <meta name="title" content="Venusep | Staff Management"/>
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
</head>
<body>
  <div class="app-wrapper">
        <!-- shared header: edit ../includes/header.php and every page updates -->
    <?php include __DIR__ . '/../includes/header.php'; ?>

        <!-- shared sidebar: edit ../includes/sidebar.php and every page updates -->
    <?php $active = 'Staff Management'; include __DIR__ . '/../includes/sidebar.php'; ?>

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
              <div class="input-group input-group-sm table-search">
                <span class="input-group-text"><i class="bi bi-search" aria-hidden="true"></i></span>
                <input id="table-filter" type="search" class="form-control" placeholder="Filter rows..." aria-label="Filter rows"/>
              </div>
            </div>
            <div class="staff-panel-body">
              <div id="users-table"></div>
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
    const roleBadge = (cell) => `<span class="badge-role">${cell.getValue()}</span>`;
    const statusBadge = (cell) => {
      const value = cell.getValue();
      const map = { Active: 'success', Invited: 'info', Suspended: 'secondary' };
      const color = map[value] || 'secondary';
      return `<span class="badge text-bg-${color}">${value}</span>`;
    };

    document.addEventListener('DOMContentLoaded', () => {
      const data = [
        { id: 1, name: 'Olivia Bennett', email: 'olivia@example.com', role: 'Staff', status: 'Active' },
        { id: 2, name: 'Liam Carter', email: 'liam@example.com', role: 'Staff', status: 'Active' },
        { id: 3, name: 'Emma Dawson', email: 'emma@example.com', role: 'Staff', status: 'Invited' },
        { id: 4, name: 'Noah Evans', email: 'noah@example.com', role: 'Staff', status: 'Suspended' },
        { id: 5, name: 'Ava Foster', email: 'ava@example.com', role: 'Staff', status: 'Active' },
        { id: 6, name: 'Ethan Grant', email: 'ethan@example.com', role: 'Staff', status: 'Active' },
        { id: 7, name: 'Sophia Hayes', email: 'sophia@example.com', role: 'Staff', status: 'Active' },
        { id: 8, name: 'Mason Ingram', email: 'mason@example.com', role: 'Staff', status: 'Invited' },
        { id: 9, name: 'Isabella Jones', email: 'isabella@example.com', role: 'Staff', status: 'Active' },
        { id: 10, name: 'Lucas Klein', email: 'lucas@example.com', role: 'Staff', status: 'Suspended' },
        { id: 11, name: 'Mia Lopez', email: 'mia@example.com', role: 'Staff', status: 'Active' },
        { id: 12, name: 'Logan Moore', email: 'logan@example.com', role: 'Staff', status: 'Active' },
        { id: 13, name: 'Charlotte Nelson', email: 'charlotte@example.com', role: 'Staff', status: 'Active' },
        { id: 14, name: 'Henry Owens', email: 'henry@example.com', role: 'Staff', status: 'Invited' },
        { id: 15, name: 'Amelia Price', email: 'amelia@example.com', role: 'Staff', status: 'Active' },
      ];

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
            title: 'Status',
            field: 'status',
            formatter: statusBadge,
            headerFilter: 'list',
            headerFilterParams: { values: ['', 'Active', 'Invited', 'Suspended'] },
            width: 140,
            hozAlign: 'center',
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
