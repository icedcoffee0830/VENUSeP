@extends('layouts.customer')
{{-- BOOKING HISTORY (was customer/booking-history.php). Bookings come from legacy customer-bookings.php, scoped to the session customer. --}}
@section('title', 'Booking History')
@section('active', 'Booking History')
@section('body-class', 'booking-history-page')
@push('head')
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
@endpush
@push('styles')
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
      /* a booking that lapsed: why, in a sentence (bookings.php expiryNote) */
      .badge-expired, .badge-released { background: #eef0f2; color: #55606b; }
      .booking-status-note { display: block; margin-top: 4px; max-width: 220px; font-size: 11px; line-height: 1.4; color: var(--muted); }
      /* row actions */
      .booking-actions { display: inline-flex; gap: 6px; }
      .booking-action { display: inline-flex; align-items: center; gap: 5px; height: 30px; padding: 0 11px; border-radius: 8px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font-family: inherit; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
      .booking-action:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .booking-action[disabled] { opacity: .5; cursor: not-allowed; }
      .booking-action-receipt { border-color: rgba(161,22,38,.35); color: #a11626; }
      .booking-action-receipt:hover { background: #fcf6f4; border-color: #a11626; }
      /* the one action that moves a booking forward, so it is the filled one */
      .booking-action-pay { background: #a11626; border-color: #a11626; color: #fff; }
      .booking-action-pay:hover { background: #7d0f1e; border-color: #7d0f1e; }

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
      /* ---- phone: the 9-column table becomes one card per booking. Each cell
         shows its column name (data-label) in front, the header row is hidden,
         and the id + status sit on the first line. The sorting/filtering
         script is untouched — it still works on the same rows. ---- */
      @media (max-width: 720px) {
        .booking-history-table { min-width: 0; }
        .booking-history-table thead { display: none; }
        .booking-history-table tbody tr { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; padding: 14px 16px; border-bottom: 1px solid #ece8e1; }
        .booking-history-table tbody tr:hover { background: transparent; }
        .booking-history-table td { display: block; padding: 0; border: 0; white-space: normal; font-size: 13px; }
        .booking-history-table td::before { content: attr(data-label); display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin-bottom: 2px; }
        .booking-history-table td.booking-id { grid-column: 1; }
        .booking-history-table td.booking-id::before { display: none; }
        .booking-history-table td.booking-id strong { font-size: 15px; }
        .booking-history-table td[data-label="Status"] { grid-column: 2; grid-row: 1; justify-self: end; }
        .booking-history-table td[data-label="Status"]::before { display: none; }
        .booking-history-table td[data-label="Event"] { grid-column: 1 / -1; }
        .booking-history-table td.booking-actions-cell { grid-column: 1 / -1; margin-top: 4px; }
        .booking-history-table td.booking-actions-cell::before { display: none; }
        .booking-empty-state td { grid-column: 1 / -1; }
        .booking-empty-state td::before { display: none; }
        .booking-actions { flex-wrap: wrap; }
        .booking-history-filters { grid-template-columns: 1fr 1fr; }
        .booking-status-tabs { overflow-x: auto; flex-wrap: nowrap; -webkit-overflow-scrolling: touch; padding-bottom: 4px; }
      }
      /* type floor (readability): nothing on the page below 12px */
      .booking-badge, .sortable-heading, .booking-filter-field label, .booking-pay-note { font-size: 12px; }
      /* ---- white page (desktop + phone): the cards sit on white, no crimson wash ---- */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      .booking-history-header h1 { color: #1f1e1e; }
      .booking-history-header p { color: #6e6a64; }
      .booking-filter-field label { color: #6e6a64; }
      .booking-history-panel { box-shadow: 0 1px 2px rgba(0,0,0,.04); border: 1px solid #e5e5e5; border-radius: 14px; }
      /* phone: cards edge to edge — the details get the width */
      @media (max-width: 767.98px) {
        .container-fluid { padding-inline: 12px; }
        .app-content-header { padding-top: 16px; }
        .booking-history-table tbody tr { padding: 14px 12px; }
      }
    </style>
@endpush
@section('content')
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
                    @foreach ($bookingHistoryVenues as $venue)
                      <option value="{{ $venue }}">{{ $venue }}</option>
                    @endforeach
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
                      @foreach ($bookingHistory as $booking)
                        <tr
                          data-booking-row
                          data-booking-id="{{ $booking['bookingId'] }}"
                          data-venue="{{ $booking['venue'] }}"
                          data-event-name="{{ $booking['eventName'] }}"
                          data-event-date="{{ $booking['eventDateIso'] }}"
                          data-booking-date="{{ $booking['bookingDateIso'] }}"
                          data-amount="{{ $booking['amountValue'] }}"
                          data-payment-status="{{ $booking['paymentStatus'] }}"
                          data-status="{{ $booking['bookingStatus'] }}"
                        >
                          <td class="booking-id" data-label="Booking"><strong>{{ $booking['bookingId'] }}</strong></td>
                          <td data-label="Venue">{{ $booking['venue'] }}</td>
                          <td data-label="Event">{{ $booking['eventName'] }}</td>
                          <td data-label="Event date">{{ $booking['eventDate'] }}</td>
                          <td data-label="Booked on">{{ $booking['bookingDate'] }}</td>
                          <td class="booking-amount" data-label="Amount">{{ $booking['amount'] }}</td>
                          <td data-label="Payment">
                            <span class="booking-badge {{ bh_badge($booking['paymentStatus']) }}">{{ $booking['paymentStatus'] }}</span>
                            {{-- post-pay bookings say WHEN — the date comes from the shared rule (legacy refund-policy.php) --}}
                            @if ($booking['paymentStatus'] === 'Payment pending')
                              <span class="booking-pay-note">opens {{ $booking['payment']['opensLabel'] }}</span>
                            @elseif ($booking['paymentStatus'] === 'Payment due')
                              <span class="booking-pay-note">pay by {{ $booking['payment']['payByLabel'] }}</span>
                            @elseif ($booking['paymentStatus'] === 'Overdue')
                              <span class="booking-pay-note is-overdue">was due {{ $booking['payment']['payByLabel'] }} &middot; can still be paid</span>
                            @endif
                          </td>
                          <td data-label="Status"><span class="booking-badge {{ bh_badge($booking['bookingStatus']) }}">{{ $booking['bookingStatus'] }}</span>
                            @if (!empty($booking['expiryNote']))<span class="booking-status-note">{{ $booking['expiryNote'] }}</span>@endif</td>
                          <td data-label="Actions" class="booking-actions-cell">
                            <div class="booking-actions">
                              {{-- Payment is open (or a receipt is with staff): the way back to
                                   the payment step, customer.pay. --}}
                              @if (in_array($booking['paymentCode'], ['await_gcash', 'await_cash', 'overdue'], true))
                                <a class="booking-action booking-action-pay" href="{{ route('customer.pay', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-wallet2" aria-hidden="true"></i>Pay now
                                </a>
                              @elseif ($booking['paymentCode'] === 'under_review')
                                <a class="booking-action" href="{{ route('customer.pay', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-hourglass-split" aria-hidden="true"></i>Payment
                                </a>
                              @endif
                              <!-- the booking and its payment, in a modal over this page — same as
                                   Transaction History's View (includes/booking-details-modal.php) -->
                              <button type="button" class="booking-action" onclick="openBookingDetails('{{ rawurlencode($booking['bookingId']) }}')"><i class="bi bi-eye" aria-hidden="true"></i>View</button>
                              @if (isset($bhReceipts[(int) ($booking['id'] ?? 0)]))
                                <a class="booking-action booking-action-receipt" href="{{ route('customer.receipt', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Receipt
                                </a>
                              @endif
                              {{-- also on a row whose refund is open or returned: the script
                                   below turns it into "Withdraw request" / "Fix and resubmit" --}}
                              @if ($booking['refundable'] || in_array($booking['refundStatus'], ['requested', 'under_review', 'returned_for_correction'], true))
                                <a class="booking-action booking-action-refund" href="{{ route('customer.refund-request', ['booking' => $booking['bookingId']]) }}">
                                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Request Refund
                                </a>
                              @endif
                            </div>
                          </td>
                        </tr>
                      @endforeach
                      <tr id="booking-history-empty" class="booking-empty-state" hidden>
                        <td colspan="9">{{ $emptyBookingMessage }}</td>
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
@endsection
@push('scripts')
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <!-- [6] PAGE SCRIPT — page-local table logic (search/sort/paginate) -->
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

        updateSortIndicators();
        render();
      });
    </script>
    <!-- [6b] REFUND ACTIONS — which button a row with a refund on it should
         offer. The payment BADGE already reads correctly without any script:
         it comes from payment_statuses.customer_label like every other row.

         NOTE WHAT FILING DOES *NOT* DO: it does not cancel the booking. Agreed
         2026-09-09 — the booking stays the customer's for the whole process and
         the date is released only when the refund is actually COMPLETED. So the
         reservation badge is left alone for every state EXCEPT 'refunded', which
         is the one moment the booking closes. -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const CSRF = @json(csrf_token());
        const REFUNDS = {!! json_encode($bhRefunds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
        const refs = Object.keys(REFUNDS);
        if (!refs.length) return;

        const withdraw = async function (ref) {
          const ok = await venusepConfirm(
            'Your booking is not affected either way — it stays yours. You can request a refund again later if you change your mind.',
            { title: 'Withdraw your refund request for ' + ref + '?', okLabel: 'Withdraw', danger: true });
          if (!ok) return;
          fetch(@json(route('customer.refund-submit')), {
            method: 'POST', credentials: 'same-origin',
            body: new URLSearchParams({ _token: CSRF, booking: ref, action: 'withdraw' })
          })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
            .then(function (out) {
              if (!out.ok) { venusepAlert(out.message || 'The request was not withdrawn.'); return; }
              window.location.reload();
            })
            .catch(function () { venusepAlert('Could not reach the server, so nothing was changed.'); });
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
          const rec = REFUNDS[ref];
          const row = document.querySelector('[data-booking-id="' + String(ref).replace(/[^A-Za-z0-9-]/g, '') + '"]');
          if (!row || !rec) return;
          const link = row.querySelector('.booking-action-refund');

          /* Open on staff, not yet decided -> the customer can pull it back (the
             same states refund-submit accepts a withdrawal from). */
          if (rec.status === 'requested' || rec.status === 'under_review') {
            action(row, 'booking-action-withdraw', 'bi-x-circle', 'Withdraw request', function () { withdraw(ref); });
          /* Returned for correction is PAPERWORK, not a denial — they fix it. */
          } else if (rec.status === 'returned_for_correction') {
            action(row, 'booking-action-fix', 'bi-arrow-counterclockwise', 'Fix and resubmit', null,
              @json(route('customer.refund-request')) + '?booking=' + encodeURIComponent(ref));
          /* Approved, denied or paid out: nothing left to offer, and a "Request
             Refund" button on a refunded booking would be a trap. A WITHDRAWN
             request keeps the button the server decided on: they may file again. */
          } else if (link && rec.status !== 'withdrawn') {
            link.remove();
          }
        });
        const search = document.getElementById('booking-history-search');
        if (search) search.dispatchEvent(new Event('input'));
      });
    </script>
    @php include base_path('legacy/includes/booking-details-modal.php'); /* "View" */ @endphp
@endpush
