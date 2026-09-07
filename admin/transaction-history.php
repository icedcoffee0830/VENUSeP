<?php
/* ==================================================================
   TRANSACTION HISTORY — VENUSeP merged system (admin view)
   ==================================================================
   Ported from the teammate's transaction-history.php (Tabulator table).
   Rewired to OUR shared shell (header.php + sidebar.php) and restyled
   to the team palette. Same table/filter/export behavior.

   [SIM] the $placeholderBookings array below is demo data — replace the
   whole block with a real "SELECT ... FROM transactions" query later.
   Rooms are the real r1–r8 venue rooms (includes/venue-rooms.php) with
   amounts matching each room's per-day fee; methods are GCash/Cash only.
   Customer names are varied on purpose (admin sees every customer, not
   just the session user).
   ================================================================== */

// [SIM] demo bookings → each becomes one transaction row (delete when DB is wired).
$placeholderBookings = [
    ['bookingId'=>'VB-2026-001','customerName'=>'Juan Miguel Dela Cruz','venue'=>'USeP Gymnasium','eventDate'=>'2026-07-18','bookingDate'=>'2026-06-22','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>8000],
    ['bookingId'=>'VB-2026-002','customerName'=>'Marco Santos','venue'=>'CIC Audio-Visual Room','eventDate'=>'2026-07-20','bookingDate'=>'2026-06-24','paymentMethod'=>'Cash','paymentStatus'=>'Pending','status'=>'Pending','amount'=>2000],
    ['bookingId'=>'VB-2026-003','customerName'=>'Janelle Cruz','venue'=>'Alumni Grand Ballroom','eventDate'=>'2026-07-22','bookingDate'=>'2026-06-25','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Completed','amount'=>5000],
    ['bookingId'=>'VB-2026-004','customerName'=>'Derek Villanueva','venue'=>'Alumni Boardroom','eventDate'=>'2026-07-25','bookingDate'=>'2026-06-27','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>1500],
    ['bookingId'=>'VB-2026-005','customerName'=>'Bianca Reyes','venue'=>'Obrero Function Hall','eventDate'=>'2026-07-26','bookingDate'=>'2026-06-29','paymentMethod'=>'Cash','paymentStatus'=>'Unpaid','status'=>'Rejected','amount'=>3000],
    ['bookingId'=>'VB-2026-006','customerName'=>'Paolo Navarro','venue'=>'Admin Conference Hall','eventDate'=>'2026-07-28','bookingDate'=>'2026-07-01','paymentMethod'=>'Cash','paymentStatus'=>'Pending','status'=>'Pending','amount'=>1800],
    ['bookingId'=>'VB-2026-007','customerName'=>'Hannah Flores','venue'=>'Alumni Grand Ballroom','eventDate'=>'2026-08-02','bookingDate'=>'2026-07-03','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>5000],
    ['bookingId'=>'VB-2026-008','customerName'=>'Carlo Ramirez','venue'=>'Heritage Function Room','eventDate'=>'2026-08-04','bookingDate'=>'2026-07-05','paymentMethod'=>'Cash','paymentStatus'=>'Refunded','status'=>'Completed','amount'=>2500],
    ['bookingId'=>'VB-2026-009','customerName'=>'Sofia Aquino','venue'=>'USeP Gymnasium','eventDate'=>'2026-08-06','bookingDate'=>'2026-07-06','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Completed','amount'=>8000],
    ['bookingId'=>'VB-2026-010','customerName'=>'Miguel Castillo','venue'=>'Admin Conference Hall','eventDate'=>'2026-08-08','bookingDate'=>'2026-07-08','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>1800],
    ['bookingId'=>'VB-2026-011','customerName'=>'Andrea Torres','venue'=>'USeP Gymnasium','eventDate'=>'2026-08-10','bookingDate'=>'2026-07-10','paymentMethod'=>'Cash','paymentStatus'=>'Pending','status'=>'Pending','amount'=>8000],
    ['bookingId'=>'VB-2026-012','customerName'=>'Nathan Garcia','venue'=>'Obrero Function Hall','eventDate'=>'2026-08-12','bookingDate'=>'2026-07-12','paymentMethod'=>'GCash','paymentStatus'=>'Refunded','status'=>'Rejected','amount'=>3000],
    ['bookingId'=>'VB-2026-013','customerName'=>'Grace Lim','venue'=>'Alumni Grand Ballroom','eventDate'=>'2026-08-14','bookingDate'=>'2026-07-14','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>5000],
    ['bookingId'=>'VB-2026-014','customerName'=>'Marco Santos','venue'=>'Heritage Function Room','eventDate'=>'2026-08-18','bookingDate'=>'2026-07-15','paymentMethod'=>'GCash','paymentStatus'=>'Paid','status'=>'Approved','amount'=>2500],
    ['bookingId'=>'VB-2026-015','customerName'=>'Janelle Cruz','venue'=>'CIC Audio-Visual Room','eventDate'=>'2026-08-20','bookingDate'=>'2026-07-16','paymentMethod'=>'Cash','paymentStatus'=>'Paid','status'=>'Completed','amount'=>2000],
];

// Shape rows exactly like the future database result columns.
$transactionRows = [];
foreach ($placeholderBookings as $index => $booking) {
    $transactionRows[] = [
        'transactionId'   => 'TXN-2026-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
        'bookingId'       => $booking['bookingId'],
        'customerName'    => $booking['customerName'],
        'venue'           => $booking['venue'],
        'eventDate'       => date('F j, Y', strtotime($booking['eventDate'])),
        'transactionDate' => date('F j, Y', strtotime($booking['bookingDate'])),
        'amount'          => '₱' . number_format($booking['amount']),
        'paymentMethod'   => $booking['paymentMethod'],
        'paymentStatus'   => $booking['paymentStatus'],
        'bookingStatus'   => $booking['status'],
    ];
}
$transactionRowsJson = json_encode($transactionRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump:
    [0] SHELL CSS     team header + sidebar layout (shared includes)
    [1] PAGE CSS      this page's styles + Tabulator + palette badges
    [2] HEADER BAR    shared include
    [3] SIDEBAR       shared include ($active = 'Transaction History')
    [4] PAGE CONTENT  title, filters, export toolbar, table container
    [6] PAGE SCRIPT   Tabulator table + filtering + CSV/JSON/print
  [SIM] = demo-only, replace at database time.
  ================================================================== -->
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

    <!-- ============================================================
         [0] SHELL CSS — team header bar + sidebar + layout (shared).
         Same block every ported page uses; needs no AdminLTE stylesheet.
         ============================================================ -->
    <style>
      :root {
        --venusep-black: #1f1e1e;
        --venusep-border: #e5e5e5;
        --venusep-muted: #606a75;
        --venusep-text: #1f1e1e;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* (header + sidebar styles live in ../includes/ — the shared includes) */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
      }
    </style>

    <!-- ============================================================
         [1] PAGE CSS — transaction page styles, self-contained.
         ============================================================ -->
    <style>
      .th-page { padding: 22px 0 0; } /* 22px + 4px from .app-content = 26px top, matches other pages */

      .th-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
      .th-head h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin: 0; }
      .th-head p { color: var(--venusep-muted); font-size: 13px; margin: 4px 0 0; }

      /* back-to-dashboard button */
      .th-back { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 15px; border: 1px solid #ddd7ce; border-radius: 10px; background: #fff; color: #1f1e1e; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; transition: .2s; }
      .th-back:hover { background: #f4f2ee; border-color: #c9c2b6; }

      /* card / panel — same language as the rest of the system */
      .th-panel { background: #fff; border: 1px solid var(--venusep-border); border-radius: 14px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); overflow: hidden; }
      .th-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid #f0efec; }
      .th-panel-head h3 { font-size: 14.5px; font-weight: 650; margin: 0; }

      /* search box */
      .th-search { display: flex; align-items: center; gap: 8px; min-width: 220px; height: 36px; padding: 0 12px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; }
      .th-search i { color: var(--venusep-muted); font-size: 14px; }
      .th-search input { border: 0; outline: 0; font: inherit; font-size: 13px; width: 100%; background: transparent; color: #1f1e1e; }

      /* export toolbar */
      .th-toolbar { display: flex; gap: 8px; flex-wrap: wrap; padding: 14px 18px 0; }
      .th-btn { display: inline-flex; align-items: center; gap: 6px; height: 34px; padding: 0 13px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; color: #1f1e1e; font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: .2s; }
      .th-btn:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-btn i { font-size: 14px; color: var(--venusep-muted); }

      /* filter panel */
      .th-filters { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)) auto; gap: 12px; align-items: end; padding: 14px 18px 4px; }
      .th-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
      .th-field label { font-size: 11px; font-weight: 600; letter-spacing: 0.03em; text-transform: uppercase; color: var(--venusep-muted); }
      .th-field select, .th-field input { height: 36px; padding: 0 10px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; font: inherit; font-size: 13px; color: #1f1e1e; }
      .th-field select:focus, .th-field input:focus, .th-search input:focus { outline: none; }
      .th-field-reset { display: flex; align-items: end; }

      /* table wrapper — horizontal scroll for the wide 11-column table */
      .th-table-wrap { padding: 14px 18px 18px; overflow-x: auto; }

      /* status badges — team soft-tint palette (no loud fills, no bold) */
      .t-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 600; line-height: 1.5; white-space: nowrap; }
      .t-green { background: #eaf6ef; color: #1c7a4f; }
      .t-amber { background: #fbf1dd; color: #8a5a00; }
      .t-red   { background: #fcecec; color: #b23a3a; }
      .t-navy  { background: #e9eff8; color: #2b4a7e; }
      .t-gray  { background: #eef0f2; color: #55606b; }

      /* row action buttons */
      .th-actions { display: inline-flex; gap: 6px; }
      .th-act { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: 1px solid #d7d7d7; background: #fff; color: #1f1e1e; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
      .th-act:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-act.is-disabled { opacity: .5; cursor: not-allowed; }

      /* Tabulator restyle — matches the team look (adapted from Staff Management) */
      .tabulator { border: 1px solid var(--venusep-border); border-radius: 10px; background: #fff; font-size: 13px; }
      .tabulator .tabulator-header { background: #faf9f7; border-bottom: 1px solid var(--venusep-border); }
      .tabulator .tabulator-header .tabulator-col { background: transparent; border-right: 1px solid #f0efec; }
      .tabulator .tabulator-header .tabulator-col .tabulator-col-title { color: #6b6258; font-size: 11px; font-weight: 700; letter-spacing: 0.04em; text-transform: uppercase; padding: 4px 2px; }
      .tabulator .tabulator-tableholder .tabulator-table { background: #fff; }
      .tabulator-row { background: #fff; border-bottom: 1px solid #f2efe9; }
      .tabulator-row.tabulator-row-even { background: #fff; }
      .tabulator-row:hover { background: #faf9f7; }
      .tabulator-row .tabulator-cell { border-right: 1px solid #f6f4f0; padding: 10px 12px; color: #2b2928; }
      .tabulator .tabulator-footer { background: #faf9f7; border-top: 1px solid var(--venusep-border); }
      .tabulator .tabulator-footer .tabulator-page { border: 1px solid #d7d7d7; border-radius: 7px; background: #fff; color: #1f1e1e; }
      .tabulator .tabulator-footer .tabulator-page.active { background: #1f1e1e; color: #fff; border-color: #1f1e1e; }
      .tabulator .tabulator-footer .tabulator-page:hover:not(:disabled) { background: #f4f2ee; }

      @media (max-width: 1100px) {
        .th-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .th-field-reset { grid-column: 1 / -1; }
      }
      @media (max-width: 767.98px) {
        .th-head { flex-direction: column; }
      }
    </style>
  </head>
  <body>
    <div class="app-wrapper">
      <!-- [2] HEADER BAR — shared include -->
      <?php include __DIR__ . '/../includes/header.php'; ?>

      <!-- [3] SIDEBAR — shared include -->
      <?php $active = 'Transaction History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="th-page">
              <div class="th-head">
                <div>
                  <h1>Transaction History</h1>
                  <p>Review booking and payment transaction records.</p>
                </div>
                <a class="th-back" href="Admin_Dashboard.php"><i class="bi bi-arrow-left"></i>Back to Dashboard</a>
              </div>

              <div class="th-panel">
                <div class="th-panel-head">
                  <h3>All Transactions</h3>
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
                  <div class="th-field">
                    <label for="date-to-filter">Date To</label>
                    <input id="date-to-filter" type="date" />
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

    <!-- Tabulator library (the only third-party script this page needs) -->
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>

    <!-- ============================================================
         [6] PAGE SCRIPT — builds the table, filters, exports.
         ============================================================ -->
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        // [SIM] rows come from the PHP demo block above (swap for DB rows later).
        const rows = <?php echo $transactionRowsJson; ?>;
        const emptyTransactionMessage = 'No transactions found.';

        const parseDate = (value) => {
          const parsed = Date.parse(value);
          return Number.isNaN(parsed) ? 0 : parsed;
        };

        // status → team soft-tint badge
        const badgeClass = (value) => ({
          Paid: 't-green', Approved: 't-green',
          Pending: 't-amber',
          Rejected: 't-red',
          Unpaid: 't-gray', Cancelled: 't-gray',
          Refunded: 't-navy', Completed: 't-navy',
        }[value] || 't-gray');
        const statusBadge = (cell) => {
          const v = cell.getValue();
          return `<span class="t-badge ${badgeClass(v)}">${v}</span>`;
        };

        const actionButtons = (cell) => {
          const id = encodeURIComponent(cell.getRow().getData().transactionId);
          // [SIM] View + Download Receipt are stubs — wire to transaction-details.php
          //       and receipt generation when the backend exists.
          return `
            <div class="th-actions">
              <a class="th-act" href="#" data-txn="${id}"><i class="bi bi-eye"></i>View</a>
              <span class="th-act is-disabled" title="Receipt download coming soon"><i class="bi bi-download"></i>Receipt</span>
            </div>`;
        };

        const table = new Tabulator('#transaction-history-table', {
          data: rows,
          layout: 'fitDataStretch',
          placeholder: emptyTransactionMessage,
          pagination: true,
          paginationSize: 10,
          paginationSizeSelector: [10, 25, 50, 100],
          movableColumns: true,
          initialSort: [{ column: 'transactionDate', dir: 'desc' }],
          columns: [
            { title: 'Transaction ID', field: 'transactionId', minWidth: 150, width: 150 },
            { title: 'Booking ID', field: 'bookingId', minWidth: 140, width: 140 },
            { title: 'Customer Name', field: 'customerName', minWidth: 180, width: 180 },
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
        const dateToFilter = document.getElementById('date-to-filter');
        const resetFilters = document.getElementById('reset-transaction-filters');

        const applyFilters = () => {
          const query = globalFilter.value.trim().toLowerCase();
          const paymentStatus = paymentStatusFilter.value;
          const bookingStatus = bookingStatusFilter.value;
          const paymentMethod = paymentMethodFilter.value;
          const dateFrom = dateFromFilter.value ? Date.parse(dateFromFilter.value) : null;
          const dateTo = dateToFilter.value ? Date.parse(`${dateToFilter.value}T23:59:59`) : null;

          table.setFilter((row) => {
            const rowText = [
              row.transactionId, row.bookingId, row.customerName, row.venue,
              row.eventDate, row.transactionDate, row.amount,
              row.paymentMethod, row.paymentStatus, row.bookingStatus,
            ].join(' ').toLowerCase();
            const transactionDate = parseDate(row.transactionDate);

            return (!query || rowText.includes(query))
              && (!paymentStatus || row.paymentStatus === paymentStatus)
              && (!bookingStatus || row.bookingStatus === bookingStatus)
              && (!paymentMethod || row.paymentMethod === paymentMethod)
              && (!dateFrom || transactionDate >= dateFrom)
              && (!dateTo || transactionDate <= dateTo);
          });
        };

        [globalFilter, paymentStatusFilter, bookingStatusFilter, paymentMethodFilter, dateFromFilter, dateToFilter]
          .forEach((control) => control.addEventListener('input', applyFilters));

        resetFilters.addEventListener('click', () => {
          globalFilter.value = '';
          paymentStatusFilter.value = '';
          bookingStatusFilter.value = '';
          paymentMethodFilter.value = '';
          dateFromFilter.value = '';
          dateToFilter.value = '';
          table.clearFilter();
        });

        document.getElementById('export-csv').addEventListener('click', () => table.download('csv', 'transaction-history.csv'));
        document.getElementById('export-json').addEventListener('click', () => table.download('json', 'transaction-history.json'));
        document.getElementById('print-table').addEventListener('click', () => table.print(false, true));
      });
    </script>
  </body>
</html>
