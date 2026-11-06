/* Script for resources/views/admin/payment-settings.blade.php. */
      (function () {
        const CSRF = paymentSettingsPage.csrf;
        let lockUntil = Date.now() + paymentSettingsPage.rsLockSeconds * 1000;
        let timer = null, busy = false;
        let office = null, openBtn = null;   /* the office whose button opened the window (data-* on it) */

        const modal = document.getElementById('rsModal');
        const title = document.getElementById('rsModalTitle');
        const list = document.getElementById('rsModalList');
        const form = document.getElementById('rsForm');
        const pw = document.getElementById('rsPassword');
        pw.insertAdjacentHTML('afterend', venusepEye);
        const hidePw = function () { if (pw.type !== 'password') pw.nextElementSibling.click(); };
        const msg = document.getElementById('rsMsg');
        const confirmBtn = document.getElementById('rsConfirm');

        const plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
        /* Per office, counted server-side: refund requests still open (finished by staff even
           after the switch goes off, DB-DECISIONS #16: a warning, not a blocker) and bookings
           made while it was ON, which keep their refund right. */

        function show(kind, text) { msg.className = 'rs-msg ' + (kind === 'lock' ? 'rs-msg-lock' : 'rs-msg-bad'); msg.textContent = text; msg.hidden = false; }
        function hideMsg() { msg.hidden = true; }

        function tick() {
          const left = Math.ceil((lockUntil - Date.now()) / 1000);
          if (left > 0) {
            pw.disabled = true; confirmBtn.disabled = true;
            show('lock', 'Too many wrong passwords. Try again in ' + (left >= 60 ? Math.floor(left / 60) + 'm ' + (left % 60) + 's' : left + 's') + '.');
            return;
          }
          clearInterval(timer); timer = null;
          pw.disabled = false; confirmBtn.disabled = false; hideMsg();
          if (!modal.hidden) pw.focus();
        }
        function startCountdown(seconds) {
          lockUntil = Date.now() + seconds * 1000;
          if (!timer) timer = setInterval(tick, 250);
          tick();
        }

        function open(btn) {
          openBtn = btn;
          office = { id: btn.dataset.venue, name: btn.dataset.name, on: btn.dataset.on === '1', hostel: btn.dataset.hostel === '1',
                     stay: +btn.dataset.stay, open: +btn.dataset.open, grace: +btn.dataset.grace };
          const turningOn = !office.on;
          const at = ' at <strong>' + office.name.replace(/[&<>"]/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }) + '</strong>';
          title.textContent = (turningOn ? 'Enable refund requests at ' : 'Disable refund requests at ') + office.name + '?';
          confirmBtn.textContent = turningOn ? 'Enable refunds' : 'Disable refunds';
          const items = turningOn ? [
            'Customers will be able to request refunds on bookings made' + at + ' <strong>from now on</strong>. The other offices do not change.',
            'Bookings made while refunds were OFF <strong>stay non-refundable</strong>, because their customers agreed to that.',
            'The landing page, FAQ and checkout will stop saying this office\'s bookings are non-refundable.',
            office.hostel
              ? 'Payment does not change: hostel stays are always paid before the stay.'
              : 'New bookings switch to <strong>pre-pay</strong>: paid after approval, by the day before the event; unpaid holds are released.',
            '<strong>Only enable this if the University\'s policy officially allows refunds.</strong>'
          ] : [
            'Customers <strong>can no longer request refunds</strong> on new bookings' + at + ', and must confirm a booking is non-refundable before making it.',
            '<strong>' + plural(office.open, 'open refund request is', 'open refund requests are') + '</strong> still waiting. Staff can still finish them.',
            '<strong>' + plural(office.stay, 'booking', 'bookings') + '</strong> made while refunds were ON <strong>' + (office.stay === 1 ? 'stays' : 'stay') + ' refundable</strong>, because their customers were promised that.',
            office.hostel
              ? 'Payment does not change: hostel stays are always paid before the stay.'
              : 'New bookings switch to <strong>post-pay</strong>: nothing is paid until the event is over, then it is due the day after the event. Unpaid bookings go overdue after ' + office.grace + ' days instead of being released.',
            'With refunds OFF, nothing is refundable through VENUSeP, even when the office closes. Staff handle those cases outside the system.'
          ];
          list.innerHTML = items.map(function (t) { return '<li>' + t + '</li>'; }).join('');
          pw.value = ''; hideMsg();
          modal.hidden = false;
          if (lockUntil > Date.now()) startCountdown(Math.ceil((lockUntil - Date.now()) / 1000));
          else pw.focus();
        }
        function close() {
          if (busy) return;
          modal.hidden = true; pw.value = ''; hidePw(); hideMsg();
          if (openBtn) openBtn.focus();
        }

        /* on the document: the card and its buttons are redrawn after a change */
        document.addEventListener('click', function (e) { const b = e.target.closest('[data-rs-open]'); if (b) open(b); });
        document.getElementById('rsCancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

        form.addEventListener('submit', function (e) {
          e.preventDefault();
          if (busy || lockUntil > Date.now()) return;
          if (!pw.value) { show('bad', 'Enter your admin password to confirm.'); pw.focus(); return; }

          const body = new FormData();
          body.append('_token', CSRF);
          body.append('venue_id', office.id);
          body.append('enable', office.on ? '0' : '1');
          body.append('password', pw.value);

          busy = true; confirmBtn.disabled = true; pw.disabled = true; hideMsg();
          fetch(paymentSettingsPage.refundSwitchUrl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server.' }; }); })
            .then(function (res) {
              busy = false;
              if (res.ok) {
                /* the card again, with its Saved note; the address carries the note, as a page load did */
                const url = paymentSettingsPage.refundsSavedUrl + '&office=' + encodeURIComponent(office.id);
                const id = office.id;
                confirmBtn.disabled = false; pw.disabled = false;
                history.replaceState(null, '', url);
                close();
                Live.refresh(url).then(function () { document.querySelector('[data-rs-open][data-venue="' + id + '"]').focus(); });
                return;
              }
              pw.value = '';
              if (res.error === 'locked') { startCountdown(res.seconds || 10); return; }
              confirmBtn.disabled = false; pw.disabled = false;
              if (res.error === 'wrong_password') {
                show('bad', 'Wrong password. ' + plural(res.attemptsLeft, 'attempt', 'attempts') + ' left before a temporary lock.');
              } else if (res.error === 'not_logged_in') {
                show('bad', res.message); setTimeout(function () { location.href = paymentSettingsPage.adminLoginUrl; }, 1500);
              } else {
                show('bad', res.message || 'The setting was not changed.');
              }
              pw.focus();
            })
            .catch(function () {
              busy = false; confirmBtn.disabled = false; pw.disabled = false;
              show('bad', 'Could not reach the server. The setting was not changed.');
            });
        });
      })();
    
