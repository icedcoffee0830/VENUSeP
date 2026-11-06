/* Script for resources/views/admin/office-settings.blade.php. */
      async function osSend(form, action) {
        const msg = form.querySelector('.os-msg');
        const btn = form.querySelector('button[type=submit]');
        const body = new FormData(form);
        body.append('venue_id', form.closest('[data-office]').dataset.office);
        body.append('action', action);
        btn.disabled = true;
        const out = await Live.post(OS_URL, body);
        btn.disabled = false;
        msg.className = 'os-msg ' + (out.ok ? 'ok' : 'bad');
        msg.textContent = out.message || 'Nothing was changed.';
        if (!out.ok) return;
        /* the form keeps what was just saved; a closure is added, so that form starts over */
        if (action === 'save') form.elements.reason.value = ''; else form.reset();
        Live.refresh();
      }
      function osSave(e, form) { e.preventDefault(); osSend(form, 'save'); return false; }
      function osClose(e, form) { e.preventDefault(); osSend(form, 'closure_add'); return false; }
      async function osRemove(venue, id, btn) {
        if (!(await venusepConfirm('Customers can book these dates again.', { title: 'Remove this closure?', okLabel: 'Remove' }))) return;
        const body = new FormData();
        body.append('venue_id', venue); body.append('action', 'closure_remove'); body.append('closure_id', id);
        btn.disabled = true;
        const out = await Live.post(OS_URL, body);
        if (out.ok) { Live.say(out.message || 'Saved.'); Live.refresh(); return; }
        btn.disabled = false;
        venusepAlert(out.message || 'Nothing was changed.');
      }
    
