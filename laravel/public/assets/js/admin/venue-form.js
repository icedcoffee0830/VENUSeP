/* Script for resources/views/admin/venue-form.blade.php. */
      var VP_VENUE_ID = document.getElementById('vpVenueId').value;
      var VP_CSRF = document.getElementById('vpCsrf').value;
      var VP_PLACEHOLDER_SVG = '<svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M15 9h.01M9 13h.01M15 13h.01M9 17h6"/></svg>';

      function vpNeedsVenue() {
        if (VP_VENUE_ID && VP_VENUE_ID !== '0') return false;
        venusepAlert('Save this venue first — a photo is attached to a saved venue.');
        return true;
      }
      function vpParseApiResponse(r) {
        return r.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Unexpected response from the server (HTTP ' + r.status + '): ' + text.slice(0, 300)); }
        });
      }
      function vpApiPost(action, extra) {
        var body = extra instanceof FormData ? extra : new FormData();
        body.append('action', action);
        body.append('venue_id', VP_VENUE_ID);
        body.append('_token', VP_CSRF);
        return fetch(venueFormPage.venuePhotoApiUrl, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vpParseApiResponse);
      }
      function vpSetCover(url) {
        var slot = document.getElementById('vpImageSlot');
        var btn = document.getElementById('vpRemoveBtn');
        if (slot) slot.innerHTML = url ? '<img id="vpCoverImg" src="' + url + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px" />' : VP_PLACEHOLDER_SVG;
        if (btn) btn.hidden = !url;
      }
      function vpChangePhoto(input) {
        // input.files is a LIVE FileList — read what we need from it
        // before clearing input.value, or the reset empties it too.
        var f = input.files && input.files[0];
        if (!f) { input.value = ''; return; }
        if (vpNeedsVenue()) { input.value = ''; return; }
        var body = new FormData();
        body.append('file', f);
        input.value = '';
        vpSetCover(URL.createObjectURL(f));   // instant local preview
        vpApiPost('upload', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not save the photo.'); vpSetCover(null); return; }
          vpSetCover(res.cover);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); vpSetCover(null); });
      }
      async function vpRemovePhoto() {
        if (vpNeedsVenue()) return;
        if (!(await venusepConfirm('This cannot be undone.', { title: 'Remove this venue\'s photo?', okLabel: 'Remove', danger: true }))) return;
        vpApiPost('delete').then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove the photo.'); return; }
          vpSetCover(null);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }

      /* "Save Changes" — the photo already saves itself the moment you
         change/remove it; Name, Description and the staff moved here save
         together, via admin/venue-save.php. */
      async function vpSaveChanges() {
        var name = document.getElementById('venueName').value.trim();
        var description = document.getElementById('venueDesc').value.trim();
        if (!name) { venusepAlert('Enter a venue name.'); return; }

        /* Moving someone changes which bookings they may act on, so say so first. */
        var moves = Object.keys(VP_MOVES);
        if (moves.length) {
          var lines = moves.map(function (id) { return VP_MOVES[id].name + ' (now ' + VP_MOVES[id].from + ')'; }).join('\n');
          var ok = await venusepConfirm(lines + '\n\nThey will handle ' + name + '\'s bookings instead, and can no longer act on their old venue\'s.',
            { title: 'Move ' + (moves.length === 1 ? 'this staff member' : 'these staff members') + ' here?', okLabel: 'Move and save' });
          if (!ok) return;
        }

        var body = new FormData();
        body.append('venue_id', VP_VENUE_ID);
        body.append('name', name);
        body.append('description', description);
        body.append('move_staff', JSON.stringify(moves));
        body.append('_token', VP_CSRF);
        fetch(venueFormPage.venueSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vpParseApiResponse)
          .then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Could not save the venue.'); return; }
            var movedLine = (res.moved && res.moved.length) ? '\n\n' + res.moved.join(', ') + (res.moved.length === 1 ? ' now handles ' : ' now handle ') + name + '.' : '';
            /* the form stays: the queued moves are done, the staff lists come back from the server */
            moves.forEach(function (id) { delete VP_MOVES[id]; });
            Live.refresh();
            venusepAlert('Venue saved.' + movedLine, { tone: 'ok', title: 'Saved' });
          })
          .catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
    

      /* user id -> { name, from }: people to move here on Save */
      const VP_MOVES = {};

      function togglePicker() {
        const p = document.getElementById('staffPicker');
        if (p) p.hidden = !p.hidden;
      }

      function filterPicker(q) {
        q = String(q || '').trim().toLowerCase();
        document.querySelectorAll('#staffPicker .vm-picker-item').forEach(function (item) {
          if (item.dataset.moved) return;
          item.hidden = q !== '' && item.dataset.search.indexOf(q) === -1;
        });
      }

      function vfEsc(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }

      function addStaff(btn) {
        const d = btn.dataset;
        VP_MOVES[d.id] = { name: d.name, from: d.from };
        const item = document.createElement('div');
        item.className = 'vm-staff-item';
        item.dataset.moveId = d.id;
        item.innerHTML =
          '<span class="vm-avatar">' + vfEsc(d.initials) + '</span>' +
          '<span class="vm-staff-name">' + vfEsc(d.name) +
            '<span class="vm-staff-sub is-moving">Moving here from ' + vfEsc(d.from) + ' — saved with Save Changes</span></span>' +
          '<button type="button" class="vm-remove" title="Keep them at ' + vfEsc(d.from) + '" onclick="undoMove(\'' + vfEsc(d.id) + '\')">&times;</button>';
        document.getElementById('staffList').appendChild(item);
        const pick = btn.closest('.vm-picker-item');
        pick.dataset.moved = '1';
        pick.hidden = true;
        const empty = document.getElementById('staffEmpty');
        if (empty) empty.hidden = true;
      }

      function undoMove(id) {
        delete VP_MOVES[id];
        const item = document.querySelector('#staffList [data-move-id="' + id + '"]');
        if (item) item.remove();
        const btn = document.querySelector('#staffPicker .vm-picker-add[data-id="' + id + '"]');
        if (btn) { const pick = btn.closest('.vm-picker-item'); delete pick.dataset.moved; pick.hidden = false; }
        const empty = document.getElementById('staffEmpty');
        if (empty && !document.querySelector('#staffList .vm-staff-item')) empty.hidden = false;
      }
    
