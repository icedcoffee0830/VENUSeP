<?php
/* ==================================================================
   BOOKING HISTORY — VENUSeP merged system (customer portal)
   ==================================================================
   Ported from the teammate's booking-history.php onto OUR shared shell
   ($portal='customer'). Page-local vanilla JS (search / sort / paginate
   / CSV-JSON export / print) — no third-party table library.

   The bookings themselves are NOT defined here any more: they come from
   includes/customer-bookings.php, the ONE source, shared with
   customer/refund-request.php. Moving them out is what stops the two
   pages drifting apart (see the note in that file).

   [SIM] View Details / Rebook are still stubs — their target pages don't
   exist yet. "Request Refund" is real and opens refund-request.php.
   ================================================================== */
require_once __DIR__ . '/../includes/customer-bookings.php';

$bookingHistory = $customerBookings;
$bookingHistoryVenues = array_values(array_unique(array_column($bookingHistory, 'venue')));
sort($bookingHistoryVenues, SORT_NATURAL | SORT_FLAG_CASE);
$emptyBookingMessage = 'No booking history found.';
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
       [4] CONTENT (status tabs, filters, table, pagination) ·
       [6] SCRIPT (search/sort/paginate/export/print)
  [SIM] = demo-only, replace at database time.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Booking History</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS — shared layout -->
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

    <!-- [1] PAGE CSS — booking history, self-contained in the team palette -->
    <style>
      .booking-history-container { padding: 22px 0 0; }
      .booking-history-header h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin: 0; }
      .booking-history-header p { color: var(--muted); font-size: 13px; margin: 4px 0 16px; }

      /* status tabs */
      .booking-status-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
      .booking-status-tab { height: 34px; padding: 0 14px; border: 1px solid #d7d7d7; border-radius: 999px; background: #fff; color: #55606b; font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: .18s; }
      .booking-status-tab:hover { background: #f4f2ee; }
      .booking-status-tab.active { background: var(--black); border-color: var(--black); color: #fff; }

      /* filters */
      .booking-history-filters { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)) auto; gap: 12px; align-items: end; margin-bottom: 16px; }
      .booking-filter-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
      .booking-filter-field label { font-size: 11px; font-weight: 600; letter-spacing: .03em; text-transform: uppercase; color: var(--muted); }
      .booking-history-filters .form-control, .booking-history-filters .form-select { height: 36px; padding: 0 11px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; font: inherit; font-size: 13px; color: var(--black); }
      .booking-history-filters .form-control:focus, .booking-history-filters .form-select:focus { outline: none; border-color: var(--black); }

      /* toolbar buttons */
      .booking-toolbar-button { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 13px; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; color: var(--black); font: inherit; font-size: 12.5px; font-weight: 600; cursor: pointer; transition: .18s; }
      .booking-toolbar-button:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .booking-toolbar-button i { color: var(--muted); }
      .booking-reset-button { align-self: end; }

      /* panel + table */
      .booking-history-panel { background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 1px 2px rgba(15,23,42,.04); overflow: hidden; }
      .booking-history-panel-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 15px 18px; border-bottom: 1px solid #f0efec; }
      .booking-history-panel-header h2 { font-size: 14.5px; font-weight: 650; margin: 0; }
      .booking-history-toolbar { display: flex; gap: 8px; flex-wrap: wrap; }
      .booking-table-scroll { overflow-x: auto; }
      .booking-history-table { width: 100%; border-collapse: collapse; min-width: 900px; }
      .booking-history-table th, .booking-history-table td { padding: 11px 14px; text-align: left; border-bottom: 1px solid #f2efe9; font-size: 13px; white-space: nowrap; }
      .booking-history-table th { background: #faf9f7; }
      .sortable-heading { border: 0; background: transparent; font: inherit; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b6258; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 0; }
      .sortable-heading::after { content: "\F282"; font-family: "bootstrap-icons"; font-size: 10px; opacity: .3; }
      .sortable-heading[data-direction="asc"]::after { content: "\F235"; opacity: .8; }
      .sortable-heading[data-direction="desc"]::after { content: "\F229"; opacity: .8; }
      .booking-history-table tbody tr:hover { background: #faf9f7; }
      .booking-amount { font-variant-numeric: tabular-nums; }
      .booking-empty-state td { text-align: center; color: var(--muted); padding: 28px; }

      /* badges — team soft-tint palette */
      .booking-badge { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 600; line-height: 1.5; }
      .badge-paid, .badge-approved { background: #eaf6ef; color: #1c7a4f; }
      .badge-completed, .badge-refunded { background: #e9eff8; color: #2b4a7e; }
      .badge-pending { background: #fbf1dd; color: #8a5a00; }
      .badge-rejected, .badge-failed { background: #fcecec; color: #b23a3a; }
      .badge-cancelled, .badge-unpaid { background: #eef0f2; color: #55606b; }
      .badge-refund-requested { background: #fbf1dd; color: #8a5a00; }
      .badge-refund-action-needed { background: #fdf3e6; color: #8a5a12; }
      .badge-refund-denied { background: #fcecec; color: #b23a3a; }
      /* post-pay states (DB-DECISIONS #18): waiting for the event · window open · window missed */
      .badge-payment-pending { background: #eef0f2; color: #55606b; }
      .badge-payment-due { background: #fbf1dd; color: #8a5a00; }
      .badge-overdue { background: #fcecec; color: #b23a3a; }
      .booking-pay-note { display: block; margin-top: 3px; font-size: 11px; color: var(--muted); white-space: nowrap; }
      .booking-pay-note.is-overdue { color: #b23a3a; }

      /* row actions */
      .booking-actions { display: inline-flex; gap: 6px; }
      .booking-action { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font-family: inherit; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
      .booking-action:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .booking-action[disabled] { opacity: .5; cursor: not-allowed; }

      /* footer / pagination */
      .booking-table-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; padding: 14px 18px; }
      .booking-entries-control { font-size: 12.5px; color: var(--muted); display: inline-flex; align-items: center; gap: 8px; }
      .booking-entries-select { height: 32px; border: 1px solid #d7d7d7; border-radius: 8px; padding: 0 8px; font: inherit; font-size: 12.5px; }
      .booking-table-info { font-size: 12.5px; color: var(--muted); margin: 0; }
      .booking-pagination { display: inline-flex; gap: 6px; flex-wrap: wrap; }
      .booking-pagination button { min-width: 32px; height: 32px; border: 1px solid #d7d7d7; border-radius: 8px; background: #fff; color: var(--black); font: inherit; font-size: 12.5px; cursor: pointer; }
      .booking-pagination button:hover:not(:disabled) { background: #f4f2ee; }
      .booking-pagination button.active { background: var(--black); border-color: var(--black); color: #fff; }
      .booking-pagination button:disabled { opacity: .4; cursor: not-allowed; }

      @media (max-width: 1100px) { .booking-history-filters { grid-template-columns: repeat(2, minmax(0, 1fr)); } .booking-reset-button { grid-column: 1 / -1; } }
    
      /* on the crimson page background (painted by includes/header.php): light text, cards that float */
      .booking-history-header h1 { color: #fff; }
      .booking-history-header p { color: #e9d0cd; }
      .booking-filter-field label { color: #f2d0cb; }
      .booking-history-panel { box-shadow: 0 18px 44px rgba(10,4,5,.28), 0 2px 6px rgba(10,4,5,.18); border-color: rgba(255,255,255,.18); }
    
      /* the active tab and the current page in the pagination: crimson, not black */
      .booking-status-tab.active { background: #a11626; border-color: #a11626; color: #ffffff; }
      .booking-pagination button.active { background: #a11626; border-color: #a11626; color: #ffffff; }
    </style>
  </head>
  <body class="booking-history-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Booking History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="booking-history-container">
              <header class="booking-history-header">
                <h1>Booking History</h1>
                <p>Review your previous venue reservations and their final status.</p>
              </header>

              <nav class="booking-status-tabs" aria-label="Filter bookings by status" role="tablist">
                <button class="booking-status-tab active" type="button" role="tab" aria-selected="true" data-booking-status="">All</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Approved">Approved</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Completed">Completed</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Rejected">Rejected</button>
                <button class="booking-status-tab" type="button" role="tab" aria-selected="false" data-booking-status="Cancelled">Cancelled</button>
              </nav>

              <section class="booking-history-filters" aria-label="Booking history filters">
                <div class="booking-filter-field">
                  <label for="booking-history-search">Search</label>
                  <input id="booking-history-search" class="form-control" type="search" placeholder="Booking ID, venue, or event...">
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-venue">Venue</label>
                  <select id="booking-history-venue" class="form-select">
                    <option value="">All Venues</option>
                    <?php foreach ($bookingHistoryVenues as $venue): ?>
                      <option value="<?php echo bh_e($venue); ?>"><?php echo bh_e($venue); ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-date-from">Date From</label>
                  <input id="booking-history-date-from" class="form-control" type="date">
                </div>
                <div class="booking-filter-field">
                  <label for="booking-history-date-to">Date To</label>
                  <input id="booking-history-date-to" class="form-control" type="date">
                </div>
                <button id="booking-history-reset" class="booking-toolbar-button booking-reset-button" type="button">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset
                </button>
              </section>

              <section class="booking-history-panel" aria-labelledby="previousBookingsTitle">
                <div class="booking-history-panel-header">
                  <h2 id="previousBookingsTitle">Previous Bookings</h2>
                  <div class="booking-history-toolbar" aria-label="Export booking history">
                    <button id="booking-export-csv" class="booking-toolbar-button" type="button"><i class="bi bi-filetype-csv" aria-hidden="true"></i>Export CSV</button>
                    <button id="booking-export-json" class="booking-toolbar-button" type="button"><i class="bi bi-filetype-json" aria-hidden="true"></i>Export JSON</button>
                    <button id="booking-print" class="booking-toolbar-button" type="button"><i class="bi bi-printer" aria-hidden="true"></i>Print</button>
                  </div>
                </div>
                <div class="booking-table-scroll">
                  <table id="bookingHistoryTable" class="booking-history-table">
                    <thead>
                      <tr>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="booking-id">Booking ID</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="venue">Venue</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="event-name">Event Name</button></th>
                        <th aria-sort="descending"><button class="sortable-heading" type="button" data-sort="event-date" data-direction="desc">Event Date</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="booking-date">Booking Date</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="amount">Amount</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="payment-status">Payment Status</button></th>
                        <th aria-sort="none"><button class="sortable-heading" type="button" data-sort="status">Booking Status</button></th>
                        <th>Actions</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($bookingHistory as $booking): ?>
                        <tr
                          data-booking-row
                          data-booking-id="<?php echo bh_e($booking['bookingId']); ?>"
                          data-venue="<?php echo bh_e($booking['venue']); ?>"
                          data-event-name="<?php echo bh_e($booking['eventName']); ?>"
                          data-event-date="<?php echo bh_e($booking['eventDateIso']); ?>"
                          data-booking-date="<?php echo bh_e($booking['bookingDateIso']); ?>"
                          data-amount="<?php echo bh_e($booking['amountValue']); ?>"
                          data-payment-status="<?php echo bh_e($booking['paymentStatus']); ?>"
                          data-status="<?php echo bh_e($booking['bookingStatus']); ?>"
                        >
                          <td class="booking-id"><strong><?php echo bh_e($booking['bookingId']); ?></strong></td>
                          <td><?php echo bh_e($booking['venue']); ?></td>
                          <td><?php echo bh_e($booking['eventName']); ?></td>
                          <td><?php echo bh_e($booking['eventDate']); ?></td>
                          <td><?php echo bh_e($booking['bookingDate']); ?></td>
                          <td class="booking-amount"><?php echo bh_e($booking['amount']); ?></td>
                          <td>
                            <span class="booking-badge <?php echo bh_badge($booking['paymentStatus']); ?>"><?php echo bh_e($booking['paymentStatus']); ?></span>
                            <?php /* post-pay bookings say WHEN — the date comes from the shared rule (includes/refund-policy.php) */
                            if ($booking['paymentStatus'] === 'Payment pending'): ?>
                              <span class="booking-pay-note">opens <?php echo bh_e($booking['payment']['opensLabel']); ?></span>
                            <?php elseif ($booking['paymentStatus'] === 'Payment due'): ?>
                              <span class="booking-pay-note">pay by <?php echo bh_e($booking['payment']['payByLabel']); ?></span>
                            <?php elseif ($booking['paymentStatus'] === 'Overdue'): ?>
                              <span class="booking-pay-note is-overdue">was due <?php echo bh_e($booking['payment']['payByLabel']); ?> &middot; can still be paid</span>
                            <?php endif; ?>
                          </td>
                          <td><span class="booking-badge <?php echo bh_badge($booking['bookingStatus']); ?>"><?php echo bh_e($booking['bookingStatus']); ?></span></td>
                          <td>
                            <div class="booking-actions">
                              <!-- [SIM] booking-details.php / rebook.php don't exist yet -->
                              <a class="booking-action" href="#"><i class="bi bi-eye" aria-hidden="true"></i>View</a>
                              <a class="booking-action" href="#"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Rebook</a>
                              <?php if ($booking['refundable']): ?>
                                <a class="booking-action booking-action-refund" href="refund-request.php?booking=<?php echo urlencode($booking['bookingId']); ?>">
                                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Request Refund
                                </a>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                      <tr id="booking-history-empty" class="booking-empty-state" hidden>
                        <td colspan="9"><?php echo bh_e($emptyBookingMessage); ?></td>
                      </tr>
                    </tbody>
                  </table>
                </div>
                <div class="booking-table-footer">
                  <label class="booking-entries-control" for="booking-history-entries">
                    Show
                    <select id="booking-history-entries" class="booking-entries-select">
                      <option value="10" selected>10</option>
                      <option value="25">25</option>
                      <option value="50">50</option>
                    </select>
                    entries
                  </label>
                  <p id="booking-history-info" class="booking-table-info" aria-live="polite"></p>
                  <nav id="booking-history-pagination" class="booking-pagination" aria-label="Booking history pages"></nav>
                </div>
              </section>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- [6] PAGE SCRIPT — page-local table logic (search/sort/paginate/export/print) -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('bookingHistoryTable');
        if (!table) return;
        const tbody = table.querySelector('tbody');
        const rows = Array.from(tbody.querySelectorAll('tr[data-booking-row]'));
        const searchInput = document.getElementById('booking-history-search');
        const venueFilter = document.getElementById('booking-history-venue');
        const dateFromFilter = document.getElementById('booking-history-date-from');
        const dateToFilter = document.getElementById('booking-history-date-to');
        const entriesSelect = document.getElementById('booking-history-entries');
        const resetButton = document.getElementById('booking-history-reset');
        const statusTabs = Array.from(document.querySelectorAll('[data-booking-status]'));
        const pagination = document.getElementById('booking-history-pagination');
        const info = document.getElementById('booking-history-info');
        const emptyRow = document.getElementById('booking-history-empty');
        let activeStatus = '', currentPage = 1, pageSize = Number(entriesSelect.value), sortKey = 'event-date', sortDirection = 'desc', filteredRows = [];

        const comparableValue = function (row, key) {
          const datasetKey = { 'booking-id': 'bookingId', 'event-name': 'eventName', 'event-date': 'eventDate', 'booking-date': 'bookingDate', 'payment-status': 'paymentStatus' }[key] || key;
          if (key === 'amount') return Number(row.dataset.amount || 0);
          if (key === 'event-date' || key === 'booking-date') return row.dataset[datasetKey] || '';
          return (row.dataset[datasetKey] || '').toLowerCase();
        };
        const sortRows = function (list) {
          return list.sort(function (l, r) {
            const a = comparableValue(l, sortKey), b = comparableValue(r, sortKey);
            if (a < b) return sortDirection === 'asc' ? -1 : 1;
            if (a > b) return sortDirection === 'asc' ? 1 : -1;
            return 0;
          });
        };
        const renderPagination = function (pageCount) {
          pagination.replaceChildren();
          const createButton = function (label, page, disabled, active, ariaLabel) {
            const button = document.createElement('button');
            button.type = 'button'; button.textContent = label; button.disabled = disabled;
            if (active) { button.classList.add('active'); button.setAttribute('aria-current', 'page'); }
            if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
            button.addEventListener('click', function () { currentPage = page; render(); });
            pagination.appendChild(button);
          };
          createButton('‹', Math.max(1, currentPage - 1), currentPage === 1, false, 'Previous page');
          for (let page = 1; page <= pageCount; page += 1) createButton(String(page), page, false, page === currentPage, 'Page ' + page);
          createButton('›', Math.min(pageCount, currentPage + 1), currentPage === pageCount, false, 'Next page');
        };
        const render = function () {
          const query = searchInput.value.trim().toLowerCase();
          const venue = venueFilter.value, dateFrom = dateFromFilter.value, dateTo = dateToFilter.value;
          filteredRows = sortRows(rows.filter(function (row) {
            const searchable = [row.dataset.bookingId, row.dataset.venue, row.dataset.eventName].join(' ').toLowerCase();
            return (!activeStatus || row.dataset.status === activeStatus)
              && (!query || searchable.includes(query))
              && (!venue || row.dataset.venue === venue)
              && (!dateFrom || row.dataset.eventDate >= dateFrom)
              && (!dateTo || row.dataset.eventDate <= dateTo);
          }));
          rows.forEach(function (row) { row.hidden = true; });
          const pageCount = Math.max(1, Math.ceil(filteredRows.length / pageSize));
          currentPage = Math.min(currentPage, pageCount);
          const start = (currentPage - 1) * pageSize;
          filteredRows.slice(start, start + pageSize).forEach(function (row) { row.hidden = false; tbody.insertBefore(row, emptyRow); });
          emptyRow.hidden = filteredRows.length !== 0;
          const firstShown = filteredRows.length ? start + 1 : 0;
          const lastShown = Math.min(start + pageSize, filteredRows.length);
          info.textContent = 'Showing ' + firstShown + ' to ' + lastShown + ' of ' + filteredRows.length + ' entries';
          renderPagination(pageCount);
        };
        const updateSortIndicators = function () {
          table.querySelectorAll('.sortable-heading').forEach(function (button) {
            button.removeAttribute('data-direction');
            button.closest('th').setAttribute('aria-sort', 'none');
            if (button.dataset.sort === sortKey) {
              button.dataset.direction = sortDirection;
              button.closest('th').setAttribute('aria-sort', sortDirection === 'asc' ? 'ascending' : 'descending');
            }
          });
        };
        table.querySelectorAll('.sortable-heading').forEach(function (button) {
          button.addEventListener('click', function () {
            const nextKey = button.dataset.sort;
            sortDirection = sortKey === nextKey && sortDirection === 'asc' ? 'desc' : 'asc';
            sortKey = nextKey; currentPage = 1; updateSortIndicators(); render();
          });
        });
        statusTabs.forEach(function (tab) {
          tab.addEventListener('click', function () {
            activeStatus = tab.dataset.bookingStatus;
            statusTabs.forEach(function (item) {
              const selected = item === tab;
              item.classList.toggle('active', selected);
              item.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            currentPage = 1; render();
          });
        });
        [searchInput, venueFilter, dateFromFilter, dateToFilter].forEach(function (control) {
          control.addEventListener('input', function () { currentPage = 1; render(); });
        });
        entriesSelect.addEventListener('change', function () { pageSize = Number(entriesSelect.value); currentPage = 1; render(); });
        resetButton.addEventListener('click', function () {
          searchInput.value = ''; venueFilter.value = ''; dateFromFilter.value = ''; dateToFilter.value = '';
          entriesSelect.value = '10'; pageSize = 10; activeStatus = ''; currentPage = 1;
          statusTabs.forEach(function (tab) {
            const selected = tab.dataset.bookingStatus === '';
            tab.classList.toggle('active', selected);
            tab.setAttribute('aria-selected', selected ? 'true' : 'false');
          });
          render();
        });

        const exportFields = ['Booking ID', 'Venue', 'Event Name', 'Event Date', 'Booking Date', 'Amount', 'Payment Status', 'Booking Status'];
        const rowValues = function (row) { return Array.from(row.querySelectorAll('td')).slice(0, 8).map(function (cell) { return cell.textContent.trim(); }); };
        const download = function (content, type, filename) {
          const url = URL.createObjectURL(new Blob([content], { type: type }));
          const link = document.createElement('a');
          link.href = url; link.download = filename;
          document.body.appendChild(link); link.click(); link.remove();
          URL.revokeObjectURL(url);
        };
        const csvCell = function (value) { return '"' + String(value).replace(/"/g, '""') + '"'; };
        document.getElementById('booking-export-csv').addEventListener('click', function () {
          const csv = [exportFields].concat(filteredRows.map(rowValues)).map(function (values) { return values.map(csvCell).join(','); }).join('\r\n');
          download('﻿' + csv, 'text/csv;charset=utf-8', 'booking-history.csv');
        });
        document.getElementById('booking-export-json').addEventListener('click', function () {
          const data = filteredRows.map(function (row) {
            const values = rowValues(row);
            return exportFields.reduce(function (record, field, index) { record[field] = values[index]; return record; }, {});
          });
          download(JSON.stringify(data, null, 2), 'application/json;charset=utf-8', 'booking-history.json');
        });
        document.getElementById('booking-print').addEventListener('click', function () {
          const printWindow = window.open('', '_blank');
          if (!printWindow) return;
          printWindow.opener = null;
          const rowsHtml = filteredRows.map(function (row) {
            return '<tr>' + rowValues(row).map(function (value) { return '<td>' + value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</td>'; }).join('') + '</tr>';
          }).join('');
          const printStyle = 'body{font-family:Inter,Arial,sans-serif;padding:24px;color:#1f1e1e}h1{font-size:18px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #ddd;padding:6px 8px;font-size:12px;text-align:left}';
          printWindow.document.write('<!doctype html><html><head><title>Booking History</title><style>' + printStyle + '</style></head><body><h1>Previous Bookings</h1><table><thead><tr>' + exportFields.map(function (field) { return '<th>' + field + '</th>'; }).join('') + '</tr></thead><tbody>' + rowsHtml + '</tbody></table></body></html>');
          printWindow.document.close();
          printWindow.focus();
          printWindow.print();
        });

        updateSortIndicators();
        render();
      });
    </script>
    <!-- Shared refund state — the ONE source, also read by both admin pages. -->
    <?php include __DIR__ . '/../includes/refund-store.php'; ?>

    <!-- [6b] REFUND STATE [SIM] — paints whatever the shared store says about
         each booking. There is no database, so a request the customer filed and
         a decision staff made both travel through includes/refund-store.php.

         NOTE WHAT THIS DOES *NOT* DO: filing does not cancel the booking. Agreed
         2026-09-09 — the booking stays the customer's for the whole process and
         the date is released only when the refund is actually COMPLETED. So the
         reservation badge is left alone for every state EXCEPT 'refunded', which
         is the one moment the booking closes. -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (!window.RefundStore) return;
        const all = RefundStore.all();
        const refs = Object.keys(all);
        if (!refs.length) return;

        const badge = function (cls, text) { return '<span class="booking-badge ' + cls + '">' + text + '</span>'; };
        const withdraw = function (ref) {
          const ok = window.confirm('Withdraw your refund request for ' + ref + '?\n\nYour booking is not affected either way — it stays yours. You can request a refund again later if you change your mind.');
          if (!ok) return;
          RefundStore.withdraw(ref);
          window.location.reload();
        };
        const action = function (row, cls, icon, text, onClick, href) {
          const link = row.querySelector('.booking-action-refund');
          if (!link) return;
          let el;
          if (href) { el = document.createElement('a'); el.href = href; }
          else { el = document.createElement('button'); el.type = 'button'; el.addEventListener('click', onClick); }
          el.className = 'booking-action ' + cls;
          el.innerHTML = '<i class="bi ' + icon + '" aria-hidden="true"></i>' + text;
          link.replaceWith(el);
        };

        refs.forEach(function (ref) {
          const rec = all[ref];
          const row = document.querySelector('[data-booking-id="' + String(ref).replace(/[^A-Za-z0-9-]/g, '') + '"]');
          if (!row || !rec) return;
          const cells = row.querySelectorAll('td');
          const link = row.querySelector('.booking-action-refund');

          if (rec.status === 'open') {
            row.dataset.paymentStatus = 'Refund requested';
            cells[6].innerHTML = badge('badge-refund-requested', rec.orPending ? 'Refund · awaiting your OR' : 'Refund requested');
            action(row, 'booking-action-withdraw', 'bi-x-circle', 'Withdraw request', function () { withdraw(ref); });
          } else if (rec.status === 'fix') {
            row.dataset.paymentStatus = 'Refund action needed';
            cells[6].innerHTML = badge('badge-refund-action-needed', 'Refund · action needed');
            action(row, 'booking-action-fix', 'bi-arrow-counterclockwise', 'Fix and resubmit', null,
              'refund-request.php?booking=' + encodeURIComponent(ref));
          } else if (rec.status === 'denied') {
            row.dataset.paymentStatus = 'Refund denied';
            cells[6].innerHTML = badge('badge-refund-denied', 'Refund denied');
            if (link) link.remove();
          } else if (rec.status === 'refunded') {
            /* the ONLY state that closes the booking and frees the date */
            const p = rec.proof || {};
            row.dataset.paymentStatus = 'Refunded';
            row.dataset.status = 'Cancelled';
            cells[6].innerHTML = badge('badge-refunded', 'Refunded')
              + (p.reference ? '<div style="font-size:10.5px;color:#8a857d;margin-top:3px">Ref ' + String(p.reference).replace(/[<>&"]/g, '') + '</div>' : "");
            cells[7].innerHTML = badge('badge-cancelled', 'Cancelled');
            /* proof lives on the refund page — the row is too small for a receipt */
            action(row, 'booking-action-proof', 'bi-receipt', 'View refund proof', null,
              'refund-request.php?booking=' + encodeURIComponent(ref));
          }
          if (rec.staffNote && (rec.status === 'fix' || rec.status === 'denied')) {
            row.title = 'Staff note: ' + rec.staffNote;
          }
        });
        const search = document.getElementById('booking-history-search');
        if (search) search.dispatchEvent(new Event('input'));
      });
    </script>

  </body>
</html>
