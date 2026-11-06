/* Script for resources/views/customer/transaction-history.blade.php. */
      document.addEventListener('DOMContentLoaded', () => {
        const rows = transactionHistoryPage.transactionRowsJson;
        const RECEIPT_URL = transactionHistoryPage.receiptUrl;
        const parseDate = (value) => { const p = Date.parse(value); return Number.isNaN(p) ? 0 : p; };
        const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
        const shortDate = (value, year = true) => {
          const at = parseDate(value);
          return at ? new Date(at).toLocaleDateString('en-US', year ? { month: 'short', day: 'numeric', year: 'numeric' } : { month: 'short', day: 'numeric' }) : '';
        };
        const TONES = transactionHistoryPage.chipTones;
        const chip = (value) => `<span class="vz-chip vz-chip-${TONES[value] || 'grey'}">${esc(value)}</span>`;
        const actionButtons = (row) => {
          // View — the booking and its payment, in a modal over this page
          // (includes/booking-details-modal.php, 2026-10-27). The valid ID is
          // a link inside it.
          const viewBtn = `<button type="button" class="th-act" onclick="openBookingDetails('${encodeURIComponent(row.bookingId)}')">View</button>`;
          const receiptBtn = row.hasReceipt
            ? `<a class="th-act is-receipt" href="${RECEIPT_URL}?booking=${encodeURIComponent(row.bookingId)}">Receipt</a>`
            : `<span class="th-act is-disabled" title="No Transaction Receipt yet — issued once staff confirm payment">Receipt</span>`;
          const rebookPage = row.type === 'hostel' ? transactionHistoryPage.hostelReservationUrl : transactionHistoryPage.roomReservationUrl;
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
    
