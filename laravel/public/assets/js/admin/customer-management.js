/* Script for resources/views/admin/customer-management.blade.php. */
    document.addEventListener('DOMContentLoaded', () => {
      /* The real customer accounts, from `users` + `customers`. Walk-ins
         (no user_id) never appear here — see admin/customer-management.php. */
      const data = Live.data('cm-rows');

      /* Every write goes to admin/customer-save.php, which re-checks that the
         caller is admin or staff. That check lives there, not here — this is
         a convenience, not a boundary. */
      const customerAction = function (body) {
        return Live.send(customerManagementPage.customerSaveUrl, body, function () { table.replaceData(Live.data('cm-rows')); });   /* same page, sort and filters */
      };

      const toggleActive = async function (row) {
        const suspend = row.status === 'Active';
        const ok = await venusepConfirm(
          suspend
            ? (row.open ? 'Warning: they have ' + row.open + ' upcoming or unpaid booking(s), which stay as they are. ' : '') + 'They will be signed out immediately and cannot sign in or book again until reactivated. Their existing bookings and history are unaffected.'
            : 'They will be able to sign in and book again.',
          { title: (suspend ? 'Suspend ' : 'Reactivate ') + row.name + '?', okLabel: suspend ? 'Suspend' : 'Reactivate', danger: suspend });
        if (!ok) return;
        const body = new FormData();
        body.append('action', 'set_active');
        body.append('user_id', row.userId);
        body.append('active', suspend ? '0' : '1');
        customerAction(body);
      };

      const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

      /* Block or lift at one office: a reason either way. The customer never sees it. */
      const blockAction = async function (row, act, venueId) {
        const b = row.blocks.find(function (x) { return x.venueId === venueId; });
        const reason = act === 'block'
          ? await venusepPrompt('They can still sign in and see everything, but cannot make new bookings at ' + b.office + ', online or at the counter. A pending request still blocked at its start is cancelled. The reason is kept; they never see it.',
              { title: 'Block ' + row.name + ' at ' + b.office + '?', label: 'Reason', okLabel: 'Block', danger: true })
          : await venusepPrompt('Blocked by ' + b.by + ' on ' + b.at + ': ' + b.reason + '. They can book at ' + b.office + ' again.',
              { title: 'Lift the block on ' + row.name + '?', label: 'Reason for lifting', okLabel: 'Lift block' });
        if (reason === null) return;
        const body = new FormData();
        body.append('action', act);
        body.append('customer_id', row.id);
        body.append('venue_id', venueId);
        body.append('reason', reason);
        customerAction(body);
      };

      /* Correct the email of an account made at the counter: the new address, then the reason. */
      const fixEmailAction = async function (row) {
        const email = await venusepPrompt('Made at the counter and never signed into, so the email can still be corrected. The account’s password and any reset link stop working, and the welcome email goes to the new address. Now: ' + row.email,
          { title: 'Fix the email of ' + row.name + '?', label: 'Correct email', placeholder: 'name@example.com', okLabel: 'Next' });
        if (email === null) return;
        const reason = await venusepPrompt('Changing ' + row.email + ' to ' + email.trim() + '. The reason is kept with the correction.',
          { title: 'Why is it being corrected?', label: 'Reason', okLabel: 'Fix email' });
        if (reason === null) return;
        const body = new FormData();
        body.append('action', 'fix_email');
        body.append('customer_id', row.id);
        body.append('email', email);
        body.append('reason', reason);
        customerAction(body);
      };

      const BOOKINGS_URL = customerManagementPage.bookingRequestsUrl;
      const byDate = (a, b) => (Date.parse(a) || 0) - (Date.parse(b) || 0);

      /* The offices to block at: one office is one click; several open a small menu. Suspend,
         Reactivate and Fix email always stay one click, on the row. */
      const blockItem = function (row, b) {
        return b.on
          ? '<button type="button" class="btn-cust-block" data-act="lift" data-venue="' + b.venueId + '"><span>Lift block at ' + esc(b.office) + '<small>Blocked ' + esc(b.at) + ' by ' + esc(b.by) + ': ' + esc(b.reason) + '</small></span></button>'
          : '<button type="button" class="btn-cust-block" data-act="block" data-venue="' + b.venueId + '">Block at ' + esc(b.office) + '</button>';
      };
      const blockButtons = function (row) {
        if (row.blocks.length === 1) {
          const b = row.blocks[0];
          return b.on
            ? '<button type="button" class="lp-btn lp-btn-ok btn-cust-block" data-act="lift" data-venue="' + b.venueId + '">Lift block</button>'
            : '<button type="button" class="lp-btn btn-cust-block" data-act="block" data-venue="' + b.venueId + '">Block at ' + esc(b.office) + '</button>';
        }
        if (!row.blocks.length) return '';
        const id = 'cmBlocks' + row.id;
        return '<button type="button" class="lp-btn" popovertarget="' + id + '">Blocks <i class="bi bi-chevron-down" aria-hidden="true"></i></button>'
          + '<div class="lp-menu" id="' + id + '" popover>' + row.blocks.map(function (b) { return blockItem(row, b); }).join('') + '</div>';
      };
      const rowActions = function (row) {
        const active = row.status === 'Active';
        return '<div class="lp-acts">' + blockButtons(row)
          + (row.fixEmail ? '<button type="button" class="lp-btn btn-cust-fix-email">Fix email</button>' : '')
          + (customerManagementPage.suspendClass ? '<button type="button" class="lp-btn ' + customerManagementPage.suspendClass + ' ' + (active ? 'lp-btn-no">Suspend' : 'lp-btn-ok">Reactivate') + '</button>' : '')
          + '</div>';
      };

      const table = new Tabulator('#customers-table', Object.assign({
        data: data,
        placeholder: 'No customers match these filters.',
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        paginationSizeSelector: [10, 25, 50, 100],
        movableColumns: true,
        columns: [
          {
            title: 'Customer', field: 'name', minWidth: 240, cssClass: 'lp-wide',
            formatter: function (cell) {
              const r = cell.getRow().getData();
              const contact = [r.email, r.phone].filter(Boolean).map(esc).join(' · ');
              return '<div class="lp-stack"><span class="lp-name">' + esc(r.name) + (r.usep === 'USeP' ? ' <span class="vz-chip vz-chip-crimson">USeP</span>' : '') + '</span><span class="lp-sub">' + contact + '</span></div>';
            },
          },
          {
            title: 'Bookings', field: 'bookings', width: 108,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              const words = r.bookings + (r.bookings === 1 ? ' booking' : ' bookings');
              return r.bookings ? '<a class="cm-bookings" href="' + BOOKINGS_URL + '?q=' + encodeURIComponent(r.name) + '">' + words + '</a>' : words;
            },
          },
          {
            title: 'Activity', field: 'lastLogin', width: 190, sorter: byDate,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              return '<div class="lp-stack"><span>' + (r.lastLogin === 'never' ? 'Never signed in' : 'Last sign-in ' + esc(r.lastLogin)) + '</span><span class="lp-sub">'
                + (r.counter ? 'Made at the counter ' : 'Joined ') + esc(r.joined) + '</span></div>';
            },
          },
          {
            /* per office (DB-DECISIONS 28): only what is true, a chip for each block */
            title: 'Status', field: 'status', width: 180, cssClass: 'lp-wide',
            formatter: function (cell) {
              const r = cell.getRow().getData();
              const blocks = r.blocks.filter(function (b) { return b.on; }).map(function (b) {
                return '<span class="vz-chip vz-chip-red" title="' + esc('By ' + b.by + ', ' + b.at + ': ' + b.reason) + '">Blocked at ' + esc(b.office) + '</span>';
              }).join('');
              return '<div class="lp-stack"><span class="vz-chip vz-chip-' + (r.status === 'Active' ? 'green' : 'grey') + '">' + esc(r.status) + '</span>' + blocks
                + (r.fixEmail ? '<span class="cm-flag"><i class="bi bi-envelope-exclamation" aria-hidden="true"></i>Email can still be fixed</span>' : '') + '</div>';
            },
          },
          {
            title: 'Actions', field: 'actions', width: 180, headerSort: false,
            formatter: function (cell) { return rowActions(cell.getRow().getData()); },
            cellClick: function (e, cell) {
              const row = cell.getRow().getData();
              const block = e.target.closest('.btn-cust-block');
              if (block) {
                block.closest('[popover]')?.hidePopover();
                blockAction(row, block.dataset.act, Number(block.dataset.venue));
              } else if (e.target.closest('.btn-cust-fix-email')) fixEmailAction(row);
              else if (customerManagementPage.suspendClass && e.target.closest('.' + customerManagementPage.suspendClass)) toggleActive(row);
            },
          },
        ],
      }, venusepPager('customers')));
      table.on('tableBuilt', () => table.element.prepend(table.element.querySelector('.tabulator-footer')));   /* the pager goes above the rows */

      /* the search and the three boxes work together */
      const search = document.getElementById('table-filter');
      const status = document.getElementById('cmStatus');
      const usep = document.getElementById('cmUsep');
      const blocked = document.getElementById('cmBlocks');
      const applyFilters = function () {
        const q = search.value.trim().toLowerCase();
        table.setFilter(function (r) {
          return (!q || [r.name, r.email, r.phone].join(' ').toLowerCase().includes(q))
            && (!status.value || r.status === status.value)
            && (!usep.value || (r.usep === 'USeP') === (usep.value === 'yes'))
            && (!blocked.value || r.blocks.some(function (b) { return b.on; }));
        });
      };
      search.addEventListener('input', applyFilters);
      [status, usep, blocked].forEach(function (box) { box.addEventListener('change', applyFilters); });
    });
  
