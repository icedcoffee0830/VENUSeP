/* Script for resources/views/admin/transaction-history.blade.php. */
      document.addEventListener('DOMContentLoaded', () => {
        // Rows come from transaction_rows() — every customer, staff view.
        const rows = adminTransactionsPage.transactionRowsJson;
        const emptyTransactionMessage = 'No transactions found.';

        const parseDate = (value) => {
          const parsed = Date.parse(value);
          return Number.isNaN(parsed) ? 0 : parsed;
        };

        const TONES = adminTransactionsPage.chipTones;
        const statusBadge = (cell) => {
          const v = cell.getValue();
          return `<span class="vz-chip vz-chip-${TONES[v] || 'grey'}">${v}</span>`;
        };

        const actionButtons = (cell) => {
          const row = cell.getRow().getData();
          // View — the booking and its payment, in a modal over this page
          // (includes/booking-details-modal.php, 2026-10-27). The valid ID is
          // a link inside it; staff can open ANY booking's.
          const viewBtn = `<button type="button" class="th-act" onclick="openBookingDetails('${encodeURIComponent(row.bookingId)}')"><i class="bi bi-eye"></i>View</button>`;
          // Receipt opens admin/receipt.php — the same styled "paper slip" view
          // customer/receipt.php shows, just gated for staff instead of the
          // booking's own customer (see that file's header for why it's separate).
          // Its own "Download PDF" link goes to receipt-pdf.php when an actual
          // file is wanted — receipt-pdf.php itself always forces a download
          // (Content-Disposition: attachment), so linking the button straight to
          // it here would never let staff just LOOK at the receipt in the tab.
          const receiptBtn = row.hasReceipt
            ? `<a class="th-act" href="${adminTransactionsPage.receiptUrl}?booking=${encodeURIComponent(row.bookingId)}"><i class="bi bi-receipt"></i>Receipt</a>`
            : `<span class="th-act is-disabled" title="No Transaction Receipt yet — issued once payment is confirmed"><i class="bi bi-receipt"></i>Receipt</span>`;
          return `
            <div class="th-actions">
              ${viewBtn}
              ${receiptBtn}
            </div>`;
        };

        const table = new Tabulator('#transaction-history-table', {
          data: rows,
          layout: 'fitDataStretch',
          placeholder: emptyTransactionMessage,
          pagination: true,
          paginationSize: 10,          /* the size box is assets/js/table-page-size.js: type a number, or pick 10 / 25 / 50 / 100 / All */
          movableColumns: true,
          initialSort: [{ column: 'transactionDate', dir: 'desc' }],
          /* A customer's name like =HYPERLINK(...) must not run as a formula when the CSV is opened in a spreadsheet. */
          columnDefaults: { accessorDownload: (v) => (typeof v === 'string' && /^[=+\-@\t\r]/.test(v) ? "'" + v : v) },
          columns: [
            { title: 'Transaction ID', field: 'transactionId', minWidth: 150, width: 150 },
            { title: 'Booking ID', field: 'bookingId', minWidth: 140, width: 140 },
            { title: 'Customer Name', field: 'customerName', minWidth: 180, width: 180 },
            { title: 'Venue', field: 'venue', minWidth: 200, width: 200 },
            { title: 'Event Date', field: 'eventDate', sorter: (a, b) => parseDate(a) - parseDate(b), minWidth: 130, width: 130 },
            { title: 'Transaction Date', field: 'transactionDate', sorter: (a, b) => parseDate(a) - parseDate(b), minWidth: 150, width: 150 },
            { title: 'Amount', field: 'amount', minWidth: 110, width: 110, hozAlign: 'right' },
            { title: 'Payment Method', field: 'paymentMethod', minWidth: 150, width: 150 },
            { title: 'Payment Status', field: 'paymentStatus', formatter: statusBadge, minWidth: 170, width: 170, hozAlign: 'center' },
            { title: 'Booking Status', field: 'bookingStatus', formatter: statusBadge, minWidth: 170, width: 170, hozAlign: 'center' },
            { title: 'Actions', field: 'actions', formatter: actionButtons, headerSort: false, minWidth: 180, width: 180, hozAlign: 'center', print: false },   /* buttons mean nothing on paper */
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
        /* PRINTING: the printout is assets/js/transaction-print.js, shared with the customer's page. */
        const printTable = venusepTransactionPrint(table, () => {
          const parts = [];
          const query = globalFilter.value.trim();
          if (query) parts.push(`search "${query}"`);
          if (paymentStatusFilter.value) parts.push(`payment status ${paymentStatusFilter.value}`);
          if (bookingStatusFilter.value) parts.push(`booking status ${bookingStatusFilter.value}`);
          if (paymentMethodFilter.value) parts.push(`paid by ${paymentMethodFilter.value}`);
          if (dateFromFilter.value && dateToFilter.value) parts.push(`transactions ${venusepPrintDay(dateFromFilter.value)} to ${venusepPrintDay(dateToFilter.value)}`);
          else if (dateFromFilter.value) parts.push(`transactions from ${venusepPrintDay(dateFromFilter.value)}`);
          else if (dateToFilter.value) parts.push(`transactions up to ${venusepPrintDay(dateToFilter.value)}`);
          return parts;
        });
        document.getElementById('print-table').addEventListener('click', printTable);
        venusepPageSize(table, [10, 25, 50, 100]);
        table.on('tableBuilt', () => table.element.prepend(table.element.querySelector('.tabulator-footer')));   /* the pager goes above the rows */
      });
    
