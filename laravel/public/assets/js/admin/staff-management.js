/* Script for resources/views/admin/staff-management.blade.php. */
    document.addEventListener('DOMContentLoaded', () => {
      /* The real staff-side accounts, from `users` + `staff`. */
      const data = Live.data('sm-rows');
      const venues = staffManagementPage.smVenues;
      /* No "Unassigned" (2026-10-27): every staff member handles exactly one
         venue, and staff-save.php refuses an empty one. The list holds only the
         offices this admin may move staff to (DB-DECISIONS 28). */
      const venueEditorValues = {};
      venues.forEach(function (venue) {
        venueEditorValues[String(venue.id)] = venue.name;
      });
      /* A venue name is typed by an admin: it is text, never markup. Tabulator
         puts a formatter's string and each list item's label in as HTML, so both
         are escaped; the labels themselves stay plain (the list's input shows
         them as typed). */
      const escText = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
          return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
      };

      /* Every write goes to admin/staff-save.php, which re-checks that the
         caller is an admin and refuses the two ways an admin could lock
         everyone out: suspending themselves, or suspending the last active
         admin. Those checks live there and not here — this is a convenience,
         not a boundary. */
      const staffAction = function (body, onOk) {
        return Live.post(staffManagementPage.staffSaveUrl, body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Nothing was changed.'); return false; }
          if (typeof onOk === 'function') onOk(res);
          return true;
        });
      };
      /* Everything on this page from the server again, keeping the table's page, sort and filters. */
      window.smRedraw = function () {
        return Live.refresh().then(function () {
          table.replaceData(Live.data('sm-rows'));
          window.smCoverRead();
        });
      };

      const toggleActive = async function (row) {
        const suspend = row.status === 'Active';
        const ok = await venusepConfirm(
          suspend
            ? 'They will no longer be able to sign in. Everything they have already approved or confirmed keeps their name on it.'
            : 'They will be able to sign in again.',
          { title: (suspend ? 'Suspend ' : 'Reactivate ') + row.name + '?', okLabel: suspend ? 'Suspend' : 'Reactivate', danger: suspend });
        if (!ok) return;
        const body = new FormData();
        body.append('action', 'set_active');
        body.append('user_id', row.id);
        body.append('active', suspend ? '0' : '1');
        staffAction(body, window.smRedraw);
      };

      const resetPassword = async function (row) {
        const pw = await venusepPrompt(
          'Choose a temporary password and give it to them yourself. They must replace it when they sign in. Their old password stops working now.',
          { title: 'Reset the password of ' + row.name + '?', label: 'Temporary password (at least 8 characters)',
            autocomplete: 'off', okLabel: 'Reset password', danger: true });
        if (pw === null) return;
        const body = new FormData();
        body.append('action', 'reset_password');
        body.append('user_id', row.id);
        body.append('new_password', pw);
        staffAction(body, function (res) { venusepAlert(res.message, { tone: 'ok', title: 'Password reset' }); });
      };

      /* Hand over the super admin (DB-DECISIONS 28): a reason, then your password. No undo. */
      const handOver = async function (row) {
        const reason = await venusepPrompt(
          row.name + ' becomes the super admin. Your account is suspended at once and you are signed out. This cannot be undone.',
          { title: 'Hand over the super admin to ' + row.name + '?', label: 'Reason', okLabel: 'Next', danger: true });
        if (reason === null) return;
        const pw = await venusepPrompt('Both of you are emailed.',
          { title: 'Confirm the handover', label: 'Your password', inputType: 'password',
            autocomplete: 'current-password', okLabel: 'Hand over', danger: true });
        if (pw === null) return;
        const body = new FormData();
        body.append('action', 'handover');
        body.append('user_id', row.id);
        body.append('reason', reason);
        body.append('password', pw);
        staffAction(body, function (res) {
          venusepAlert(res.message, { tone: 'ok', title: 'Handed over' })
            .then(function () { window.location.href = staffManagementPage.adminLoginUrl; });
        });
      };


      /* a row's buttons, as many as this person allows */
      const rowActions = function (row) {
        /* No button on your own row. The server refuses it anyway, but offering a click
           that can only fail is worse than not offering it, and this is the click that
           would end your own access. */
        if (row.isSelf) return '<span class="lp-acts"><span class="lp-you">This is you</span></span>';
        if (!row.canManage) return '';
        const active = row.status === 'Active';
        const staffActive = row.role === 'Staff' && active;
        return '<div class="lp-acts">'
          + (row.canHandOver ? '<button type="button" class="lp-btn btn-staff-handover">Make super admin</button>' : '')
          + '<button type="button" class="lp-btn btn-staff-reset">Reset password</button>'
          + (staffActive ? '<button type="button" class="lp-btn btn-staff-cover">Set up cover</button><button type="button" class="lp-btn btn-staff-move">Move group</button>' : '')
          + '<button type="button" class="lp-btn btn-staff-toggle ' + (active ? 'lp-btn-no">Suspend' : 'lp-btn-ok">Reactivate') + '</button></div>';
      };

      const hallsCell = function (r) {
        if (r.wholeHostel) return '<span class="vz-chip vz-chip-grey">Whole hostel</span>';
        if (r.role === 'Super admin') return '<span class="sm-halls-names">All halls</span>';
        if (r.role === 'Admin') return '<span class="sm-halls-names">All halls of ' + escText(r.venue) + '</span>';
        const n = r.halls.length;
        const edit = function (text, icon) {
          return r.canHalls ? '<button type="button" class="lp-btn sm-halls-edit sm-halls-count" aria-label="Change the halls of ' + escText(r.name) + '">' + text + ' <i class="bi ' + icon + '" aria-hidden="true"></i></button>' : '';
        };
        if (!n) return '<div class="lp-row"><span class="vz-chip vz-chip-amber">No halls</span>' + edit('Assign', 'bi-plus') + '</div>';
        const count = n + (n === 1 ? ' hall' : ' halls');
        return '<div class="lp-row" title="' + escText(r.hallNames) + '">' + (edit(count, 'bi-pencil') || '<strong>' + count + '</strong>')
          + '<span class="sm-halls-names lp-ell">' + escText(r.hallNames) + '</span></div>';
      };

      const table = new Tabulator('#users-table', Object.assign({
        data: data,
        placeholder: 'No people match these filters.',
        layout: 'fitColumns',
        pagination: true,
        paginationSize: 10,
        paginationSizeSelector: [10, 25, 50, 100],
        movableColumns: true,
        columns: [
          {
            title: 'Person', field: 'name', minWidth: 190,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              return '<div class="lp-stack"><span class="lp-name">' + escText(r.name) + '</span><span class="lp-sub">' + escText(r.email) + '</span></div>';
            },
          },
          {
            title: 'Role', field: 'role', width: 118,
            formatter: function (cell) { return '<span class="vz-chip vz-chip-' + (cell.getValue() === 'Staff' ? 'line' : 'crimson') + '">' + escText(cell.getValue()) + '</span>'; },
          },
          {
            title: 'Office',
            field: 'venueId',
            formatter: function (cell) { return escText(cell.getRow().getData().venue); },   // the office label, from the server
            editor: 'list',
            editorParams: { values: venueEditorValues, itemFormatter: escText },
            editable: function (cell) { const r = cell.getRow().getData(); return r.role === 'Staff' && r.canManage; },
            width: 112,
            tooltip: function (e, cell) { return cell.getRow().getData().venue; },   // a long office label is cut short in the cell
            cellEdited: function (cell) {
              const row = cell.getRow().getData();
              const body = new FormData();
              body.append('action', 'set_venue');
              body.append('user_id', row.id);
              body.append('venue_id', cell.getValue() || '');
              staffAction(body).then(window.smRedraw);   /* a refused move puts the cell back */
            },
          },
          {
            title: 'Halls', field: 'hallNames', headerSort: false, minWidth: 145, cssClass: 'lp-wide',
            formatter: function (cell) { return hallsCell(cell.getRow().getData()); },
            cellClick: function (e, cell) {
              if (e.target.closest('.sm-halls-edit')) window.smOpenHalls(cell.getRow().getData());
            },
          },
          {
            title: 'Status', field: 'status', width: 160,
            formatter: function (cell) {
              const r = cell.getRow().getData();
              /* 'Invited' is gone: users.is_active is a boolean, and nothing in the system
                 can send an invitation, so no account could ever have been in that state. */
              return '<div class="lp-stack"><span class="vz-chip vz-chip-' + (r.status === 'Active' ? 'green' : 'grey') + '">' + escText(r.status) + '</span>'
                + '<span class="lp-sub">' + (r.lastLogin === 'never' ? 'Never signed in' : 'Signed in ' + escText(r.lastLogin)) + '</span></div>';
            },
          },
          {
            title: 'Actions',
            field: 'actions',
            width: 240,
            headerSort: false,
            formatter: function (cell) { return rowActions(cell.getRow().getData()); },
            cellClick: function (e, cell) {
              const row = cell.getRow().getData();
              if (e.target.closest('.btn-staff-reset')) resetPassword(row);
              else if (e.target.closest('.btn-staff-handover')) handOver(row);
              else if (e.target.closest('.btn-staff-cover')) window.smOpenCover(row);
              else if (e.target.closest('.btn-staff-move')) window.smOpenMove(row);
              else if (e.target.closest('.btn-staff-toggle')) toggleActive(row);
            },
          },
        ],
      }, venusepPager('people')));
      table.on('tableBuilt', () => table.element.prepend(table.element.querySelector('.tabulator-footer')));   /* the pager goes above the rows */

      /* the search and the three boxes work together */
      const search = document.getElementById('table-filter');
      const boxes = document.querySelectorAll('[data-filter]');
      const applyFilters = function () {
        const filters = [];
        if (search.value) filters.push([{ field: 'name', type: 'like', value: search.value }, { field: 'email', type: 'like', value: search.value }]);
        boxes.forEach(function (box) { if (box.value) filters.push({ field: box.dataset.filter, type: '=', value: box.value }); });
        table.setFilter(filters);
      };
      search.addEventListener('input', applyFilters);
      boxes.forEach(function (box) { box.addEventListener('change', applyFilters); });

      /* People / Covers / Moves: the address keeps the tab, so a refresh or the sidebar's link lands on it */
      document.addEventListener('click', function (event) {
        const link = event.target.closest('.lp-seg [data-tab]');
        if (!link) return;
        event.preventDefault();
        history.replaceState(null, '', link.href);
        document.querySelectorAll('.lp-seg [data-tab]').forEach(function (a) {
          if (a === link) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
        });
        document.querySelectorAll('[data-tab-panel]').forEach(function (panel) { panel.hidden = panel.dataset.tabPanel !== link.dataset.tab; });
        if (link.dataset.tab === 'people') table.redraw(true);   /* a table drawn while hidden has no sizes */
      });
    });
  
