/* Script for resources/views/partials/staff-covers.blade.php. */
  (function () {
    let COVER, ROWS;
    window.smCoverRead = function () { COVER = Live.data('sm-cover-data'); ROWS = COVER.rows; };
    window.smCoverRead();
    const COVER_URL = staffCovers.staffCoverSaveUrl;
    const STAFF_URL = staffCovers.staffSaveUrl;
    const officeOf = (id) => COVER.offices.find((o) => String(o.id) === String(id));

    /* option elements take text, so a typed name stays text */
    function fill(select, items) {
      select.replaceChildren(...items.map(([value, label]) => {
        const opt = document.createElement('option');
        opt.value = value; opt.textContent = label;
        return opt;
      }));
    }
    /* a form in a dialog: send, show a refusal inside it, redraw on success */
    function submits(dialog, send) {
      const form = dialog.querySelector('form');
      const error = dialog.querySelector('.sm-error');
      const button = form.querySelector('button[type=submit]');
      dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        error.textContent = '';
        button.disabled = true;
        const res = await send(new FormData(form));
        button.disabled = false;
        if (res.ok) {
          dialog.close();
          form.reset();
          Live.say(res.message || 'Saved.');
          window.smRedraw();
          return;
        }
        error.textContent = res.message || 'Nothing was changed.';
        if (res.field && form.elements[res.field]) form.elements[res.field].focus();
      });
    }
    function open(dialog) {
      dialog.querySelector('.sm-error').textContent = '';
      dialog.showModal();
    }

    /* ---- halls of one staff member ---- */
    const hallsDlg = document.getElementById('smHallsDlg');
    let hallsFor = null;
    window.smOpenHalls = function (row) {
      hallsFor = row;
      document.getElementById('smHallsTitle').textContent = 'Halls of ' + row.name;
      const list = document.getElementById('smHallsList');
      const othersOn = (hall) => ROWS.filter((r) => r.id !== row.id && r.halls.includes(Number(hall.id))).map((r) => r.name.split(' ')[0]);
      const held = (hall) => row.status === 'Active' && row.halls.includes(Number(hall.id));
      /* the server's rule, shown before Save: a hall they hold alone cannot be unticked */
      const checkLast = () => {
        const stranded = [];
        list.querySelectorAll('.sm-check').forEach((label) => {
          const lost = !label.querySelector('input').checked && label.dataset.last === '1';
          label.classList.toggle('is-warn', lost);
          if (lost) stranded.push(label.dataset.hall);
        });
        document.getElementById('smHallsWarn').textContent = stranded.length
          ? stranded.join(', ') + ' would have no staff. Give it to someone else first, or keep it ticked.' : '';
        hallsDlg.querySelector('button[type=submit]').disabled = stranded.length > 0;
      };
      list.replaceChildren(...COVER.halls.filter((h) => String(h.venue_id) === String(row.venueId)).map((hall) => {
        const label = document.createElement('label');
        const box = document.createElement('input');
        const note = document.createElement('small');
        const others = othersOn(hall);
        label.className = 'sm-check';
        label.dataset.hall = hall.name;
        label.dataset.last = held(hall) && !others.length ? '1' : '0';
        box.type = 'checkbox'; box.name = 'hall'; box.value = hall.id;
        box.checked = row.halls.includes(Number(hall.id));
        box.addEventListener('change', checkLast);
        note.className = 'sm-hall-note';
        note.textContent = others.length ? 'also ' + others.join(', ') : (held(hall) ? 'only them' : 'no one yet');
        label.append(box, ' ' + hall.name, note);
        return label;
      }));
      checkLast();
      open(hallsDlg);
    };
    submits(hallsDlg, (data) => Live.post(STAFF_URL, { action: 'set_halls', user_id: hallsFor.id,
      hall_ids: JSON.stringify(data.getAll('hall').map(Number)) }));

    /* ---- a cover ---- */
    const coverDlg = document.getElementById('smCoverDlg');
    const coverForm = coverDlg.querySelector('form');
    function coverChanged() {
      const staff = ROWS.find((r) => String(r.id) === coverForm.user_id.value);
      const office = officeOf(coverForm.venue_id.value);
      const halls = COVER.halls.filter((h) => office && String(h.venue_id) === String(office.id));
      fill(coverForm.room_id, [['', 'The whole office']].concat(halls.map((h) => [h.id, h.name])));
      document.getElementById('smCoverHall').hidden = !halls.length;   // the hostel is one unit
      const mine = staff ? officeOf(staff.venueId) : null;
      document.getElementById('smCoverHint').hidden = COVER.super || !mine || !office || mine.office_group === office.office_group;
    }
    coverForm.user_id.addEventListener('change', coverChanged);
    coverForm.venue_id.addEventListener('change', coverChanged);
    /* row: the Set up cover button on one person's row, already picked */
    window.smOpenCover = function (row) {
      fill(coverForm.user_id, ROWS.map((r) => [r.id, r.name + ' (' + r.venue + ')']));
      fill(coverForm.venue_id, COVER.offices.map((o) => [o.id, o.name]));
      if (row) coverForm.user_id.value = row.id;
      coverChanged();
      open(coverDlg);
    };
    document.getElementById('smCoverOpen').addEventListener('click', () => window.smOpenCover());
    submits(coverDlg, (data) => Live.post(COVER_URL, Object.assign({ action: 'cover_create' }, Object.fromEntries(data))));

    /* ---- a permanent move to the other group ---- */
    const moveDlg = document.getElementById('smMoveDlg');
    const moveForm = moveDlg.querySelector('form');
    let moveFor = null;
    window.smOpenMove = function (row) {
      moveFor = row;
      document.getElementById('smMoveTitle').textContent = 'Move ' + row.name + ' to the other group';
      const mine = officeOf(row.venueId);
      fill(moveForm.venue_id, COVER.offices.filter((o) => o.office_group !== mine.office_group).map((o) => [o.id, o.name]));
      const others = (COVER.staff[row.venueId] || []).filter((s) => s.id !== row.id);
      const box = document.getElementById('smMoveHalls');
      /* each hall only they cover names who takes it; none to name it: the server says so */
      box.replaceChildren(...(others.length ? row.lastHalls.filter((h) => h.id > 0) : []).map((hall) => {
        const label = document.createElement('label');
        const select = document.createElement('select');
        label.className = 'sm-field';
        select.dataset.hall = hall.id;
        select.required = true;
        fill(select, [['', 'Choose who takes it']].concat(others.map((s) => [s.id, s.name])));
        label.append('Replacement for ' + hall.name, select);
        return label;
      }));
      open(moveDlg);
    };
    submits(moveDlg, (data) => {
      const replacements = {};
      moveDlg.querySelectorAll('[data-hall]').forEach((s) => { replacements[s.dataset.hall] = Number(s.value); });
      return Live.post(COVER_URL, { action: 'move_create', user_id: moveFor.id, venue_id: data.get('venue_id'), reason: data.get('reason'),
        replacements: JSON.stringify(replacements) });
    });

    /* ---- the row buttons of both lists ---- */
    document.addEventListener('click', async (event) => {
      const button = event.target.closest('[data-act]');
      if (!button) return;
      const act = button.dataset.act;
      const fields = { action: act, id: button.dataset.id };
      if (act.endsWith('_reject')) {
        const reason = await venusepPrompt('The other side is told, with your reason.',
          { title: 'Reject this request?', label: 'Reason', okLabel: 'Reject', danger: true });
        if (reason === null) return;
        fields.reason = reason;
      } else if (act === 'move_approve') {
        if (!(await venusepConfirm('It is permanent. They leave their office now, and any replacement takes over at the same moment.',
          { title: 'Approve this move?', okLabel: 'Approve move' }))) return;
      } else if (act === 'cover_end') {
        if (!(await venusepConfirm('It stops now, for this staff member and for both offices.',
          { title: 'End this cover now?', okLabel: 'End cover', danger: true }))) return;
      }
      const res = await Live.post(COVER_URL, fields);
      if (!res.ok) { venusepAlert(res.message || 'Nothing was changed.'); return; }
      Live.say(res.message || 'Saved.');
      window.smRedraw();
    });
  })();
