@extends('layouts.customer')
{{-- TRANSACTION HISTORY (was customer/transaction-history.php). Rows come from transaction_rows() in legacy bookings.php, scoped to the session customer. --}}
@section('title', 'Transaction History')
@section('active', 'Transaction History')
@section('body-class', 'customer-transaction-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/css/tabulator_bootstrap5.min.css" crossorigin="anonymous" />
@endpush
@push('styles')
    <!-- [1] PAGE CSS -->
    <style>
@include('partials.list-page-css')
      body, .app-main { background: #fff !important; }
      .th-page { padding: 26px 0 40px; color: #1f1e1e; }
      .th-head-acts { display: flex; gap: 8px; flex: none; }
      .th-tool { display: inline-flex; align-items: center; gap: 7px; height: 38px; padding: 0 15px; border: 1px solid #ddd7ce; border-radius: 10px; background: #fff; color: #1f1e1e; font: inherit; font-size: 13px; font-weight: 600; text-decoration: none; white-space: nowrap; cursor: pointer; }
      .th-tool:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-panel { background: #fff; border: 1px solid #e5e5e5; border-radius: 14px; box-shadow: 0 1px 2px rgba(0, 0, 0, .04); overflow: hidden; }

      .th-actions { display: flex; flex-wrap: wrap; gap: 6px; }
      .th-act { display: inline-flex; align-items: center; justify-content: center; height: 32px; padding: 0 10px; border-radius: 8px; border: 1px solid #d7d7d7; background: #fff; color: #1f1e1e; font: inherit; font-size: 12.5px; font-weight: 600; text-decoration: none; cursor: pointer; }
      .th-act:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .th-act.is-receipt { border-color: rgba(161, 22, 38, .35); color: #a11626; }
      .th-act.is-disabled { opacity: .5; cursor: not-allowed; }
      .th-chips { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; min-width: 0; }
      .th-chips .vz-chip { white-space: normal; }
      .th-amount { width: 100%; text-align: right; font-weight: 600; font-variant-numeric: tabular-nums; }
      .th-amount .lp-sub { font-weight: 400; }
      .th-card { display: none; }

      /* the filters: a row of boxes on a desktop, a bottom sheet on a phone (one set of controls) */
      .th-sheet-head, .th-sheet-dl { display: none; }
      .th-link { padding: 0; border: 0; background: none; color: #a11626; font: inherit; font-weight: 600; cursor: pointer; }
      @media (min-width: 768px) {
        .th-sheet, .th-sheet-foot, .th-two { display: contents; }
        #thShow { display: none; }
        .th-sheet .lp-field { flex: 0 1 112px; }
        .th-two .lp-field { flex-basis: 124px; }
        #reset-transaction-filters { padding: 0 11px; }
        .lp-tools .lp-search { flex: 1 1 150px; }
      }
      @media (max-width: 767.98px) {
        .container-fluid { padding-inline: 12px; }
        .th-page { padding-top: 16px; }
        .th-head-acts { display: none; }
        .th-panel { border: 0; border-radius: 0; box-shadow: none; overflow: visible; }
        .lp-tools { flex-wrap: nowrap; padding: 0 0 10px; border-bottom: 0; }
        .lp-table .tabulator-footer { padding: 0 0 10px; border: 0; }
        .lp-table .tabulator-footer.tabulator-footer { border-bottom: 0; }
        .lp-table .tabulator-footer .vz-ps { display: none; }
        .th-count { display: inline-flex; align-items: center; justify-content: center; min-width: 20px; height: 20px; padding: 0 6px; border-radius: 999px; background: #a11626; color: #fff; font-size: 12px; }
        .th-count[hidden] { display: none; }

        .th-sheet { position: fixed; inset: auto 0 0; width: 100%; max-width: none; max-height: 88vh; margin: 0; padding: 12px 16px calc(16px + env(safe-area-inset-bottom)); overflow: auto; overscroll-behavior: contain; border: 0; border-radius: 18px 18px 0 0; background: #fff; color: #1f1e1e; }
        .th-sheet::backdrop { background: rgba(10, 4, 5, .45); }
        .th-sheet-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
        .th-sheet-head h2 { margin: 0; font-size: 18px; font-weight: 700; }
        .th-sheet-head button { width: 44px; height: 44px; border: 0; background: none; color: #6e6a64; font-size: 20px; cursor: pointer; }
        .th-sheet .lp-field { display: flex; flex: none; margin-bottom: 14px; }
        .th-two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .th-sheet-foot { display: grid; grid-template-columns: 1fr 2fr; gap: 10px; margin-top: 4px; }
        .th-sheet-foot .th-tool { justify-content: center; height: 48px; font-size: 15px; }
        #thShow { border-color: #a11626; background: #a11626; color: #fff; }
        .th-sheet-dl { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 12px; padding-top: 4px; border-top: 1px solid #f0efec; color: #6e6a64; font-size: 13.5px; }
        .th-sheet-dl .th-link { min-height: 44px; }

        /* one card per payment, like My bookings */
        #transaction-history-table .tabulator-row { display: block; margin-bottom: 10px; padding: 0; border: 0; }
        #transaction-history-table .tabulator-row .tabulator-cell { display: none; }
        .th-card { display: block; padding: 14px; border: 1px solid #e5e5e5; border-radius: 14px; background: #fff; }
        .th-card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }
        .th-card-top .vz-chip { flex: none; }
        .th-ven { min-width: 0; color: #1f1e1e; font-size: 15px; font-weight: 600; overflow-wrap: anywhere; }
        .th-ref { margin-top: 2px; color: #6e6a64; font-size: 12.5px; }
        .th-money { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin-top: 10px; }
        .th-money b { font-size: 20px; font-weight: 700; font-variant-numeric: tabular-nums; }
        .th-money span { color: #6e6a64; font-size: 13px; text-align: right; }
        .th-bk { display: flex; align-items: center; gap: 6px; margin-top: 8px; color: #6e6a64; font-size: 13px; }
        .th-card .th-actions { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-top: 12px; }
        .th-card .th-act { height: 44px; padding: 0 6px; font-size: 13.5px; }
      }
    @include('partials.tabulator-pager-css')
    </style>
@endpush
@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="th-page">
              <header class="lp-head">
                <div>
                  <h1>Transaction History</h1>
                  <p>What you paid, how, and the receipt for each booking.</p>
                </div>
                <div class="th-head-acts">
                  <button type="button" class="th-tool" popovertarget="thDownload"><i class="bi bi-download" aria-hidden="true"></i>Download<i class="bi bi-chevron-down" aria-hidden="true"></i></button>
                  <div id="thDownload" class="lp-menu" popover>
                    <button id="export-csv" type="button" data-export="csv">CSV</button>
                    <button id="export-json" type="button" data-export="json">JSON</button>
                    <button id="print-table" type="button" data-export="print">Print</button>
                  </div>
                  <a class="th-tool" href="{{ route('customer.venues') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Venues</a>
                </div>
              </header>

              <div class="th-panel">
                <div class="lp-tools">
                  <label class="lp-search"><i class="bi bi-search" aria-hidden="true"></i><input id="table-filter" type="search" placeholder="Venue or booking no.…" aria-label="Search venue or booking number" autocomplete="off" /></label>
                  <button type="button" class="lp-filters-btn" id="thFilters"><i class="bi bi-sliders" aria-hidden="true"></i>Filters <span class="th-count" id="thCount" hidden></span></button>
                  <dialog class="th-sheet" id="thSheet" aria-labelledby="thSheetTitle">
                    <div class="th-sheet-head"><h2 id="thSheetTitle">Filters</h2><button type="button" data-sheet-close aria-label="Close filters"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                    <div class="lp-field">
                      <label for="payment-status-filter">Payment</label>
                      <select id="payment-status-filter">
                        <option value="">All</option>
                        @foreach ($paymentLabels as $label)
                        <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="lp-field">
                      <label for="booking-status-filter">Booking</label>
                      <select id="booking-status-filter">
                        <option value="">All</option>
                        @foreach ($bookingLabels as $label)
                        <option value="{{ $label }}">{{ $label }}</option>
                        @endforeach
                      </select>
                    </div>
                    <div class="lp-field">
                      <label for="payment-method-filter">Method</label>
                      <select id="payment-method-filter">
                        <option value="">All</option>
                        <option value="Cash">Cash</option>
                        <option value="GCash">GCash</option>
                      </select>
                    </div>
                    <div class="th-two">
                      <div class="lp-field"><label for="date-from-filter">Paid from</label><input id="date-from-filter" type="date" /></div>
                      <div class="lp-field"><label for="date-to-filter">to</label><input id="date-to-filter" type="date" /></div>
                    </div>
                    <div class="th-sheet-foot">
                      <button id="reset-transaction-filters" type="button" class="th-tool"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Reset</button>
                      <button id="thShow" type="button" class="th-tool" data-sheet-close>Show payments</button>
                    </div>
                    <div class="th-sheet-dl">Download <button type="button" class="th-link" data-export="csv">CSV</button><button type="button" class="th-link" data-export="json">JSON</button><button type="button" class="th-link" data-export="print">Print</button></div>
                  </dialog>
                </div>
                <div id="transaction-history-table" class="lp-table"></div>
              </div>
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/tabulator-tables@6.4.0/dist/js/tabulator.min.js" crossorigin="anonymous"></script>
    <script src="{{ asset('assets/js/table-page-size.js') }}"></script>
    <script src="{{ asset('assets/js/list-page.js') }}"></script>

    <!-- [6] PAGE SCRIPT -->
    <script>
      document.addEventListener('DOMContentLoaded', () => {
        const rows = {!! $transactionRowsJson !!};
        const RECEIPT_URL = @json(route('customer.receipt'));
        const parseDate = (value) => { const p = Date.parse(value); return Number.isNaN(p) ? 0 : p; };
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const shortDate = (value, year = true) => {
          const at = parseDate(value);
          return at ? new Date(at).toLocaleDateString('en-US', year ? { month: 'short', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric' }) : '';
        };
        const TONES = @json(\App\Support\Status::CHIP_TONES);
        const chip = (value) => `<span class="vz-chip vz-chip-${TONES[value] || 'grey'}">${esc(value)}</span>`;
        const actionButtons = (row) => {
          // View — the booking and its payment, in a modal over this page
          // (includes/booking-details-modal.php, 2026-10-27). The valid ID is
          // a link inside it.
          const viewBtn = `<button type="button" class="th-act" onclick="openBookingDetails('${encodeURIComponent(row.bookingId)}')">View</button>`;
          const receiptBtn = row.hasReceipt
            ? `<a class="th-act is-receipt" href="${RECEIPT_URL}?booking=${encodeURIComponent(row.bookingId)}">Receipt</a>`
            : `<span class="th-act is-disabled" title="No Transaction Receipt yet — issued once staff confirm payment">Receipt</span>`;
          const rebookPage = row.type === 'hostel' ? @json(route('customer.hostel-reservation')) : @json(route('customer.room-reservation'));
          const rebookBtn = row.roomCode
            ? `<a class="th-act" href="${rebookPage}?room=${encodeURIComponent(row.roomCode)}">Rebook</a>`
            : '';
          return `<div class="th-actions">${viewBtn}${receiptBtn}${rebookBtn}</div>`;
        };
        /* the phone's card for one payment; the page's CSS shows it instead of the cells */
        const card = (r) => `<article class="th-card">
            <div class="th-card-top"><span class="th-ven">${esc(r.venue)}</span>${chip(r.paymentStatus)}</div>
            <div class="th-ref">${esc(r.bookingId)} · ${r.type === 'hostel' ? 'Stay' : 'Event'} ${esc(shortDate(r.eventDate) || r.eventDate)}</div>
            <div class="th-money"><b>${esc(r.amount)}</b><span>${esc(r.paymentMethod)}${r.paidOn ? ' · paid ' + shortDate(r.paidOn, false) : ''}</span></div>
            <div class="th-bk">Booking ${chip(r.bookingStatus)}</div>
            ${actionButtons(r)}
          </article>`;

        const globalFilter = document.getElementById('table-filter');
        const paymentStatusFilter = document.getElementById('payment-status-filter');
        const bookingStatusFilter = document.getElementById('booking-status-filter');
        const paymentMethodFilter = document.getElementById('payment-method-filter');
        const dateFromFilter = document.getElementById('date-from-filter');
        const dateToFilter = document.getElementById('date-to-filter');
        const boxes = [paymentStatusFilter, bookingStatusFilter, paymentMethodFilter, dateFromFilter, dateToFilter];

        /* Two kinds of empty: nothing paid yet, or nothing left after the filters. */
        const emptyNote = () => {
          const note = document.createElement('div');
          note.innerHTML = [globalFilter, ...boxes].some((c) => c.value)
            ? 'No payments match these filters. <button type="button" class="th-link" data-reset>Reset</button>'
            : 'No payments yet. When you pay for a booking, it shows here with its receipt.';
          return note;
        };

        const table = new Tabulator('#transaction-history-table', Object.assign({
          data: rows, layout: 'fitColumns', placeholder: emptyNote,
          /* the size box is assets/js/table-page-size.js: type a number, or pick 10 / 25 / 50 / All */
          pagination: true, paginationSize: 10, movableColumns: true,
          initialSort: [{ column: 'transactionDate', dir: 'desc' }],
          rowFormatter: (row) => {
            const el = row.getElement();
            el.querySelector('.th-card')?.remove();
            el.insertAdjacentHTML('beforeend', card(row.getData()));
          },
          /* A hidden column still goes to the CSV, JSON and paper: each fact keeps its own column there. */
          columns: [
            { title: 'Transaction ID', field: 'transactionId', visible: false, download: true, print: true },
            {
              title: 'Booking', titleDownload: 'Booking ID', titlePrint: 'Booking ID', field: 'bookingId', width: 132,
              formatter: (cell) => { const r = cell.getRow().getData(); return `<div class="lp-stack"><span class="lp-name">${esc(r.bookingId)}</span><span class="lp-sub">${esc(r.transactionId)}</span></div>`; },
              formatterPrint: (cell) => cell.getValue(),
            },
            { title: 'Venue', field: 'venue', minWidth: 140 },
            { title: 'Event', titleDownload: 'Event Date', titlePrint: 'Event Date', field: 'eventDate', sorter: (a, b) => parseDate(a) - parseDate(b), width: 106, formatter: (cell) => esc(shortDate(cell.getValue()) || cell.getValue()), formatterPrint: (cell) => cell.getValue() },
            {
              title: 'Paid on', titleDownload: 'Transaction Date', titlePrint: 'Transaction Date', field: 'transactionDate', sorter: (a, b) => parseDate(a) - parseDate(b), width: 106,
              formatter: (cell) => shortDate(cell.getRow().getData().paidOn) || '—',
              formatterPrint: (cell) => cell.getValue(),
            },
            {
              title: 'Amount', field: 'amount', width: 96, hozAlign: 'right',
              formatter: (cell) => { const r = cell.getRow().getData(); return `<div class="th-amount">${esc(r.amount)}<span class="lp-sub">${esc(r.paymentMethod)}</span></div>`; },
              formatterPrint: (cell) => cell.getValue(),
            },
            { title: 'Payment Method', field: 'paymentMethod', visible: false, download: true, print: true },
            {
              title: 'Status', titleDownload: 'Payment Status', titlePrint: 'Payment Status', field: 'paymentStatus', width: 142,
              formatter: (cell) => { const r = cell.getRow().getData(); return `<div class="th-chips">${chip(r.paymentStatus)}${chip(r.bookingStatus)}</div>`; },
              formatterPrint: (cell) => cell.getValue(),
            },
            { title: 'Booking Status', field: 'bookingStatus', visible: false, download: true, print: true },
            { title: 'Actions', field: 'actions', formatter: (cell) => actionButtons(cell.getRow().getData()), headerSort: false, width: 226, print: false, download: false },   /* buttons mean nothing on paper */
          ],
        }, venusepPager('payments')));

        const sheet = document.getElementById('thSheet');
        const showBtn = document.getElementById('thShow');
        const count = document.getElementById('thCount');
        const applyFilters = () => {
          const query = globalFilter.value.trim().toLowerCase();
          const paymentStatus = paymentStatusFilter.value;
          const bookingStatus = bookingStatusFilter.value;
          const paymentMethod = paymentMethodFilter.value;
          const dateFrom = dateFromFilter.value ? Date.parse(dateFromFilter.value + 'T00:00') : null;
          const dateTo = dateToFilter.value ? Date.parse(dateToFilter.value + 'T00:00') : null;
          table.setFilter((row) => {
            const rowText = [row.transactionId, row.bookingId, row.venue, row.eventDate, row.transactionDate, row.amount, row.paymentMethod, row.paymentStatus, row.bookingStatus].join(' ').toLowerCase();
            return (!query || rowText.includes(query))
              && (!paymentStatus || row.paymentStatus === paymentStatus)
              && (!bookingStatus || row.bookingStatus === bookingStatus)
              && (!paymentMethod || row.paymentMethod === paymentMethod)
              && (!dateFrom || parseDate(row.paidOn) >= dateFrom)
              && (!dateTo || (row.paidOn && parseDate(row.paidOn) <= dateTo));
          });
          const on = boxes.filter((c) => c.value).length;
          count.textContent = on;
          count.hidden = !on;
          const left = table.getDataCount('active');
          showBtn.textContent = 'Show ' + left + (left === 1 ? ' payment' : ' payments');
        };
        const resetFilters = () => {
          [globalFilter, ...boxes].forEach((c) => { c.value = ''; });
          applyFilters();
        };
        [globalFilter, ...boxes].forEach((c) => c.addEventListener('input', applyFilters));
        document.getElementById('reset-transaction-filters').addEventListener('click', resetFilters);
        document.getElementById('thFilters').addEventListener('click', () => sheet.showModal());
        sheet.addEventListener('click', (event) => {
          if (event.target === sheet || event.target.closest('[data-sheet-close]')) sheet.close();   /* the dim area, the X, Show */
        });
        /* The Download menu and the sheet's links. Print: exactly the rows on the current
           page; pick "All rows" in the page size to print the whole list. */
        const exports = {
          csv: () => table.download('csv', 'my-transactions.csv'),
          json: () => table.download('json', 'my-transactions.json'),
          print: () => table.print('visible', true),
        };
        document.addEventListener('click', (event) => {
          if (event.target.closest('[data-reset]')) resetFilters();
          const button = event.target.closest('[data-export]');
          if (!button) return;
          document.getElementById('thDownload').hidePopover();
          if (sheet.open) sheet.close();
          exports[button.dataset.export]();
        });
        venusepPageSize(table, [10, 25, 50]);
        table.on('tableBuilt', () => {
          table.element.prepend(table.element.querySelector('.tabulator-footer'));   /* the pager goes above the rows */
          showBtn.textContent = 'Show ' + rows.length + (rows.length === 1 ? ' payment' : ' payments');
        });
      });
    </script>
    @php include base_path('legacy/includes/booking-details-modal.php'); /* "View" */ @endphp
@endpush
