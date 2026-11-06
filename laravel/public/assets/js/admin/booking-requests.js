/* Script for resources/views/admin/booking-requests.blade.php. */
      /* Booking Requests queue — static mockup data + client-side filtering.
         Statuses follow the agreed payment-status taxonomy:
         reservation status and payment status are SEPARATE badges. */
      /* Every reservation_statuses code, because the queue now renders whatever
         the database holds — an unmapped code used to crash the row builder. */
      /* Every status the database knows comes first (status_badge_maps), so a new
         status can never blank the list; the hand-written labels below win. */
      const RES = Object.assign(bookingRequestsPage.reservationBadges, {
        pending:   { t: 'Pending review', c: 'b-amber' },
        approved:  { t: 'Approved',       c: 'b-green' },
        completed: { t: 'Completed',      c: 'b-navy'  },
        released:  { t: 'Released',       c: 'b-gray'  },
        rejected:  { t: 'Rejected',       c: 'b-red'   },
        cancelled: { t: 'Cancelled',      c: 'b-gray'  },
        /* USeP closed the room while this booking was inside the closure. The
           customer is owed a replacement, a new date, or a non-deniable refund. */
        disrupted: { t: 'Room closed · action needed', c: 'b-red' },
      });
      const PAY = Object.assign(bookingRequestsPage.paymentBadges, {
        locked:      { t: 'Payment locked',              c: 'b-gray'  },
        written_off: { t: 'Written off',                 c: 'b-gray'  },
        await_gcash: { t: 'Awaiting payment · GCash',    c: 'b-amber' },
        await_cash:  { t: 'Awaiting payment · Cash',     c: 'b-amber' },
        auto_pass:   { t: 'Receipt passed auto-check',   c: 'b-navy'  },
        review:      { t: 'Receipt · manual review',     c: 'b-amber' },
        rejected:    { t: 'Receipt rejected',          c: 'b-red'   },
        confirmed:   { t: 'Payment confirmed',           c: 'b-green' },
        paid_cash:   { t: 'Paid at cashier',             c: 'b-green' },
        overdue:     { t: 'Payment overdue',             c: 'b-red'   },
        /* POST-PAY (DB-DECISIONS #18): approved while refunds were OFF, so nothing
           can be paid until the event is over. Not waiting on anyone. */
        await_event: { t: 'Payment due after event',     c: 'b-gray'  },
        refund_req:  { t: 'Refund · under verification', c: 'b-navy'  },
        /* Filed, but the Official Receipt has not arrived. The claim can still be
           decided; only the PAYOUT waits (agreed 2026-09-09). */
        refund_or:   { t: 'Refund · awaiting Official Receipt', c: 'b-amber' },
        refund_fix:  { t: 'Refund · returned for correction',   c: 'b-amber' },
        /* HOSTEL ONLY — waiting on CEDU, not on the customer. */
        await_pos:   { t: 'Awaiting POS · CEDU',         c: 'b-amber' },
        /* The rest of the payment_statuses taxonomy. The mockup only listed the
           states its invented rows happened to use; the database can produce
           every one, and a missing key here renders an empty badge. */
        expired:          { t: 'Expired',                       c: 'b-gray'  },
        under_review:     { t: 'Receipt · manual review',       c: 'b-amber' },
        refund_requested: { t: 'Refund · under verification',   c: 'b-navy'  },
        refund_correction:{ t: 'Refund · returned for correction', c: 'b-amber' },
        refund_await_or:  { t: 'Refund · awaiting Official Receipt', c: 'b-amber' },
        refund_processing:{ t: 'Refund · processing',           c: 'b-navy'  },
        refunded:         { t: 'Refunded',                      c: 'b-gray'  },
        refund_denied:    { t: 'Refund denied',                 c: 'b-red'   },
      });
      /* A code neither list knows shows its own name: one odd row must never blank the page. */
      function resBadge(code) { return RES[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }
      function payBadge(code) { return PAY[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }
      /* THE QUEUE — real `bookings` rows, shaped in PHP above. This was a
         hand-written array of 15 invented requests; it is now whatever the
         database actually holds, so the customer side and this page can never
         again disagree about which bookings exist. */
      let BR = Live.data('br-rows');
      const TABS = [
        { k: 'all',     t: 'All' },
        { k: 'id',      t: 'Pending ID review' },
        { k: 'confirm', t: 'Receipts to confirm' },
        { k: 'review',  t: 'Manual review' },
        /* Payment still awaited and not yet late (overdue has its own tab). Matched on the
           payment status, not the row's one category, so a POS-to-fetch stay shows in both. */
        { k: 'awaiting', t: 'Awaiting payment' },
        { k: 'overdue', t: 'Overdue' },
        /* Refunds wait on STAFF, not on the customer — same argument as POS below.
           Without a tab a refund request only ever appears under "All", mixed in
           with rows that need nobody. */
        { k: 'refund',  t: 'Refunds to verify' },
        /* "Waiting on another office" is genuinely new — every other tab here
           waits on the customer. It earns a tab because it is staff WORK: someone
           has to physically walk to CEDU, and nothing else would surface it. */
        { k: 'pos',     t: 'POS to fetch' },
      ];
      /* deep-link support: the dashboard cards link here as ?tab=id / confirm /
         review / overdue — open with that tab already active */
      const PARAMS = new URLSearchParams(location.search);
      const TAB_PARAM = PARAMS.get('tab');
      let tab = TABS.some((t) => t.k === TAB_PARAM) ? TAB_PARAM : 'all', q = (PARAMS.get('q') || '').trim().toLowerCase();
      document.getElementById('brSearch').value = PARAMS.get('q') || '';

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      const AWAITING = ['await_gcash', 'await_cash', 'await_pos'];
      function inTab(r, k) { return k === 'all' || (k === 'awaiting' ? AWAITING.includes(r.pay) : r.cat === k); }
      function countOf(k) { return BR.filter(r => inTab(r, k)).length; }
      function rowsOf() {
        const list = BR.filter(r => inTab(r, tab))
                       .filter(r => !q || (r.name + ' ' + r.room + ' ' + r.id).toLowerCase().includes(q));
        /* Newest request first (seq = the booking's id). The refunds tab keeps its
           soonest-event-first order: a near event is a date nobody can rebook. */
        return list.sort((a, b) => (tab === 'refund' && a.eventIso && b.eventIso && a.eventIso !== b.eventIso)
          ? (a.eventIso < b.eventIso ? -1 : 1)
          : b.seq - a.seq);
      }
      /* Paging state. Changing the tab or the search changes WHICH rows exist,
         so both send you back to page 1 — staying on page 4 of a list that now
         has two pages reads as an empty queue. */
      let page = 1, pageSize = 10;
      /* The address keeps the tab and the search, so Back, a refresh and a link all land here again. */
      function keepInAddress() {
        const to = new URL(location.href);
        const typed = document.getElementById('brSearch').value.trim();
        if (tab === 'all') to.searchParams.delete('tab'); else to.searchParams.set('tab', tab);
        if (typed) to.searchParams.set('q', typed); else to.searchParams.delete('q');
        history.replaceState(null, '', to);
      }
      function setTab(k) { tab = k; page = 1; keepInAddress(); draw(); }
      function setQ(v) { q = v.trim().toLowerCase(); page = 1; keepInAddress(); drawRows(); }
      function setPage(n) { page = n; drawRows(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
      function setPageSize(n) { pageSize = parseInt(n, 10) || 10; page = 1; drawRows(); }

      /* How long the customer has been waiting on STAFF — measured from the day
         they filed. Worded so it is obvious who owes whom: "waiting 3 days" never
         said who was waiting or for what. COMPUTED, never hard-coded, so the queue
         stays truthful as days pass instead of ageing into a lie. */
      function waitedFor(iso) {
        const days = Math.floor((Date.now() - new Date(iso + 'T00:00:00')) / 86400000);
        if (days <= 0) return 'filed today · no staff reply yet';
        if (days === 1) return 'filed yesterday · no staff reply for 1 day';
        return 'filed ' + new Date(iso + 'T00:00:00').toLocaleDateString('en-US', { month: 'short', day: 'numeric' })
             + ' · no staff reply for ' + days + ' days';
      }
      /* Rows carrying `since` show their age next to the action text. */
      /* How soon the event is. This matters MORE than waiting time for refunds:
         the booking stays live until the refund completes, so a near event means
         a date nobody can rebook. Refund rows are ordered by it in rowsOf(). */
      function eventIn(iso) {
        const days = Math.ceil((new Date(iso + 'T00:00:00') - Date.now()) / 86400000);
        if (days <= 0) return 'event has passed';
        return days === 1 ? 'event tomorrow' : 'event in ' + days + ' days';
      }
      function actLabel(r) {
        let s = r.act;
        if (r.since) s += ' · ' + waitedFor(r.since);
        if (r.eventIso) s += ' · ' + eventIn(r.eventIso);
        return s;
      }

      /* First / Prev / a window of page numbers / Next / Last — the same set
         Tabulator renders under the Transaction History table. The window is
         five wide so 16 pages do not produce 16 buttons. */
      function drawPager(total) {
        const foot = document.getElementById('brFoot');
        const pages = Math.max(1, Math.ceil(total / pageSize));
        foot.hidden = total === 0;
        if (total === 0) { return; }

        const from = (page - 1) * pageSize + 1;
        const to = Math.min(page * pageSize, total);
        document.getElementById('brShown').textContent = `Showing ${from}–${to} of ${total}`;

        let first = Math.max(1, page - 2);
        const last = Math.min(pages, first + 4);
        first = Math.max(1, last - 4);

        const btn = (label, target, opts) => {
          const o = opts || {};
          return `<button type="button" class="br-pbtn${o.active ? ' active' : ''}"${o.disabled ? ' disabled' : ''} onclick="setPage(${target})">${label}</button>`;
        };
        let html = btn('First', 1, { disabled: page === 1 }) + btn('Prev', page - 1, { disabled: page === 1 });
        for (let p = first; p <= last; p++) { html += btn(p, p, { active: p === page }); }
        html += btn('Next', page + 1, { disabled: page === pages }) + btn('Last', pages, { disabled: page === pages });
        document.getElementById('brPages').innerHTML = html;
      }

      function drawRows() {
        const all = rowsOf();
        /* Deleting or filtering can leave the current page past the end — land
           on the last real page instead of showing nothing. */
        const pages = Math.max(1, Math.ceil(all.length / pageSize));
        if (page > pages) { page = pages; }
        if (page < 1) { page = 1; }
        document.getElementById('brRows').innerHTML = all.slice((page - 1) * pageSize, page * pageSize).map(r => `
          <a class="br-row" href="${bookingRequestsPage.bookingRequestUrl}?id=${r.id}">
            <span><div class="r-id">${r.id}</div><div class="r-name">${esc(r.name)}${r.blocked ? ' <span class="br-badge b-red">Blocked</span>' : ''}</div><div class="r-sub">${esc(r.type)}${r.office ? ' · ' + esc(r.office) : ''}</div></span>
            <span class="hide-md"><div class="r-main">${esc(r.room)}</div><div class="r-sub">${esc(r.venue)}</div></span>
            <span class="hide-md r-main">${esc(r.dates)}</span>
            <span class="hide-md"><span class="br-badge ${resBadge(r.res).c}">${esc(resBadge(r.res).t)}</span></span>
            <span><span class="br-badge ${payBadge(r.pay).c}">${r.pay === 'paid_cash' ? esc(r.payLabel) : esc(payBadge(r.pay).t)}</span></span>
            <span class="hide-md r-act ${r.cls}">${esc(actLabel(r))}</span>
            <span class="r-chev">&rsaquo;</span>
          </a>`).join('') || '<div style="padding:1.2rem;color:#6e6a64;font-size:.85rem">No requests match.</div>';
        drawPager(all.length);
      }
      function draw() {
        document.getElementById('brTabBtns').innerHTML = TABS.map(t => `
          <button type="button" class="br-tab${tab === t.k ? ' active' : ''}" onclick="setTab('${t.k}')">${t.t}<span class="n">${countOf(t.k)}</span></button>`).join('');
        const need = BR.filter(r => ['id', 'confirm', 'review', 'overdue', 'refund', 'pos'].includes(r.cat)).length;
        document.getElementById('brCount').textContent = BR.length + ' booking requests · ' + need + ' need action';
        drawRows();
      }
      /* A refund the customer filed used to reach this queue through
         includes/refund-store.php — browser storage, shared between two
         mockups, invisible on any other machine. A refund is now a `refunds`
         row, so it arrives here the same way every other booking does: it is
         simply in BR above. The injection that used to live here is gone. */
      document.getElementById('brPageSize').addEventListener('change', function () { setPageSize(this.value); });
      /* Opening a row: the booking page reads this for its back link, its place and Next. */
      document.getElementById('brRows').addEventListener('click', (e) => {
        if (!e.target.closest('a.br-row')) return;
        const list = { tab: tab, label: TABS.find((t) => t.k === tab).t, url: location.href, ids: rowsOf().map((r) => r.id) };
        try { sessionStorage.setItem('venusep_queue', JSON.stringify(list)); } catch (err) {}
      });
      draw();
      /* the 30-second check: fresh rows under the same tab, search and page */
      Live.watch('queue', () => Live.refresh().then(() => { BR = Live.data('br-rows'); draw(); }));
    
