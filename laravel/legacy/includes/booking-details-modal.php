<?php
/* =====================================================================
   BOOKING DETAILS MODAL (2026-10-27) — "View" on Transaction History
   (both portals) and the customer's Booking History.

   Include once, anywhere in <body>, on a page ONE folder below the project
   root (customer/ or admin/). Then call:
       openBookingDetails('VB-2026-017')
   It overlays the page (the page behind it blurred), reads the booking from
   booking-details.php (which decides who may see what), and closes on ×,
   Escape or a click outside.

   The valid ID, the uploaded GCash receipt and the System Receipt are LINKS
   inside it: their own pages already serve them, with their own permission
   checks (valid-id-view.php, document-view.php, receipt.php).
   ===================================================================== */
?>
<style>
  .bdm-overlay { position: fixed; inset: 0; z-index: 2500; display: flex; align-items: center; justify-content: center; padding: 18px;
                 background: rgba(20, 14, 12, .42); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); opacity: 0; transition: opacity .16s ease; }
  .bdm-overlay.bdm-in { opacity: 1; }
  .bdm-card { width: 100%; max-width: 580px; max-height: calc(100vh - 36px); overflow: auto; background: #fff; border-radius: 18px;
              box-shadow: 0 28px 80px rgba(20, 10, 10, .3); font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; color: #1f1e1e;
              transform: translateY(8px) scale(.98); transition: transform .18s cubic-bezier(.16,1,.3,1); }
  .bdm-overlay.bdm-in .bdm-card { transform: none; }
  .bdm-head { position: sticky; top: 0; background: #fff; display: flex; align-items: flex-start; gap: 12px; padding: 20px 22px 14px; border-bottom: 1px solid #eee9e3; z-index: 1; }
  .bdm-head h2 { margin: 0; font-size: 18px; font-weight: 750; letter-spacing: -.01em; line-height: 1.25; }
  .bdm-sub { margin-top: 3px; font-size: 12.5px; color: #8a837c; }
  .bdm-x { margin-left: auto; flex: none; width: 34px; height: 34px; border: 1px solid #e3ddd6; border-radius: 10px; background: #fff; font-size: 18px; line-height: 1; cursor: pointer; color: #4a463f; }
  .bdm-x:hover { background: #f6f3ef; }
  .bdm-body { padding: 6px 22px 22px; }
  .bdm-sec { padding: 14px 0; border-bottom: 1px solid #f0ebe5; }
  .bdm-sec:last-child { border-bottom: 0; }
  .bdm-sec h3 { margin: 0 0 9px; font-size: 11.5px; font-weight: 700; letter-spacing: .07em; text-transform: uppercase; color: #a39c94; }
  .bdm-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 18px; }
  .bdm-k { font-size: 11.5px; color: #8a837c; }
  .bdm-v { font-size: 13.5px; font-weight: 600; overflow-wrap: anywhere; }
  .bdm-row { display: flex; justify-content: space-between; gap: 14px; padding: 5px 0; font-size: 13.5px; }
  .bdm-row span:first-child { color: #6b655e; }
  .bdm-row.bdm-total { border-top: 1px solid #eee9e3; margin-top: 4px; padding-top: 9px; font-weight: 750; font-size: 15px; }
  .bdm-badge { display: inline-block; margin-top: 7px; padding: 3px 10px; border-radius: 999px; font-size: 11.5px; font-weight: 650; background: #f2efe9; color: #4a463f; }
  .bdm-note { margin-top: 8px; font-size: 12.5px; color: #8a5a12; background: #fdf7ee; border: 1px solid #f3e3c8; border-radius: 10px; padding: 8px 11px; }
  .bdm-links { display: flex; flex-wrap: wrap; gap: 8px; }
  .bdm-link { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border: 1px solid #e3ddd6; border-radius: 10px; font-size: 12.5px; font-weight: 650; color: #1f1e1e; text-decoration: none; background: #fff; }
  .bdm-link:hover { background: #f6f3ef; border-color: #cfc7bd; }
  .bdm-gcash { display: flex; gap: 12px; align-items: flex-start; }
  .bdm-gcash img { width: 64px; height: 84px; object-fit: cover; border-radius: 8px; border: 1px solid #e3ddd6; flex: none; background: #f4f4f4; }
  .bdm-noimg { width: 64px; height: 84px; flex: none; display: flex; align-items: center; justify-content: center; text-align: center; padding: 4px;
               border: 1px dashed #d8d1c8; border-radius: 8px; font-size: 10.5px; color: #8a837c; }
  .bdm-tl { list-style: none; margin: 0; padding: 0; }
  .bdm-tl li { position: relative; padding: 0 0 11px 16px; font-size: 12.5px; line-height: 1.45; }
  .bdm-tl li::before { content: ''; position: absolute; left: 0; top: 6px; width: 7px; height: 7px; border-radius: 999px; background: #cfc7bd; }
  .bdm-tl li:last-child::before { background: #a11626; }
  .bdm-tl b { display: block; font-size: 13px; }
  .bdm-tl small { color: #8a837c; }
  .bdm-msg { padding: 40px 22px; text-align: center; font-size: 13.5px; color: #6b655e; }
  @media (max-width: 520px) { .bdm-grid { grid-template-columns: 1fr; } .bdm-head, .bdm-body { padding-left: 16px; padding-right: 16px; } }
</style>
<script>
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
      const links = [`<a class="bdm-link" href="../valid-id-view.php?booking=${encodeURIComponent(d.reference)}">Valid ID</a>`];
      if (d.hasReceipt) links.push(`<a class="bdm-link" href="receipt.php?booking=${encodeURIComponent(d.reference)}">System Receipt</a>`);
      return `
        <div class="bdm-head">
          <div>
            <h2 id="bdmTitle">${e(d.title)}</h2>
            <div class="bdm-sub">${e(d.reference)} · ${e(d.type)} · booked ${e(d.bookedOn)}${d.isDemo ? ' · demo booking' : ''}</div>
            <span class="bdm-badge">${e(d.status)}</span>
            ${d.note ? `<div class="bdm-note">${e(d.note)}</div>` : ''}
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
            <div class="bdm-grid" style="margin-top:10px">${kv('Method', m.method)}${kv('Status', m.paymentStatus)}${kv('Pay by', m.payBy)}${kv('Paid on', m.paidOn)}</div>
            ${d.gcash ? `<div class="bdm-gcash" style="margin-top:12px">
                <a href="../valid-id-view.php?receipt=${d.gcash.imageId}" title="Open the uploaded receipt"><img src="../document-view.php?receipt=${d.gcash.imageId}" alt="GCash receipt"
                   onerror="this.closest('a').outerHTML='<span class=&quot;bdm-noimg&quot;>Image not on file</span>'"></a>
                <div class="bdm-grid" style="flex:1">${kv('GCash reference', d.gcash.reference)}${kv('Amount on receipt', d.gcash.amount)}${kv('Receipt check', d.gcash.check)}</div>
              </div>` : ''}
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
      fetch('../booking-details.php?booking=' + encodeURIComponent(ref), { credentials: 'same-origin' })
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
</script>
