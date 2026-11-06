{{-- The admin list pages (Staff, Customers): heading, section tabs, labelled filters, quiet row
     buttons, the Tabulator look, and one card per row on a phone. Pair: assets/js/list-page.js. --}}
.app-main { margin-left: var(--venusep-sidebar-width) !important; padding-top: var(--venusep-header-height) !important; width: calc(100vw - var(--venusep-sidebar-width)) !important; min-height: 100vh; }
.staff-content { min-height: calc(100vh - var(--venusep-header-height)); padding: 26px 30px 40px !important; color: #1f1e1e; }
.staff-content .container-fluid { padding: 0; }

.lp-head { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
.lp-head h1 { margin: 0; font-size: 22px; font-weight: 700; letter-spacing: -.01em; color: #1f1e1e; text-wrap: balance; }
.lp-head p { margin: 4px 0 0; color: #6e6a64; font-size: 13.5px; }

.lp-seg { display: flex; gap: 4px; margin-bottom: 16px; border-bottom: 1px solid #e5e5e5; overflow-x: auto; scrollbar-width: none; }
.lp-seg a { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; margin-bottom: -1px; padding: 0 14px; border-bottom: 2px solid transparent; color: #6e6a64; font-size: 14px; font-weight: 600; text-decoration: none; white-space: nowrap; }
.lp-seg a:hover { color: #1f1e1e; }
.lp-seg a[aria-current] { color: #a11626; border-bottom-color: #a11626; }
.lp-seg .vz-chip { font-variant-numeric: tabular-nums; }

.staff-panel { width: 100%; overflow: hidden; background: #fff; border: 1px solid #e5e5e5; border-radius: 14px; box-shadow: 0 1px 2px rgba(0, 0, 0, .04); }
.staff-panel-header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 1rem; border-bottom: 1px solid #f0efec; }
.staff-panel-header h2 { margin: 0; font-size: .95rem; font-weight: 700; }
.staff-panel-tools { display: flex; align-items: center; gap: .6rem; }
.staff-panel-body { padding: 1rem; }
.btn-staff-add { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-height: 38px; padding: 0 1rem; border-radius: 10px; background: #a11626; color: #fff; font: inherit; font-size: 13.5px; font-weight: 700; text-decoration: none; white-space: nowrap; box-shadow: 0 8px 20px rgba(138, 18, 34, .22); }
.btn-staff-add:hover { background: #7d0f1e; color: #fff; }

.lp-tools { display: flex; align-items: flex-end; flex-wrap: wrap; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #f0efec; }
.lp-search { position: relative; display: block; flex: 1 1 220px; max-width: 320px; }
.lp-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #6e6a64; font-size: 14px; }
.lp-search input { width: 100%; height: 38px; padding: 0 10px 0 34px; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; color: #1f1e1e; font: inherit; font-size: 13.5px; }
.lp-field { display: flex; flex: 0 1 165px; flex-direction: column; gap: 5px; min-width: 0; }
.lp-field label { color: #6e6a64; font-size: 12px; font-weight: 600; letter-spacing: .03em; }
.lp-field select, .lp-field input { width: 100%; height: 38px; padding: 0 10px; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; color: #1f1e1e; font: inherit; font-size: 13.5px; }
.lp-filters-btn { display: none; align-items: center; gap: .4rem; flex: none; height: 44px; padding: 0 1rem; border: 1px solid #d7d7d7; border-radius: 10px; background: #fff; color: #1f1e1e; font: inherit; font-size: 13.5px; font-weight: 600; cursor: pointer; }

.lp-name { display: block; font-weight: 600; color: #1f1e1e; }
.lp-sub { display: block; margin-top: 2px; color: #6e6a64; font-size: 12.5px; }
.lp-ell { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lp-stack { display: flex; flex-direction: column; align-items: flex-start; gap: 3px; min-width: 0; width: 100%; }
.lp-row { display: flex; align-items: center; gap: 8px; min-width: 0; width: 100%; }

/* a row's actions: one click, in plain sight, without shouting */
.lp-acts { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 4px; width: 100%; }
.lp-btn { display: inline-flex; align-items: center; justify-content: center; gap: 5px; min-height: 28px; padding: 0 9px; border: 1px solid #e5e2dc; border-radius: 8px; background: #fff; color: #3f3b36; font: inherit; font-size: 12.5px; font-weight: 600; white-space: nowrap; text-decoration: none; cursor: pointer; }
.lp-btn:hover { border-color: #a11626; color: #a11626; }
.lp-btn-no { color: #b23a3a; }
.lp-btn-ok { color: #1c7a4f; }
.lp-btn:focus-visible, .lp-seg a:focus-visible, .lp-menu button:focus-visible, .lp-menu a:focus-visible { outline: 2px solid #a11626; outline-offset: 2px; }
.lp-you { color: #6e6a64; font-size: 12.5px; }

/* the small menu: a native popover, so it sits above the table and Esc closes it */
.lp-menu { position: fixed; inset: auto; margin: 0; padding: 6px; border: 1px solid #e5e5e5; border-radius: 12px; background: #fff; box-shadow: 0 14px 34px rgba(29, 18, 20, .18); }
.lp-menu button, .lp-menu a { display: flex; align-items: center; gap: 10px; width: 100%; min-height: 40px; padding: 6px 10px; border: 0; border-radius: 8px; background: transparent; color: #1f1e1e; font: inherit; font-size: 13.5px; text-align: left; text-decoration: none; cursor: pointer; }
.lp-menu button:hover, .lp-menu a:hover { background: #f9eef0; color: #a11626; }
.lp-menu small { display: block; color: #6e6a64; font-size: 12px; }

/* the table */
.tabulator.lp-table { border: 0; background: #fff; font-size: 13.5px; font-family: inherit; }
.lp-table .tabulator-header { border-bottom: 1px solid #e5e5e5; background: #faf9f7; color: #6b6258; font-size: 12px; font-weight: 700; letter-spacing: .03em; }
.lp-table .tabulator-header .tabulator-col { border-right: 0; background: #faf9f7; }
.lp-table .tabulator-header .tabulator-col[tabulator-field="actions"] .tabulator-col-title { color: transparent; }
.lp-table .tabulator-tableholder .tabulator-table { background: #fff; color: #1f1e1e; }
.lp-table .tabulator-row, .lp-table .tabulator-row.tabulator-row-even, .lp-table .tabulator-row.tabulator-row-odd { background: #fff !important; border-bottom: 1px solid #f2efe9; }
.lp-table .tabulator-row:hover { background: #fcfbfa !important; }
.lp-table .tabulator-row .tabulator-cell { display: inline-flex; align-items: center; padding: 12px 10px; border-right: 0; line-height: 1.4; white-space: normal; overflow-wrap: anywhere; }
.lp-table .tabulator-placeholder .tabulator-placeholder-contents { padding: 24px 16px; color: #6e6a64; font-size: 14px; font-weight: 400; white-space: normal; }
.lp-table .tabulator-footer { padding: 8px 16px; border-top: 0; background: #fff; }
.lp-table .tabulator-footer .tabulator-footer-contents { justify-content: space-between; }
.lp-table .tabulator-footer .tabulator-page-counter { color: #6e6a64; font-size: 13px; font-weight: 400; }
.lp-table .tabulator-footer .tabulator-paginator { flex: none; gap: 6px; color: #6e6a64; font-size: 13px; font-weight: 400; }
.lp-table .tabulator-footer .tabulator-page-size { min-height: 34px; margin: 0; padding: 0 8px; border: 1px solid #d7d7d7; border-radius: 8px; background: #fff; color: #1f1e1e; font: inherit; }
.lp-table .tabulator-footer .tabulator-page { min-width: 34px; min-height: 34px; margin: 0; padding: 0; border: 1px solid #d7d7d7; border-radius: 8px; background: #fff; color: #1f1e1e; font: inherit; font-size: 16px; line-height: 1; }
.lp-table .tabulator-footer .tabulator-page:disabled { opacity: .4; cursor: default; }
.lp-table .tabulator-footer .tabulator-page[data-page="first"], .lp-table .tabulator-footer .tabulator-page[data-page="last"], .lp-table .tabulator-footer .tabulator-pages { display: none; }

@media (max-width: 767.98px) {
  :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
  .app-main { margin-left: 0 !important; width: 100vw !important; }
  .staff-content { padding: 1.1rem 1rem 3rem !important; }
  .lp-head { flex-direction: column; align-items: stretch; }
  .btn-staff-add { min-height: 44px; width: 100%; }
  .staff-panel-header, .staff-panel-tools { flex-wrap: wrap; width: 100%; }

  /* search and Filters on one line; the boxes open under them */
  .lp-tools { padding: 12px; }
  .lp-search { flex: 1 1 calc(100% - 120px); min-width: 0; max-width: none; }
  .lp-search input { height: 44px; }
  .lp-filters-btn { display: inline-flex; }
  .lp-field { display: none; flex: 1 1 calc(50% - 6px); }
  .lp-tools.is-open .lp-field { display: flex; }
  .lp-field select, .lp-field input { height: 44px; }

  .lp-table .tabulator-footer { padding: 8px 12px; }
  .lp-table .tabulator-footer .tabulator-page, .lp-table .tabulator-footer .tabulator-page-size { min-width: 44px; min-height: 44px; }

  /* one card per row: the page says each value's label with [tabulator-field]::before */
  .lp-table .tabulator-header, .lp-table .tabulator-row .tabulator-col-resize-handle { display: none; }
  .lp-table .tabulator-tableholder { height: auto !important; overflow: visible; }
  .lp-table .tabulator-tableholder .tabulator-table { display: block; width: 100%; min-width: 0 !important; }
  .lp-table .tabulator-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; align-content: start; gap: 8px 12px; height: auto !important; min-height: 0 !important; padding: 14px 12px; border-bottom: 1px solid #ece8e1; }
  .lp-table .tabulator-row .tabulator-cell { display: block; width: auto !important; height: auto !important; min-width: 0; padding: 0; }
  .lp-table .tabulator-row .tabulator-cell[tabulator-field]::before { display: block; margin-bottom: 2px; color: #6e6a64; font-size: 12px; font-weight: 600; }
  .lp-table .tabulator-row .tabulator-cell.lp-wide, .lp-table .tabulator-row .tabulator-cell[tabulator-field="actions"] { grid-column: 1 / -1; }
  .lp-acts { justify-content: flex-start; }
  .lp-btn { flex: 1 1 auto; min-height: 44px; }
}
