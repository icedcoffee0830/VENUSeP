/* Script for resources/views/admin/room-form.blade.php. */
      // UI-only custom dropdowns (same behavior as the calendar sort dropdown). No backend.
      document.querySelectorAll('.vm-dd').forEach((dd) => {
        dd.querySelectorAll('.vm-dd-option').forEach((opt) => {
          opt.addEventListener('click', () => {
            dd.querySelector('.vm-dd-trigger').textContent = opt.textContent;
            dd.removeAttribute('open');
          });
        });
      });
      // Close any open dropdown when clicking outside of it.
      document.addEventListener('click', (e) => {
        document.querySelectorAll('.vm-dd[open]').forEach((dd) => {
          if (!dd.contains(e.target)) dd.removeAttribute('open');
        });
      });
    

      var MT_FMT = { month: 'short', day: 'numeric', year: 'numeric' };
      function mtDate(v) {
        if (!v) return '';
        var d = new Date(v + 'T00:00:00');
        return isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-US', MT_FMT);
      }
      // Hostel rooms: the rate follows the CR type: communal P350, private P400.
      // Letting staff set them independently would create two facts about one price.
      var CR_RATE = { 'Communal CR': '350.00', 'Private CR': '400.00' };
      document.addEventListener('DOMContentLoaded', function () {
        var cr = document.getElementById('roomCr');
        if (!cr) return;
        cr.querySelectorAll('.vm-dd-option').forEach(function (opt) {
          opt.addEventListener('click', function () {
            var rate = CR_RATE[opt.textContent.trim()];
            var box = document.getElementById('roomRate');
            if (rate && box) box.value = rate;
          });
        });
      });
      function mtSync() {
        var on = document.getElementById('mtOn').checked;
        document.getElementById('mtBody').hidden = !on;
        if (!on) return;

        var hard   = document.querySelector('input[name="mtTier"][value="hard"]').checked;
        var indef  = document.getElementById('mtIndef').checked;
        var from   = document.getElementById('mtFrom').value;
        var until  = document.getElementById('mtUntil');
        var reason = document.getElementById('mtReason').value.trim();

        // "Indefinite" means no END date — not no date. The Until field is the
        // only thing it switches off.
        until.disabled = indef;
        if (indef) until.value = '';

        var echo = document.getElementById('mtEcho');
        if (!from) { echo.innerHTML = 'Pick a <b>From</b> date to see what the customer will be shown.'; return; }

        var when = indef ? 'no set return date'
                 : (until.value ? 'until ' + mtDate(until.value) : '…');
        var head = hard ? 'Closed for maintenance' : 'Partial maintenance';
        var says = hard
          ? 'The room cannot be booked on these dates. Customers picking one are told why.'
          : 'The room stays bookable. Customers are shown a notice before they reserve.';
        var warn = (indef && hard)
          ? '<div class="vm-mt-warn" style="margin-top:.45rem">Indefinite closures are listed on Venue Management once they pass 14 days, so the room cannot be closed and forgotten.</div>'
          : '';
        echo.innerHTML = 'Customers will see: <b>' + head + ' · ' + when + '</b>'
          + (reason ? ' <b>(' + reason.replace(/</g, '&lt;') + ')</b>' : '')
          + '<div style="margin-top:.3rem">' + says + '</div>' + warn;
      }
      mtSync();
    

      /* The window as admin/room-save.php reads it (2026-10-27). */
      function mtValue() {
        if (!document.getElementById('mtOn').checked) return { on: false };
        return { on: true,
          tier: document.querySelector('input[name="mtTier"]:checked').value,
          from: document.getElementById('mtFrom').value,
          until: document.getElementById('mtIndef').checked ? null : (document.getElementById('mtUntil').value || ''),
          reason: document.getElementById('mtReason').value.trim() };
      }
      /* The saved window opens as it is — this section used to open switched
         off for every room, even one that was closed. */
      (function () {
        var m = roomFormPage.roomMaintenance;
        if (!m) return;
        document.getElementById('mtOn').checked = true;
        document.querySelector('input[name="mtTier"][value="' + (m.blocks ? 'hard' : 'medium') + '"]').checked = true;
        document.getElementById('mtFrom').value = m.from;
        if (m.until) { document.getElementById('mtUntil').value = m.until; } else { document.getElementById('mtIndef').checked = true; }
        document.getElementById('mtReason').value = m.reason || '';
        mtSync();
      })();

      var VM_ROOM_ID = document.getElementById('vmRoomId').value;
      var VM_CSRF = document.getElementById('vmCsrf').value;
      var VM_SAMPLE_PANO = 'https://pannellum.org/images/alma.jpg';
      var VM_ICON_28 = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';
      var VM_ICON_22 = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';

      function vmNeedsRoom() {
        if (VM_ROOM_ID) return false;
        venusepAlert('Save this room first — photos are attached to a saved room.');
        return true;
      }
      /* Parses the response as JSON, but if the server sent something else
         (an HTML error page, a stray PHP warning ahead of the JSON, a
         redirect to the login page) the failure says exactly what came
         back instead of the generic "could not reach the server" — that
         message used to fire for this case too, which hid the real cause. */
      function vmParseApiResponse(r) {
        return r.text().then(function (text) {
          try { return JSON.parse(text); }
          catch (e) { throw new Error('Unexpected response from the server (HTTP ' + r.status + '): ' + text.slice(0, 300)); }
        });
      }
      function vmApiGet(action) {
        return fetch(VM_API + '?action=' + encodeURIComponent(action) + '&room_id=' + encodeURIComponent(VM_ROOM_ID), { credentials: 'same-origin' })
          .then(vmParseApiResponse);
      }
      function vmApiPost(action, extra) {
        var body = extra instanceof FormData ? extra : new FormData();
        body.append('action', action);
        body.append('room_id', VM_ROOM_ID || '');
        body.append('_token', VM_CSRF);
        return fetch(VM_API, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(vmParseApiResponse);
      }

      var vmPano = null;
      function vmInitPano(src) {
        if (typeof pannellum === 'undefined') { return; }
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML = '';
        vmPano = pannellum.viewer('vmPanoView', {
          type: 'equirectangular', panorama: src, autoLoad: true, showControls: true,
          compass: true, hfov: 100, minHfov: 45, maxHfov: 120, mouseZoom: true, draggable: true,
        });
      }
      function vmPanoPlaceholder() {
        if (vmPano) { try { vmPano.destroy(); } catch (e) {} vmPano = null; }
        document.getElementById('vmPanoView').innerHTML =
          '<svg width="46" height="46" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.3"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/></svg>';
      }
      function vmLoadPano(input) {
        var f = input.files && input.files[0];
        input.value = '';
        if (!f) return;
        if (vmNeedsRoom()) return;
        vmInitPano(URL.createObjectURL(f));   // instant local preview
        var body = new FormData();
        body.append('file', f);
        vmApiPost('upload_pano', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not save the 360° photo.'); return; }
          vmInitPano(res.pano || VM_SAMPLE_PANO);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
      function vmClearPano() {
        if (vmNeedsRoom()) return;
        vmApiPost('delete_pano').then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove the 360° photo.'); return; }
          vmPanoPlaceholder();
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
    

      var VMPHOTOS = [];   // [{file, url}], cover-first — mirrors the server
      var VMSEL = 0;
      var VM_MAX_PHOTOS = 5;   // overwritten from the server's state on load (ROOM_PHOTO_MAX_COUNT)

      function vmSetShot(el, url, iconSvg) {
        if (!el) return;
        el.innerHTML = url ? '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover" alt="" />' : iconSvg;
      }
      function vmRefreshThumbs() {
        var urls = VMPHOTOS.map(function (p) { return p.url; });
        vmSetShot(document.getElementById('vmSideShot0'), urls[0], VM_ICON_28);
        vmSetShot(document.getElementById('vmSideShot1'), urls[1], VM_ICON_28);
        for (var i = 0; i < 3; i++) {
          var el = document.getElementById('vmThumb' + i);
          if (!el) continue;
          var url = urls[i + 2];
          if (i === 2) {
            el.innerHTML = (url ? '<img src="' + url + '" style="width:100%;height:100%;object-fit:cover;position:absolute;inset:0" alt="" />' : VM_ICON_22)
              + '<button type="button" class="vm-edit-photos" onclick="vmOpenGallery()">✎ Edit photos</button>';
          } else {
            vmSetShot(el, url, VM_ICON_22);
          }
        }
      }
      function vmApplyState(state) {
        VMPHOTOS = state.photos || [];
        if (state.maxPhotos) VM_MAX_PHOTOS = state.maxPhotos;
        if (VMSEL >= VMPHOTOS.length) VMSEL = 0;
        vmRefreshThumbs();
        vmGalRender();
        vmGalRenderAdd();
      }
      function vmLoadPhotos() {
        vmInitPano(VM_SAMPLE_PANO);   // shown immediately; swapped for the real one below if saved
        if (!VM_ROOM_ID) return;
        vmApiGet('list').then(function (res) {
          if (!res.ok) { console.error('vmLoadPhotos: server replied not-ok:', res); return; }
          vmApplyState(res);
          if (res.pano) vmInitPano(res.pano);
        }).catch(function (err) { console.error('vmLoadPhotos failed — photos/360 may not reflect what is actually saved:', err); });
      }

      function vmOpenGallery() {
        if (document.getElementById('vmGalOverlay')) return;
        var ov = document.createElement('div');
        ov.id = 'vmGalOverlay';
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(20,18,15,.75);z-index:3000;display:flex;align-items:center;justify-content:center;padding:24px';
        ov.innerHTML =
          '<div style="background:#fff;border-radius:14px;max-width:960px;width:100%;max-height:90vh;display:flex;flex-direction:column;overflow:hidden;font-family:var(--vm-font);color:var(--vm-text)">' +
          '<div style="display:flex;justify-content:space-between;align-items:center;padding:14px 18px;border-bottom:1px solid var(--vm-border)"><div style="font-weight:700">Room photos</div><button type="button" onclick="vmCloseGallery()" style="background:none;border:none;font-size:20px;color:#606a75;cursor:pointer">&times;</button></div>' +
          '<div style="padding:16px 18px;overflow:auto">' +
          '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px"><div style="font-weight:700;font-size:.95rem">Venue uploads <span id="vmGalCount" style="font-weight:400;color:#6e6a64"></span></div><label id="vmGalAddWrap" class="vm-btn vm-btn-outline vm-pano-btn" style="min-height:2rem;padding:0 .9rem;display:inline-flex;align-items:center"><span id="vmGalAddLabel">Add photos</span><input id="vmGalAddInput" type="file" accept="image/*" multiple onchange="vmGalAdd(this)" /></label></div>' +
          '<div id="vmGalBig" class="vm-galbig"></div>' +
          '<div id="vmGalGrid" class="vm-galgrid"></div>' +
          '<p style="color:#6e6a64;font-size:.78rem;margin:10px 2px 0">Click a photo to make it the cover. The &times; on a thumbnail removes it. Up to ' + VM_MAX_PHOTOS + ' photos per room; JPG, PNG or WEBP, 7 MB each.</p>' +
          '</div></div>';
        ov.addEventListener('click', function (e) { if (e.target === ov) vmCloseGallery(); });
        document.body.appendChild(ov);
        vmGalRender();
        vmGalRenderAdd();
      }
      function vmGalRenderAdd() {
        var count = document.getElementById('vmGalCount');
        var wrap = document.getElementById('vmGalAddWrap');
        var label = document.getElementById('vmGalAddLabel');
        var input = document.getElementById('vmGalAddInput');
        if (!count || !wrap || !label || !input) return;   // overlay not open
        var full = VMPHOTOS.length >= VM_MAX_PHOTOS;
        count.textContent = '(' + VMPHOTOS.length + ' of ' + VM_MAX_PHOTOS + ')';
        label.textContent = full ? 'Maximum reached' : 'Add photos';
        input.disabled = full;
        wrap.style.opacity = full ? '.5' : '1';
        wrap.style.cursor = full ? 'default' : 'pointer';
        wrap.style.pointerEvents = full ? 'none' : 'auto';
      }
      function vmGalAdd(input) {
        // input.files is a LIVE FileList, not a snapshot — clearing
        // input.value before finishing with `files` empties this same
        // reference too, silently turning the whole upload into a no-op.
        // Everything that needs to read `files` (the length checks, the
        // FormData loop) must happen before input.value is touched.
        var files = input.files;
        if (!files || !files.length) { input.value = ''; return; }
        if (vmNeedsRoom()) { input.value = ''; return; }
        var room = VMPHOTOS.length;
        if (room >= VM_MAX_PHOTOS) { venusepAlert('This room already has the maximum of ' + VM_MAX_PHOTOS + ' photos.'); input.value = ''; return; }
        if (room + files.length > VM_MAX_PHOTOS) {
          venusepAlert('Only ' + (VM_MAX_PHOTOS - room) + ' more photo(s) fit — a room can have at most ' + VM_MAX_PHOTOS + '. The rest will be skipped.');
        }
        var body = new FormData();
        for (var i = 0; i < files.length; i++) body.append('files[]', files[i]);
        input.value = '';   // safe now — the File objects are already captured in `body`
        vmApiPost('upload', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not upload those photos.'); return; }
          VMSEL = (res.photos || []).length - 1;
          vmApplyState(res);
          if (res.warning) venusepAlert(res.warning);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
      function vmGalSelect(i) {
        VMSEL = i;
        vmGalRender();
        if (i === 0 || vmNeedsRoom()) return;
        var order = VMPHOTOS.map(function (p) { return p.file; });
        var picked = order.splice(i, 1)[0];
        order.unshift(picked);
        var body = new FormData();
        order.forEach(function (f) { body.append('order[]', f); });
        vmApiPost('reorder', body).then(function (res) {
          if (!res.ok) return;
          VMSEL = 0;
          vmApplyState(res);
        }).catch(function (err) { console.error('vmGalSelect reorder failed:', err); });
      }
      async function vmGalDelete(i, e) {
        if (e) e.stopPropagation();
        if (vmNeedsRoom()) return;
        var photo = VMPHOTOS[i];
        if (!photo || !(await venusepConfirm('This cannot be undone.', { title: 'Remove this photo?', okLabel: 'Remove', danger: true }))) return;
        var body = new FormData();
        body.append('file', photo.file);
        vmApiPost('delete', body).then(function (res) {
          if (!res.ok) { venusepAlert(res.message || 'Could not remove that photo.'); return; }
          vmApplyState(res);
        }).catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }
      function vmGalRender() {
        var big = document.getElementById('vmGalBig');
        var grid = document.getElementById('vmGalGrid');
        if (!grid) return;
        if (VMPHOTOS.length === 0) {
          if (big) big.innerHTML = '<div class="vm-galempty">No photos yet — click "Add photos".</div>';
          grid.innerHTML = '';
          return;
        }
        if (VMSEL >= VMPHOTOS.length) VMSEL = 0;
        if (big) big.innerHTML = '<img src="' + VMPHOTOS[VMSEL].url + '" style="width:100%;height:100%;object-fit:cover" alt="" />';
        grid.innerHTML = VMPHOTOS.map(function (p, i) {
          return '<button type="button" class="vm-galthumb' + (i === VMSEL ? ' sel' : '') + '" onclick="vmGalSelect(' + i + ')">'
            + '<img src="' + p.url + '" style="width:100%;height:100%;object-fit:cover" alt="" />'
            + (i === VMSEL ? '<span class="vm-galfeat">Cover</span>' : '')
            + '<span onclick="vmGalDelete(' + i + ', event)" style="position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(20,18,15,.75);color:#fff;font-size:13px;line-height:20px;text-align:center;cursor:pointer">&times;</span>'
            + '</button>';
        }).join('');
      }
      function vmCloseGallery() { var ov = document.getElementById('vmGalOverlay'); if (ov) ov.remove(); }

      /* "Save Changes" — photos and the 360° tour already save themselves the
         moment you add/remove/reorder them (see vmApiPost above); EVERYTHING
         else on the form saves here, on create and on edit (2026-10-27), via
         admin/room-save.php — which also says what the change means for
         bookings already made (shown in the confirmation). */
      function vmSaveChanges() {
        /* No room id = CREATE. The screen always offered "Add Room"; it used
           to answer with an alert saying it could not. room-save.php generates
           the room_code (r9, h6) rather than letting anyone type one, because
           that code is also the photo folder on disk. */
        var isNew = !VM_ROOM_ID;
        var name = document.getElementById('roomName').value.trim();
        var venueTrigger = document.querySelector('#roomLoc .vm-dd-trigger');
        var venue = roomFormPage.rfFixedVenue ? document.getElementById('roomLoc').value : (venueTrigger ? venueTrigger.textContent.trim() : '');
        if (!name) { venusepAlert('Enter a room name.'); return; }
        if (!venue || venue === 'Select a venue') { venusepAlert('Choose a location.'); return; }
        var mtv = mtValue();
        if (mtv.on && mtv.until === '') { venusepAlert('Set the maintenance end date, or tick "No set return date".'); return; }

        var body = new FormData();
        body.append('room_id', VM_ROOM_ID || '');
        body.append('name', name);
        body.append('venue', venue);
        /* every detail on the form, on create AND edit (2026-10-27) */
        if (roomFormPage.isHostel) {
        var crTrigger = document.querySelector('#roomCr .vm-dd-trigger');
        body.append('room_type', 'hostel');
        body.append('beds', document.getElementById('roomBeds').value);
        body.append('cr_type', crTrigger && /private/i.test(crTrigger.textContent) ? 'private' : 'communal');
        body.append('rate', document.getElementById('roomRate').value);
        body.append('check_in_time', document.getElementById('roomCheckIn').value);
        body.append('check_out_time', document.getElementById('roomCheckOut').value);
        } else {
        body.append('room_type', 'event');
        if (isNew && window.rfHallStaff) body.append('staff_ids', JSON.stringify(window.rfHallStaff()));
        body.append('capacity', document.getElementById('roomCap').value);
        body.append('fee', document.getElementById('roomRate').value);
        body.append('best_for', document.getElementById('roomBestFor').value);
        body.append('catering', document.getElementById('roomCatering').value);
        body.append('accessibility', document.getElementById('roomAccess').value);
        }
        body.append('description', document.getElementById('roomDesc').value);
        body.append('maintenance', JSON.stringify(mtv));
        /* The ticked amenity KEYS. Always sent — an empty list is a real answer
           (the admin unticked everything), which is why room-save.php
           distinguishes "absent" from "empty". */
        body.append('amenities', JSON.stringify(window.selectedAmenities()));
        body.append('_token', VM_CSRF);
        fetch(roomFormPage.roomSaveUrl, { method: 'POST', body: body, credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (res) {
            if (!res.ok) { venusepAlert(res.message || 'Could not save the room.'); return; }
            /* An edit stays here. A new room has no form of its own yet (its photos need the
               room), so that one still moves on, once OK is clicked: the modal does not block,
               and navigating at once wiped it off the screen. */
            venusepAlert(res.message || 'Room saved.', { tone: 'ok', title: 'Saved' }).then(function () {
              if (!res.created) return;
              location.href = (res.room_id && !roomFormPage.isHostel)
                ? roomFormPage.roomFormUrl + '?id=' + encodeURIComponent(res.room_id)
                : roomFormPage.venueManagementUrl;
            });
          })
          .catch(function (err) { venusepAlert(err && err.message ? err.message : 'Could not reach the server.'); });
      }

      vmLoadPhotos();
    

      (function () {
        var S = function (inner) {
          return '<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>';
        };
        var I = {
          dome: S('<path d="M12 5a7 7 0 0 0-7 7v3h14v-3a7 7 0 0 0-7-7z"/><line x1="12" y1="3" x2="12" y2="5"/><line x1="2" y1="19" x2="22" y2="19"/>'),
          large: S('<polyline points="15 3 21 3 21 9"/><polyline points="9 21 3 21 3 15"/><line x1="21" y1="3" x2="14" y2="10"/><line x1="3" y1="21" x2="10" y2="14"/>'),
          small: S('<polyline points="4 14 10 14 10 20"/><polyline points="20 10 14 10 14 4"/><line x1="14" y1="10" x2="21" y2="3"/><line x1="3" y1="21" x2="10" y2="14"/>'),
          car: S('<path d="M5 17H3v-5l2-5h14l2 5v5h-2"/><circle cx="7.5" cy="17" r="1.5"/><circle cx="16.5" cy="17" r="1.5"/><line x1="5" y1="11" x2="19" y2="11"/>'),
          access: S('<circle cx="16" cy="4" r="1"/><path d="m18 19 1-7-6 1"/><path d="m5 8 3-3 5.5 3-2.36 3.5"/><path d="M4.24 14.5a5 5 0 0 0 6.88 6"/><path d="M13.76 17.5a5 5 0 0 0-6.88-6"/>'),
          wind: S('<path d="M9.59 4.59A2 2 0 1 1 11 8H2"/><path d="M12.59 19.41A2 2 0 1 0 14 16H2"/><path d="M17.73 7.73A2.5 2.5 0 1 1 19.5 12H2"/>'),
          sun: S('<circle cx="12" cy="12" r="4"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>'),
          window: S('<rect x="4" y="3" width="16" height="18" rx="1"/><line x1="12" y1="3" x2="12" y2="21"/><line x1="4" y1="12" x2="20" y2="12"/>'),
          stage: S('<rect x="3" y="4" width="18" height="11" rx="1"/><line x1="12" y1="15" x2="12" y2="19"/><line x1="8" y1="19" x2="16" y2="19"/>'),
          wifi: S('<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>'),
          door: S('<path d="M3 21h18"/><path d="M6 21V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v17"/><circle cx="15" cy="12" r="1"/>'),
          monitor: S('<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>'),
          speaker: S('<rect x="5" y="2" width="14" height="20" rx="2"/><circle cx="12" cy="14" r="4"/><line x1="12" y1="6" x2="12.01" y2="6"/>'),
          mic: S('<path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/>'),
          podium: S('<rect x="7" y="4" width="10" height="7" rx="1"/><path d="M9 21V11h6v10"/><path d="M7 21h10"/><line x1="12" y1="11" x2="12" y2="13"/>'),
          board: S('<rect x="3" y="3" width="18" height="14" rx="1"/><line x1="12" y1="17" x2="12" y2="21"/><line x1="7" y1="21" x2="17" y2="21"/>'),
          chair: S('<path d="M5 19h14"/><path d="M6 19v2"/><path d="M18 19v2"/><path d="M6 15V6a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v9"/><path d="M6 12h12"/>'),
          table: S('<rect x="3" y="7" width="18" height="4" rx="1"/><line x1="6" y1="11" x2="6" y2="18"/><line x1="18" y1="11" x2="18" y2="18"/>'),
          coffee: S('<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/>'),
          power: S('<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>'),
          camera: S('<polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2"/>'),
          box: S('<path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5" rx="1"/><line x1="10" y1="12" x2="14" y2="12"/>'),
        };

        // Hard-coded pools. `on:true` = pre-selected (this is the Edit state).
        /* THE REAL VOCABULARY, grouped, with this room's own selections ticked.
           Built in PHP above from includes/amenities.php. */
        var AMENITY_POOL = roomFormPage.rfPool;

        var CHECK = '<svg class="vm-chip-check" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';

        function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

        /* Each chip carries its KEY. The label is only what a human reads —
           storing labels would mean rewording one silently orphaned every room
           that had it, which is the drift the key-based design exists to stop. */
        function buildAmenities() {
          var el = document.getElementById('vmAmenityPool');
          if (!el) return;
          el.innerHTML = AMENITY_POOL.map(function (g) {
            return '<div class="vm-pool-group" style="grid-column:1/-1;margin:.5rem 0 .15rem;font-size:.7rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#6e6a64">' +
                   esc(g.group) + '</div>' +
                   g.items.map(function (o) {
                     return '<button type="button" class="vm-chip' + (o.on ? ' selected' : '') +
                            '" data-amenity="' + esc(o.key) + '" aria-pressed="' + (o.on ? 'true' : 'false') + '">' +
                            '<span>' + esc(o.label) + '</span>' + CHECK + '</button>';
                   }).join('');
          }).join('');
        }

        /* What the form will send: the keys of every ticked chip, in the order
           the vocabulary lists them, which is the order the customer sees. */
        window.selectedAmenities = function () {
          return Array.prototype.slice
            .call(document.querySelectorAll('#vmAmenityPool .vm-chip.selected'))
            .map(function (c) { return c.getAttribute('data-amenity'); })
            .filter(Boolean);
        };

        buildAmenities();

        // Toggle a chip on click (mockup: just flips the visual selected state).
        document.addEventListener('click', function (e) {
          var chip = e.target.closest ? e.target.closest('.vm-chip') : null;
          if (!chip) return;
          var on = chip.classList.toggle('selected');
          chip.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      })();
    
