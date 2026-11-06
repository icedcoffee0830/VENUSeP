/* Script for legacy/includes/booking-details-modal.php. */
  (function () {
    function e(s) { const d = document.createElement('div'); d.textContent = String(s == null ? '' : s); return d.innerHTML; }
    let overlay = null, lastFocus = null;

    function close() {
      if (!overlay) return;
      document.removeEventListener('keydown', onKey, true);
      overlay.classList.remove('bdm-in');
      const o = overlay; overlay = null;
      setTimeout(function () { o.remove(); }, 170);
      document.body.style.overflow = '';
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }
    function onKey(ev) { if (ev.key === 'Escape') { ev.preventDefault(); close(); } }

    function render(d) {
      const kv = (k, v) => v ? `<div><div class="bdm-k">${e(k)}</div><div class="bdm-v">${e(v)}</div></div>` : '';
      const m = d.money;
      const when = d.when.map(w => kv(w.label, w.value)).join('');
      /* same tab (2026-10-28): each page has its own Back */
      const links = d.idRemovedOn ? [] : [`<a class="bdm-link" href="${bookingDetailsModal.validIdViewUrl}?booking=${encodeURIComponent(d.reference)}">Valid ID</a>`];
      if (d.hasReceipt) links.push(`<a class="bdm-link" href="${bookingDetailsModal.bdmReceiptUrl}?booking=${encodeURIComponent(d.reference)}">Transaction Receipt</a>`);
      const p = d.pay;   /* customers only: Pay now, or why not yet */
      const pay = p ? `<div class="bdm-pay">${p.note ? `<div class="bdm-note">${e(p.note)}</div>` : ''}${p.url ? `<a class="bdm-link${p.primary ? ' bdm-link-pay' : ''}" href="${e(p.url)}">${e(p.label)}</a>` : ''}</div>` : '';
      return `
        <div class="bdm-head">
          <div>
            <h2 id="bdmTitle">${e(d.title)}</h2>
            <div class="bdm-sub">${e(d.reference)} · ${e(d.type)} · booked ${e(d.bookedOn)}</div>
            <span class="bdm-badge">${e(d.status)}</span>
            ${d.note ? `<div class="bdm-note">${e(d.note)}</div>` : ''}
            ${d.idRemovedOn ? `<div class="bdm-note">Your ID was removed on ${e(d.idRemovedOn)}: all processes for this booking are finished.</div>` : ''}
          </div>
          <button type="button" class="bdm-x" aria-label="Close" data-bdm-close>&times;</button>
        </div>
        <div class="bdm-body">
          <div class="bdm-sec"><h3>Where &amp; when</h3>
            <div class="bdm-grid">${kv('Room', d.room)}${kv('Venue', d.venue)}${when}${d.attendees ? kv('Attendees', String(d.attendees)) : ''}</div>
            ${d.guests.length ? `<div style="margin-top:10px"><div class="bdm-k">Guests</div><div class="bdm-v" style="font-weight:500">${d.guests.map(e).join('<br>')}</div></div>` : ''}
          </div>
          <div class="bdm-sec"><h3>Booked by</h3>
            <div class="bdm-grid">${kv('Name', d.customer.name)}${kv('Type', d.customer.type)}${kv('Email', d.customer.email)}${kv('Phone', d.customer.phone)}</div>
          </div>
          <div class="bdm-sec"><h3>Payment</h3>
            <div class="bdm-row"><span>Room price</span><span>${e(m.roomPrice)}</span></div>
            ${m.discount ? `<div class="bdm-row"><span>USeP discount (${e(m.discountPercent)}%)</span><span>${e(m.discount)}</span></div>` : ''}
            <div class="bdm-row bdm-total"><span>Total</span><span>${e(m.total)}</span></div>
            ${m.discountNote ? `<div class="bdm-note">${e(m.discountNote)}</div>` : ''}
            <div class="bdm-grid" style="margin-top:10px">${kv('Method', m.method)}${kv('Status', m.paymentStatus)}${kv('Pay by', m.payBy)}${kv('Overdue after', m.overdueAfter)}${kv('Paid on', m.paidOn)}</div>
            ${d.gcash ? `<div class="bdm-gcash" style="margin-top:12px">
                <a href="${bookingDetailsModal.validIdViewUrl}?receipt=${d.gcash.imageId}" title="Open the uploaded receipt"><img src="${bookingDetailsModal.documentViewUrl}?receipt=${d.gcash.imageId}" alt="GCash receipt"
                   onerror="this.closest('a').outerHTML='<span class=&quot;bdm-noimg&quot;>Image not on file</span>'"></a>
                <div class="bdm-grid" style="flex:1">${kv('GCash reference', d.gcash.reference)}${kv('Amount on receipt', d.gcash.amount)}${kv('Receipt check', d.gcash.check)}</div>
              </div>` : ''}
            ${pay}
          </div>
          <div class="bdm-sec"><h3>Documents</h3><div class="bdm-links">${links.join('')}</div></div>
          ${d.timeline.length ? `<div class="bdm-sec"><h3>History</h3><ul class="bdm-tl">${d.timeline.map(t => `<li><b>${e(t.x)}</b>${t.m ? e(t.m) + '<br>' : ''}<small>${e(t.w)}</small></li>`).join('')}</ul></div>` : ''}
        </div>`;
    }

    window.openBookingDetails = function (ref) {
      try { ref = decodeURIComponent(ref); } catch (err) { /* already plain */ }   // callers pass it URL-safe
      close();
      lastFocus = document.activeElement;
      overlay = document.createElement('div');
      overlay.className = 'bdm-overlay';
      overlay.innerHTML = '<div class="bdm-card" role="dialog" aria-modal="true" aria-labelledby="bdmTitle"><div class="bdm-msg">Loading the booking…</div></div>';
      document.body.appendChild(overlay);
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(function () { overlay && overlay.classList.add('bdm-in'); });
      document.addEventListener('keydown', onKey, true);
      overlay.addEventListener('mousedown', function (ev) { if (ev.target === overlay) close(); });
      overlay.addEventListener('click', function (ev) { if (ev.target.closest('[data-bdm-close]')) close(); });
      const mine = overlay;
      fetch(bookingDetailsModal.bookingDetailsUrl + encodeURIComponent(ref), { credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; }); })
        .then(function (res) {
          if (overlay !== mine) return;                       // closed meanwhile
          const card = mine.querySelector('.bdm-card');
          card.innerHTML = res.ok ? render(res.details)
            : '<div class="bdm-head"><h2 id="bdmTitle">Booking details</h2><button type="button" class="bdm-x" aria-label="Close" data-bdm-close>&times;</button></div><div class="bdm-msg">' + e(res.message || 'That booking could not be shown.') + '</div>';
          const x = card.querySelector('[data-bdm-close]'); if (x) x.focus();
        })
        .catch(function () {
          if (overlay !== mine) return;
          mine.querySelector('.bdm-card').innerHTML = '<div class="bdm-msg">Could not reach the server. <button type="button" class="bdm-x" style="margin:12px auto 0;display:block;width:auto;padding:0 12px" data-bdm-close>Close</button></div>';
        });
    };

    /* ?view=<reference> opens that booking straight away — where a customer's
       notification leads (includes/notifications.php, 2026-10-28). The
       parameter is then dropped from the address, so coming BACK to this page
       does not open the booking again. */
    const params = new URLSearchParams(location.search);
    const viewRef = params.get('view');
    if (viewRef) {
      const openIt = function () { window.openBookingDetails(viewRef); };
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', openIt); else openIt();
      params.delete('view');
      const rest = params.toString();
      try { history.replaceState(history.state, '', location.pathname + (rest ? '?' + rest : '') + location.hash); } catch (err) { /* keep the address */ }
    }

    /* Leaving the page (a receipt or the ID opens in the same tab) closes the
       window at once, and so does coming back to a copy the browser kept: Back
       should land on the page, not on the booking that was open. */
    function closeNow() {
      if (!overlay) return;
      document.removeEventListener('keydown', onKey, true);
      overlay.remove();
      overlay = null;
      document.body.style.overflow = '';
    }
    window.addEventListener('pagehide', closeNow);
    window.addEventListener('pageshow', function (ev) { if (ev.persisted) closeNow(); });
  })();
