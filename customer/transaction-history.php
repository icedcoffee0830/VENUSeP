<?php require_once __DIR__ . '/../includes/auth.php'; customer_require_login(); /* customers only — guests go to the login page */ ?>
<?php
/* ==================================================================
   TRANSACTION HISTORY — VENUSeP merged system (customer portal)
   ==================================================================
   Customer variant of the transaction table (their own transactions).
   Same Tabulator engine as admin/transaction-history.php, minus the
   "Customer Name" column, on OUR customer shell ($portal='customer').

   The rows come from transaction_rows() in includes/bookings.php — the
   same builder the admin ledger uses, scoped to the session customer.
   This page used to declare its own $customerBookings array, which
   SHADOWED the one the include provides: a second hard-coded copy
   hiding behind the same variable name, free to disagree with the
   customer's own booking history. It did.
   ================================================================== */
require_once __DIR__ . '/../includes/customer-bookings.php';

$transactionRows = transaction_rows($customerContact['id']);
$transactionRowsJson = json_encode($transactionRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
?>
?>
<!DOCTYPE html>
<!-- MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
     [4] CONTENT (filters, export, table) · [6] SCRIPT (Tabulator).
     Rows come from transaction_rows() in includes/bookings.php. -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Transaction History</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS -->
    <style>
      :root {
        --black: #1f1e1e; --border: #e5e5e5; --muted: #606a75;
        --venusep-sidebar-width: 235px; --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { background: #fff; color: var(--black); font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; overflow-x: hidden; }
      .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; background: #fff; }
      .container-fluid { width: 100%; padding-inline: 30px; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
      }
    </style>

    <!-- [1] PAGE CSS -->
    <style>
      .th-page { padding: 22px 0 0; }
      .th-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
      .th-head h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin: 0; }
      .th-head p { color: var(--muted); font-size: 13px; margin: 4px 0 0; }
      .th-back { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 15px; border: 1px solid #ddd7ce; border-radius: 10px; background: #fff; color: #1f1e1e; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; transition: .2s; }
      .th-back:hover { background: #f4f2ee; border-color: #c9c2b6; }

      .th-panel { background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); overflow: hidden; }
      .th-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid #f0efec; }
      .th-panel-head h3 { font-size: 14.5px; font-weight: 650; margin: 0; }
      .th-search { display: flex; align-items: center; gap: 8px; min-width: 220px; height: 36px; padding: 0 12px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; }
      .th-search i { color: var(--muted); font-size: 14px; }
      .th-search input { border: 0; outline: 0; font: inherit; font-size: 13px; width: 100%; background: transparent; color: #1f1e1e; }

      .th-toolbar { display: flex; gap: 8px; flex-wrap: wrap; padding: 14px 18px 0; }
      .th-btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 13px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; color: #1f1e1e; font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: .2s; }
      .th-btn:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-btn i { font-size: 14px; color: var(--muted); }

      .th-filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto; gap: 12px; align-items: end; padding: 14px 18px 4px; }
      .th-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
      .th-field label { font-size: 11px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; color: var(--muted); }
      .th-field select, .th-field input { height: 36px; padding: 0 10px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; font: inherit; font-size: 13px; color: #1f1e1e; }
      .th-field select:focus, .th-field input:focus, .th-search input:focus { outline: none; }
      .th-field-reset { display: flex; align-items: end; }

      .th-table-wrap { padding: 14px 18px 18px; overflow-x: auto; }

      .t-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 600; line-height: 1.5; white-space: nowrap; }
      .t-green { background: #eaf6ef; color: #1c7a4f; }
      .t-amber { background: #fbf1dd; color: #8a5a00; }
      .t-red   { background: #fcecec; color: #b23a3a; }
      .t-navy  { background: #e9eff8; color: #2b4a7e; }
      .t-gray  { background: #eef0f2; color: #55606b; }

      .th-actions { display: inline-flex; gap: 6px; }
      .th-act { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: 1px solid #d7d7d7; background: #fff; color: #1f1e1e; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
      .th-act:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-act.is-disabled { opacity: .5; cursor: not-allowed; }

      .tabulator { border: 1px solid var(--border); border-radius: 10px; background: #fff; font-size: 13px; }
      .tabulator .tabulator-header { background: #faf9f7; border-bottom: 1px solid var(--border); }
      .tabulator .tabulator-header .tabulator-col { background: transparent; border-right: 1px solid #f0efec; }
      .tabulator .tabulator-header .tabulator-col .tabulator-col-title { color: #6b6258; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 4px 2px; }
      .tabulator .tabulator-tableholder .tabulator-table { background: #fff; }
      .tabulator-row { background: #fff; border-bottom: 1px solid #f2efe9; }
      .tabulator-row.tabulator-row-even { background: #fff; }
      .tabulator-row:hover { background: #faf9f7; }
      .tabulator-row .tabulator-cell { border-right: 1px solid #f6f4f0; padding: 10px 12px; color: #2b2928; }
      .tabulator .tabulator-footer { background: #faf9f7; border-top: 1px solid var(--border); }
      .tabulator .tabulator-footer .tabulator-page { border: 1px solid #d7d7d7; border-radius: 7px; background: #fff; color: #1f1e1e; }
      .tabulator .tabulator-footer .tabulator-page.active { background: #1f1e1e; color: #fff; border-color: #1f1e1e; }
      .tabulator .tabulator-footer .tabulator-page:hover:not(:disabled) { background: #f4f2ee; }

      @media (max-width: 1100px) { .th-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .th-field-reset { grid-column: 1 / -1; } }
      @media (max-width: 767.98px) { .th-head { flex-direction: column; } .tabulator .tabulator-tableholder, .tabulator .tabulator-header { -webkit-overflow-scrolling: touch; } .tabulator .tabulator-tableholder { overscroll-behavior-x: contain; } }
    
      /* on the crimson page background (painted by includes/header.php): light text, a card that floats */
      .th-head h1 { color: #fff; }
      .th-head p { color: #e9d0cd; }
      .th-panel { box-shadow: 0 18px 44px rgba(10,4,5,.28), 0 2px 6px rgba(10,4,5,.18); border-color: rgba(255,255,255,.18); }
    
      /* current page in the table's pagination: crimson, not black */
      .tabulator .tabulator-footer .tabulator-page.active { background: #a11626; color: #ffffff; border-color: #a11626; }
      /* type floor (readability): nothing on the page below 12px */
      .t-badge, .tabulator-col-title, .th-field label { font-size: 12px !important; }
          /* ---- phone: white page, cards edge to edge — the details get the width.
         (Desktop keeps the crimson gradient behind the white cards.) ---- */
      @media (max-width: 767.98px) {
        body { background: #fff !important; }
        .app-main { background: #fff !important; }
        .container-fluid { padding-inline: 12px; }
        .app-content-header { padding-top: 16px; }
        .th-head h1 { color: #1f1e1e; }
        .th-head p { color: #6e6a64; }
        .th-field label { color: #6e6a64; }
        .th-panel { box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid #e5e5e5; border-radius: 14px; }
      }
    </style>
  </head>
  <body class="customer-transaction-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Transaction History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="th-page">
              <div class="th-head">
                <div>
                  <h1>Transaction History</h1>
                  <p>Your booking and payment transaction records.</p>
                </div>
                <a class="th-back" href="venusep_venue_booking.php"><i class="bi bi-arrow-left"></i>Back to Venues</a>
              </div>

              <div class="th-panel">
                <div class="th-panel-head">
                  <h3>My Transactions</h3>
                  <div class="th-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="table-filter" type="search" placeholder="Filter rows..." aria-label="Filter rows" />
                  </div>
                </div>

                <div class="th-toolbar">
                  <button id="export-csv" type="button" class="th-btn"><i class="bi bi-filetype-csv"></i>Export CSV</button>
                  <button id="export-json" type="button" class="th-btn"><i class="bi bi-filetype-json"></i>Export JSON</button>
                  <button id="print-table" type="button" class="th-btn"><i class="bi bi-printer"></i>Print</button>
                </div>

                <div class="th-filters" aria-label="Transaction filters">
                  <div class="th-field">
                    <label for="payment-status-filter">Payment Status</label>
                    <select id="payment-status-filter">
                      <option value="">All</option>
                      <option value="Paid">Paid</option>
                      <option value="Pending">Pending</option>
                      <option value="Payment pending">Payment pending</option>
                      <option value="Payment due">Payment due</option>
                      <option value="Overdue">Overdue</option>
                      <option value="Unpaid">Unpaid</option>
                      <option value="Refunded">Refunded</option>
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="booking-status-filter">Booking Status</label>
                    <select id="booking-status-filter">
                      <option value="">All</option>
                      <option value="Pending">Pending</option>
                      <option value="Approved">Approved</option>
                      <option value="Rejected">Rejected</option>
                      <option value="Completed">Completed</option>
                      <option value="Cancelled">Cancelled</option>
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="payment-method-filter">Payment Method</label>
                    <select id="payment-method-filter">
                      <option value="">All</option>
                      <option value="Cash">Cash</option>
                      <option value="GCash">GCash</option>
                    </select>
                  </div>
                  <div class="th-field">
                    <label for="date-from-filter">Date From</label>
                    <input id="date-from-filter" type="date" />
                  </div>
                  <div class="th-field-reset">
                    <button id="reset-transaction-filters" type="button" class="th-btn"><i class="bi bi-arrow-counterclockwise"></i>Reset</button>
                  </div>
                </div>

                <div class="th-table-wrap">
                  <div id="transaction-history-table"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>

    <!-- [6] PAGE SCRIPT -->
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const rows = <?php echo $transactionRowsJson; ?>;
        const parseDate = (value) => { const p = Date.parse(value); return Number.isNaN(p) ? 0 : p; };
        const badgeClass = (value) => ({
          Paid: 't-green', Approved: 't-green', Pending: 't-amber',
          'Payment pending': 't-gray', 'Payment due': 't-amber', Overdue: 't-red',   /* post-pay states */
          Unpaid: 't-gray', Rejected: 't-red', Refunded: 't-navy', Completed: 't-navy', Cancelled: 't-gray',
        }[value] || 't-gray');
        const statusBadge = (cell) => `<span class="t-badge ${badgeClass(cell.getValue())}">${cell.getValue()}</span>`;
        const actionButtons = () => `
          <div class="th-actions">
            <a class="th-act" href="#"><i class="bi bi-eye"></i>View</a>
            <span class="th-act is-disabled" title="Receipt download coming soon"><i class="bi bi-download"></i>Receipt</span>
          </div>`;

        const table = new Tabulator('#transaction-history-table', {
          data: rows, layout: 'fitDataStretch', placeholder: 'No transactions found.',
          /* every column stays visible; on a phone the table scrolls sideways inside its card */
          pagination: true, paginationSize: 10, paginationSizeSelector: [10, 25, 50], movableColumns: true,
          initialSort: [{ column: 'transactionDate', dir: 'desc' }],
          columns: [
            { title: 'Transaction ID', field: 'transactionId', minWidth: 150, width: 150 },
            { title: 'Booking ID', field: 'bookingId', minWidth: 140, width: 140 },
            { title: 'Venue', field: 'venue', minWidth: 200, width: 200 },
            { title: 'Event Date', field: 'eventDate', sorter: (a, b) => parseDate(a) - parseDate(b), minWidth: 130, width: 130 },
            { title: 'Transaction Date', field: 'transactionDate', sorter: (a, b) => parseDate(a) - parseDate(b), minWidth: 150, width: 150 },
            { title: 'Amount', field: 'amount', minWidth: 110, width: 110, hozAlign: 'right' },
            { title: 'Payment Method', field: 'paymentMethod', minWidth: 150, width: 150 },
            { title: 'Payment Status', field: 'paymentStatus', formatter: statusBadge, minWidth: 130, width: 130, hozAlign: 'center' },
            { title: 'Booking Status', field: 'bookingStatus', formatter: statusBadge, minWidth: 130, width: 130, hozAlign: 'center' },
            { title: 'Actions', field: 'actions', formatter: actionButtons, headerSort: false, minWidth: 180, width: 180, hozAlign: 'center' },
          ],
        });

        const globalFilter = document.getElementById('table-filter');
        const paymentStatusFilter = document.getElementById('payment-status-filter');
        const bookingStatusFilter = document.getElementById('booking-status-filter');
        const paymentMethodFilter = document.getElementById('payment-method-filter');
        const dateFromFilter = document.getElementById('date-from-filter');
        const resetFilters = document.getElementById('reset-transaction-filters');

        const applyFilters = () => {
          const query = globalFilter.value.trim().toLowerCase();
          const paymentStatus = paymentStatusFilter.value;
          const bookingStatus = bookingStatusFilter.value;
          const paymentMethod = paymentMethodFilter.value;
          const dateFrom = dateFromFilter.value ? Date.parse(dateFromFilter.value) : null;
          table.setFilter((row) => {
            const rowText = [row.transactionId, row.bookingId, row.venue, row.eventDate, row.transactionDate, row.amount, row.paymentMethod, row.paymentStatus, row.bookingStatus].join(' ').toLowerCase();
            return (!query || rowText.includes(query))
              && (!paymentStatus || row.paymentStatus === paymentStatus)
              && (!bookingStatus || row.bookingStatus === bookingStatus)
              && (!paymentMethod || row.paymentMethod === paymentMethod)
              && (!dateFrom || parseDate(row.transactionDate) >= dateFrom);
          });
        };
        [globalFilter, paymentStatusFilter, bookingStatusFilter, paymentMethodFilter, dateFromFilter].forEach((c) => c.addEventListener('input', applyFilters));
        resetFilters.addEventListener('click', () => {
          globalFilter.value = ''; paymentStatusFilter.value = ''; bookingStatusFilter.value = ''; paymentMethodFilter.value = ''; dateFromFilter.value = '';
          table.clearFilter();
        });
        document.getElementById('export-csv').addEventListener('click', () => table.download('csv', 'my-transactions.csv'));
        document.getElementById('export-json').addEventListener('click', () => table.download('json', 'my-transactions.json'));
        document.getElementById('print-table').addEventListener('click', () => table.print(false, true));
      });
    </script>
  </body>
</html>
