<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* THE QUEUE, from the same `bookings` rows the customer's own history reads.
   The row builder lives in includes/bookings.php so this page and the
   dashboard's "needs action" preview cannot disagree about how much work is
   waiting — they were separate hand-written arrays and had already drifted. */
require_once __DIR__ . '/../includes/bookings.php';
$brRows = booking_queue_rows();
?>
<!DOCTYPE html>
<!-- ==================================================================
  BOOKING REQUESTS — VENUSeP merged system (ported from the AdminLTE mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  the request queue (tabs, search, rows built by script)
    [6] PAGE SCRIPT   demo data + row builder + tab/search filtering

  (No [5]: the stock AdminLTE library scripts were removed in this port —
  the team shell needs no JS.)

  [SIM] now marks only what is still deliberately simulated: the demo
  advance buttons, which stand in for another person, another office or
  the passage of time. The data is real.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Booking Requests</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- ============================================================
         [0] SHELL CSS — the TEAM header bar + sidebar + layout
         (adapted from venusep_profile.php / sidebar.php so this page
         needs no AdminLTE stylesheet). Same block on every page.
         ============================================================ -->
    <style>
      /* layout */
      :root {
        --venusep-black: #1f1e1e;
        --venusep-cream: #ffffff;
        --venusep-border: #ddd7ce;
        --venusep-text: #050505;
        --venusep-sidebar-width: 235px;
        --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body {
        background: #fff;
        color: var(--venusep-text);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        overflow-x: hidden;
      }
      /* (header + sidebar styles live in ../includes/ — the shared includes) */
      /* main content area sits right of the sidebar, below the header */
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 1.3rem 0 0; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .user-name { display: none; }
      }
    </style>

    <!-- ============================================================
         [1] PAGE CSS — every style this page needs, kept inline so
         the file is self-contained. Grouped: badges / tabs + search /
         queue list (see the /* … */ labels below).
         ============================================================ -->
    <style>
      /*
        Booking Requests — staff queue (mockup)
        Self-contained page CSS following the team redesign style.
      */
      :root {
        --vm-border: #e5e5e5;
        --vm-text: #1f1e1e;
        --vm-muted: #606a75;
        --vm-hover: #ebebeb;
        --vm-strip: #f4f4f4;
        --vm-dark: #1f1e1e;
        --vm-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        --vm-shadow: 0 1px 2px rgba(15, 23, 42, 0.05);
      }

      .app-main { background: #ffffff; font-family: var(--vm-font); }

      .br-page { color: var(--vm-text); padding: 22px 0 0; width: 100%; } /* 22px + 4px from .app-content = 26px top, sides owned by .container-fluid */
      .br-title { font-size: 1.45rem; font-weight: 700; letter-spacing: -0.01em; margin: 0 0 0.2rem; }
      .br-subtitle { color: var(--vm-muted); font-size: 0.86rem; margin: 0 0 1.3rem; }

      /* status badges — dot style, matches booking-request.php */
      .br-badge { border: 1px solid transparent; border-radius: 999px; display: inline-flex; align-items: center; gap: 0.38rem; font-size: 0.72rem; font-weight: 400; padding: 0.28rem 0.66rem; white-space: nowrap; }
      .br-badge::before { border-radius: 999px; content: ''; height: 6px; width: 6px; }
      .b-gray  { background: #ffffff; border-color: var(--vm-border); color: #6b675f; }
      .b-gray::before  { background: #b9b5ad; }
      .b-green { background: #eaf6ef; color: #1c7a4f; }
      .b-green::before { background: #2f9e63; }
      .b-amber { background: #fdf3e6; color: #8a5a12; }
      .b-amber::before { background: #d9930d; }
      .b-red   { background: #fcecec; color: #b23a3a; }
      .b-red::before   { background: #cf4a4a; }
      .b-navy  { background: #eef1f8; color: #1f2a44; }
      .b-navy::before  { background: #1f2a44; }

      /* needs-action tabs + search */
      .br-toolbar { align-items: center; display: flex; flex-wrap: wrap; gap: 0.55rem; margin-bottom: 1rem; }
      .br-tab { background: #ffffff; border: 1px solid var(--vm-border); border-radius: 999px; color: var(--vm-muted); cursor: pointer; font-family: inherit; font-size: 0.8rem; font-weight: 600; padding: 0.42rem 0.85rem; }
      .br-tab:hover { background: var(--vm-hover); }
      .br-tab .n { background: var(--vm-strip); border-radius: 999px; font-size: 0.7rem; margin-left: 0.35rem; padding: 0.05rem 0.42rem; }
      .br-tab.active { background: var(--vm-dark); border-color: var(--vm-dark); color: #ffffff; }
      .br-tab.active .n { background: rgba(255, 255, 255, 0.18); color: #ffffff; }
      .br-search { border: 1px solid var(--vm-border); border-radius: 9px; font-family: inherit; font-size: 0.84rem; height: 38px; margin-left: auto; padding: 0 0.8rem; width: 260px; }
      .br-search:focus { outline: 2px solid rgba(31, 30, 30, 0.25); }

      /* the queue list */
      .br-card { background: #ffffff; border: 1px solid var(--vm-border); border-radius: 14px; box-shadow: var(--vm-shadow); }
      .br-list { overflow: hidden; }
      .br-hd, .br-row { align-items: center; display: grid; gap: 0.9rem; grid-template-columns: 1.9fr 1.5fr 1.1fr 1.1fr 1.55fr 1.5fr 22px; padding: 0.75rem 1.1rem; }
      .br-hd { border-bottom: 1px solid var(--vm-border); color: var(--vm-muted); font-size: 0.68rem; font-weight: 600; letter-spacing: 0.05em; text-transform: uppercase; }
      .br-row { border-bottom: 1px solid #f0efec; color: inherit; cursor: pointer; text-decoration: none; }
      .br-row:hover { background: #fafaf9; }
      .br-row:last-child { border-bottom: none; }
      .r-id { color: var(--vm-muted); font-size: 0.72rem; }
      .r-name { font-size: 0.88rem; font-weight: 600; }
      .r-sub { color: var(--vm-muted); font-size: 0.75rem; margin-top: 0.1rem; }
      .r-main { font-size: 0.84rem; font-weight: 500; }
      .r-act { color: var(--vm-muted); font-size: 0.76rem; line-height: 1.4; }
      .r-act.warn { color: #8a5a12; font-weight: 600; }
      .r-act.late { color: #b23a3a; font-weight: 600; }
      .r-chev { color: #c9c5bd; font-size: 1.05rem; }

      /* pagination — deliberately the same control as the Transaction History
         table (Tabulator's footer), down to the page-size choices and the
         button styling. The queue is a list of links rather than a table, so
         it cannot BE that table, but it should not behave like a different
         product either. 157 rows on one endless page was the complaint. */
      .br-foot { align-items: center; background: #faf9f7; border-top: 1px solid var(--vm-border); display: flex; flex-wrap: wrap; gap: 0.6rem; justify-content: space-between; padding: 0.6rem 1.1rem; }
      .br-foot[hidden] { display: none; }
      .br-pagesize { align-items: center; color: var(--vm-muted); display: flex; font-size: 0.76rem; gap: 0.4rem; }
      .br-pagesize select { border: 1px solid #d7d7d7; border-radius: 7px; background: #fff; color: #1f1e1e; font-family: inherit; font-size: 0.78rem; padding: 0.2rem 0.4rem; }
      .br-pages { display: flex; flex-wrap: wrap; gap: 0.25rem; }
      .br-pbtn { background: #fff; border: 1px solid #d7d7d7; border-radius: 7px; color: #1f1e1e; cursor: pointer; font-family: inherit; font-size: 0.76rem; min-width: 30px; padding: 0.25rem 0.5rem; }
      .br-pbtn:hover:not(:disabled) { background: #f4f2ee; }
      .br-pbtn.active { background: var(--vm-dark); border-color: var(--vm-dark); color: #fff; }
      .br-pbtn:disabled { cursor: not-allowed; opacity: 0.45; }
      .br-shown { color: var(--vm-muted); font-size: 0.76rem; }

      @media (max-width: 1000px) {
        .br-hd, .br-row { grid-template-columns: 1.9fr 1.55fr 22px; }
        .hide-md { display: none; }
        .br-search { margin-left: 0; width: 100%; }
      }
    </style>
  </head>
  <body>
    <div class="app-wrapper">
            <!-- ==========================================================
           [2] HEADER BAR — the ONE shared top bar, included from
           ../includes/header.php. Edit it THERE and every page updates.
           ========================================================== -->
      <?php include __DIR__ . '/../includes/header.php'; ?>

            <!-- ==========================================================
           [3] SIDEBAR — the ONE shared sidebar, included from
           ../includes/sidebar.php ($active = the highlighted item).
           Edit the menu THERE and every page updates.
           ========================================================== -->
      <?php $active = 'Booking Management'; include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- ==========================================================
           [4] PAGE CONTENT — title, the needs-action tabs (All /
           Pending ID / Receipts to confirm / Manual review / Overdue),
           the search box, and the queue list container. The rows
           themselves are generated by the PAGE SCRIPT [6] below.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="br-page">
              <h1 class="br-title">Booking Requests</h1>
              <p class="br-subtitle" id="brCount"></p>

              <div class="br-toolbar" id="brTabs"></div>

              <div class="br-card br-list">
                <div class="br-hd">
                  <span>Request · Customer</span>
                  <span class="hide-md">Room</span>
                  <span class="hide-md">Event date(s)</span>
                  <span class="hide-md">Reservation</span>
                  <span>Payment</span>
                  <span class="hide-md">Next action</span>
                  <span></span>
                </div>
                <div id="brRows"></div>
                <div class="br-foot" id="brFoot" hidden>
                  <label class="br-pagesize">Page Size
                    <select id="brPageSize">
                      <option value="10" selected>10</option>
                      <option value="25">25</option>
                      <option value="50">50</option>
                      <option value="100">100</option>
                    </select>
                  </label>
                  <span class="br-shown" id="brShown"></span>
                  <div class="br-pages" id="brPages"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — this page's own JS (demo only, no backend):
         · RES / PAY     status-code → badge label + color maps
         · BR            real bookings, shaped by booking_queue_rows() —
                         the real page will load these rows from the
                         database instead
         · row builder   turns each request into a clickable row
         · tabs + search counts per tab, filters the visible rows
         ============================================================ -->
    <!-- Shared refund state — the ONE source, also included by both customer
         pages. Must load BEFORE this page's script, which reads from it. -->
    <?php /* refund-store.php is gone: refunds are `refunds` rows now, not browser storage. */ ?>
    <script>
      /* Booking Requests queue — static mockup data + client-side filtering.
         Statuses follow the agreed payment-status taxonomy:
         reservation status and payment status are SEPARATE badges. */
      /* Every reservation_statuses code, because the queue now renders whatever
         the database holds — an unmapped code used to crash the row builder. */
      const RES = {
        pending:   { t: 'Pending review', c: 'b-amber' },
        approved:  { t: 'Approved',       c: 'b-green' },
        completed: { t: 'Completed',      c: 'b-navy'  },
        released:  { t: 'Released',       c: 'b-gray'  },
        rejected:  { t: 'Rejected',       c: 'b-red'   },
        cancelled: { t: 'Cancelled',      c: 'b-gray'  },
        /* USeP closed the room while this booking was inside the closure. The
           customer is owed a replacement, a new date, or a non-deniable refund. */
        disrupted: { t: 'Room closed · action needed', c: 'b-red' },
      };
      const PAY = {
        locked:      { t: 'Payment locked',              c: 'b-gray'  },
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
      };
      /* THE QUEUE — real `bookings` rows, shaped in PHP above. This was a
         hand-written array of 15 invented requests; it is now whatever the
         database actually holds, so the customer side and this page can never
         again disagree about which bookings exist. */
      const BR = <?php echo json_encode($brRows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
      const TABS = [
        { k: 'all',     t: 'All' },
        { k: 'id',      t: 'Pending ID review' },
        { k: 'confirm', t: 'Receipts to confirm' },
        { k: 'review',  t: 'Manual review' },
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
      const TAB_PARAM = new URLSearchParams(location.search).get('tab');
      let tab = TABS.some((t) => t.k === TAB_PARAM) ? TAB_PARAM : 'all', q = '';

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      function countOf(k) { return k === 'all' ? BR.length : BR.filter(r => r.cat === k).length; }
      function rowsOf() {
        const list = BR.filter(r => (tab === 'all' || r.cat === tab))
                       .filter(r => !q || (r.name + ' ' + r.room + ' ' + r.id).toLowerCase().includes(q));
        /* Rows carrying an event date (refunds) are ordered soonest-event-first.
           Array.sort is stable and this returns 0 for anything without one, so
           the rest of the queue keeps its existing order. */
        return list.sort((a, b) => {
          if (!a.eventIso || !b.eventIso) return 0;
          return a.eventIso < b.eventIso ? -1 : (a.eventIso > b.eventIso ? 1 : 0);
        });
      }
      /* Paging state. Changing the tab or the search changes WHICH rows exist,
         so both send you back to page 1 — staying on page 4 of a list that now
         has two pages reads as an empty queue. */
      let page = 1, pageSize = 10;
      function setTab(k) { tab = k; page = 1; draw(); }
      function setQ(v) { q = v.trim().toLowerCase(); page = 1; drawRows(); }
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
          <a class="br-row" href="booking-request.php?id=${r.id}">
            <span><div class="r-id">${r.id}</div><div class="r-name">${esc(r.name)}</div><div class="r-sub">${esc(r.type)}</div></span>
            <span class="hide-md"><div class="r-main">${esc(r.room)}</div><div class="r-sub">${esc(r.venue)}</div></span>
            <span class="hide-md r-main">${esc(r.dates)}</span>
            <span class="hide-md"><span class="br-badge ${RES[r.res].c}">${RES[r.res].t}</span></span>
            <span><span class="br-badge ${PAY[r.pay].c}">${PAY[r.pay].t}</span></span>
            <span class="hide-md r-act ${r.cls}">${esc(actLabel(r))}</span>
            <span class="r-chev">&rsaquo;</span>
          </a>`).join('') || '<div style="padding:1.2rem;color:#8a857d;font-size:.85rem">No requests match.</div>';
        drawPager(all.length);
      }
      function draw() {
        document.getElementById('brTabs').innerHTML = TABS.map(t => `
          <button type="button" class="br-tab${tab === t.k ? ' active' : ''}" onclick="setTab('${t.k}')">${t.t}<span class="n">${countOf(t.k)}</span></button>`).join('')
          + `<input id="brSearch" class="br-search" type="text" value="${esc(q)}" placeholder="Search name, room, or request no." oninput="setQ(this.value)">`;
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
      draw();
    </script>
  </body>
</html>
