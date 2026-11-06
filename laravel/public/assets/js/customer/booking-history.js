/* Script for resources/views/customer/booking-history.blade.php. */
      /* filled in below, when the table is set up */
      let bhRedraw = function () {};
      function bookingChange(url, body) { return Live.send(url, body, bhRedraw); }
      async function bookingCancel(ref) {
        if (!(await venusepConfirm('Your request is cancelled and the date is freed. You owe nothing.', { title: 'Cancel this request?', okLabel: 'Cancel request', cancelLabel: 'Keep it' }))) return;
        const body = new FormData(); body.append('booking', ref);
        bookingChange(bookingHistoryPage.bookingCancelUrl, body);
      }
      function bookingFix(ref, input) {
        const body = new FormData(); body.append('booking', ref); body.append('id_document', input.files[0]);
        input.value = '';   /* a refused file must not count as a field in use */
        bookingChange(bookingHistoryPage.bookingFixUrl, body);
      }
    

      document.addEventListener('DOMContentLoaded', function () {
        const table = document.getElementById('bookingHistoryTable');
        if (!table) return;
        /* all three are read again after a refresh: the rows are new elements then */
        let tbody = table.querySelector('tbody');
        let rows = Array.from(tbody.querySelectorAll('tr[data-booking-row]'));
        const searchInput = document.getElementById('booking-history-search');
        const venueFilter = document.getElementById('booking-history-venue');
        const dateFromFilter = document.getElementById('booking-history-date-from');
        const dateToFilter = document.getElementById('booking-history-date-to');
        const entriesSelect = document.getElementById('booking-history-entries');
        const resetButton = document.getElementById('booking-history-reset');
        const statusTabs = Array.from(document.querySelectorAll('[data-booking-status]'));
        const pagination = document.getElementById('booking-history-pagination');
        const info = document.getElementById('booking-history-info');
        let emptyRow = document.getElementById('booking-history-empty');
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
            return (!activeStatus || (activeStatus === 'due' ? row.dataset.due === '1' : row.dataset.status === activeStatus))
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

        /* Fresh rows under the same filters, sort and page. */
        bhRedraw = function () {
          tbody = table.querySelector('tbody');
          rows = Array.from(tbody.querySelectorAll('tr[data-booking-row]'));
          emptyRow = document.getElementById('booking-history-empty');
          bhRefundActions();
          render();
        };
        /* the 30-second check */
        Live.watch('mine', function () { return Live.refresh().then(bhRedraw); });
      });
    

      /* the row's refund state is on the row (data-refund-status); a fresh row runs through this again */
      function bhRefundActions() {
        const withdraw = async function (ref) {
          const ok = await venusepConfirm(
            'Your booking is not affected either way — it stays yours. You can request a refund again later if you change your mind.',
            { title: 'Withdraw your refund request for ' + ref + '?', okLabel: 'Withdraw', danger: true });
          if (!ok) return;
          Live.send(bookingHistoryPage.refundSubmitUrl, { booking: ref, action: 'withdraw' }, bhRedraw);
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

        document.querySelectorAll('[data-refund-status]:not([data-refund-status=""])').forEach(function (row) {
          const ref = row.dataset.bookingId, rec = { status: row.dataset.refundStatus };
          const link = row.querySelector('.booking-action-refund');

          /* Open on staff, not yet decided -> the customer can pull it back (the
             same states refund-submit accepts a withdrawal from). */
          if (rec.status === 'requested' || rec.status === 'under_review') {
            action(row, 'booking-action-withdraw', 'bi-x-circle', 'Withdraw request', function () { withdraw(ref); });
          /* Returned for correction is PAPERWORK, not a denial — they fix it. */
          } else if (rec.status === 'returned_for_correction') {
            action(row, 'booking-action-fix', 'bi-arrow-counterclockwise', 'Fix and resubmit', null,
              bookingHistoryPage.refundRequestUrl + '?booking=' + encodeURIComponent(ref));
          /* Approved, denied or paid out: nothing left to offer, and a "Request
             Refund" button on a refunded booking would be a trap. A WITHDRAWN
             request keeps the button the server decided on: they may file again. */
          } else if (link && rec.status !== 'withdrawn') {
            link.remove();
          }
        });
      }
      document.addEventListener('DOMContentLoaded', bhRefundActions);
    
