<?php ?>
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

  [SIM] marks simulation-only pieces (fake data / demo actions) that
  exist so the mockup works on its own — delete or replace them when
  the real database is connected.
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
              </div>
            </div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — this page's own JS (demo only, no backend):
         · RES / PAY     status-code → badge label + color maps
         · DATA [SIM]    fake booking requests shown in the queue —
                         the real page will load these rows from the
                         database instead
         · row builder   turns each request into a clickable row
         · tabs + search counts per tab, filters the visible rows
         ============================================================ -->
    <script>
      /* Booking Requests queue — static mockup data + client-side filtering.
         Statuses follow the agreed payment-status taxonomy:
         reservation status and payment status are SEPARATE badges. */
      const RES = {
        pending:  { t: 'Pending review', c: 'b-amber' },
        approved: { t: 'Approved',       c: 'b-green' },
        released: { t: 'Released',       c: 'b-gray'  },
      };
      const PAY = {
        locked:      { t: 'Payment locked',              c: 'b-gray'  },
        await_gcash: { t: 'Awaiting payment · GCash',    c: 'b-amber' },
        await_cash:  { t: 'Awaiting payment · Cash',     c: 'b-amber' },
        auto_pass:   { t: 'Receipt passed auto-check',   c: 'b-navy'  },
        review:      { t: 'Receipt · manual review',     c: 'b-amber' },
        rejected:    { t: 'Receipt auto-rejected',       c: 'b-red'   },
        confirmed:   { t: 'Payment confirmed',           c: 'b-green' },
        paid_cash:   { t: 'Paid at cashier',             c: 'b-green' },
        overdue:     { t: 'Payment overdue',             c: 'b-red'   },
        refund_req:  { t: 'Refund · under verification', c: 'b-navy'  },
        /* HOSTEL ONLY — waiting on CEDU, not on the customer. */
        await_pos:   { t: 'Awaiting POS · CEDU',         c: 'b-amber' },
      };
      const BR = [
        { id: 'BRQ-2431', name: 'Juan Miguel Dela Cruz', type: 'Student · CIC', room: 'Alumni Grand Ballroom', venue: 'Bahay Alumni', dates: 'Jul 23 – 25, 2026', res: 'pending', pay: 'locked', act: 'Review the submitted ID', cls: 'warn', cat: 'id' },
        { id: 'BRQ-2430', name: 'Maria Santos', type: 'Faculty · CBA', room: 'Heritage Function Room', venue: 'Bahay Alumni', dates: 'Jul 20, 2026', res: 'approved', pay: 'await_gcash', act: 'Pay by Jul 19 · customer notified', cls: '', cat: '' },
        { id: 'BRQ-2429', name: 'Rafael Lim', type: 'Org · JPIA', room: 'CIC Audio-Visual Room', venue: 'USeP Venues', dates: 'Jul 18, 2026', res: 'approved', pay: 'auto_pass', act: 'Match ref 3042 137 089838 in GCash', cls: 'warn', cat: 'confirm' },
        { id: 'BRQ-2428', name: 'Ana Reyes', type: 'Student · CoE', room: 'Alumni Boardroom', venue: 'Bahay Alumni', dates: 'Jul 21, 2026', res: 'approved', pay: 'review', act: '3 flags need a human look', cls: 'warn', cat: 'review' },
        { id: 'BRQ-2427', name: 'Leo Garcia', type: 'Staff · OSAS', room: 'Obrero Function Hall', venue: 'USeP Venues', dates: 'Jul 24 – 27, 2026', res: 'approved', pay: 'rejected', act: 'Resubmit window ends Jul 16 · 2:10 PM', cls: 'warn', cat: '' },
        { id: 'BRQ-2426', name: 'Carmen Uy', type: 'Faculty · CAS', room: 'Admin Conference Hall', venue: 'USeP Venues', dates: 'Jul 22, 2026', res: 'approved', pay: 'await_cash', act: 'Pay at cashier by Jul 21', cls: '', cat: '' },
        { id: 'BRQ-2425', name: 'Paolo Mendoza', type: 'Org · Honor Society', room: 'USeP Gymnasium', venue: 'USeP Venues', dates: 'Aug 2, 2026', res: 'approved', pay: 'confirmed', act: 'Confirmed by M. Robles · Jul 13', cls: '', cat: '' },
        { id: 'BRQ-2424', name: 'Grace Tan', type: 'Student · CIC', room: 'Garden Pavilion', venue: 'Bahay Alumni', dates: 'Jul 17, 2026', res: 'approved', pay: 'overdue', act: 'Deadline passed Jul 16 · slot releasable', cls: 'late', cat: 'overdue' },
        { id: 'BRQ-2423', name: 'Diego Cruz', type: 'Alumni', room: 'Heritage Function Room', venue: 'Bahay Alumni', dates: 'Jul 12, 2026', res: 'approved', pay: 'refund_req', act: 'Both receipts submitted · verify', cls: 'warn', cat: '' },
        /* HOSTEL requests live in the SAME queue. A queue answers "what needs me
           now" — splitting it by venue is how work goes unseen. The row shape is
           identical; only `dates` reads as a stay and the action mentions CEDU. */
        { id: 'BRQ-2450', name: 'Ana Reyes', type: 'Student · CAS', room: 'Hostel Room 1', venue: 'USeP Hostel', dates: 'Aug 1 – 4, 2026 · 2 beds', res: 'approved', pay: 'await_pos', act: 'Get the POS from CEDU · payment is locked until then', cls: 'warn', cat: 'pos' },
        { id: 'BRQ-2451', name: 'Luis Ramos', type: 'Student · CIC', room: 'Hostel Room 4', venue: 'USeP Hostel', dates: 'Jul 20 – 22, 2026 · 3 beds', res: 'approved', pay: 'confirmed', act: 'Paid · OR still to come from the cashier', cls: '', cat: '' },
      ];
      const TABS = [
        { k: 'all',     t: 'All' },
        { k: 'id',      t: 'Pending ID review' },
        { k: 'confirm', t: 'Receipts to confirm' },
        { k: 'review',  t: 'Manual review' },
        { k: 'overdue', t: 'Overdue' },
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
        return BR.filter(r => (tab === 'all' || r.cat === tab))
                 .filter(r => !q || (r.name + ' ' + r.room + ' ' + r.id).toLowerCase().includes(q));
      }
      function setTab(k) { tab = k; draw(); }
      function setQ(v) { q = v.trim().toLowerCase(); drawRows(); }

      function drawRows() {
        document.getElementById('brRows').innerHTML = rowsOf().map(r => `
          <a class="br-row" href="booking-request.php?id=${r.id}">
            <span><div class="r-id">${r.id}</div><div class="r-name">${esc(r.name)}</div><div class="r-sub">${esc(r.type)}</div></span>
            <span class="hide-md"><div class="r-main">${esc(r.room)}</div><div class="r-sub">${esc(r.venue)}</div></span>
            <span class="hide-md r-main">${esc(r.dates)}</span>
            <span class="hide-md"><span class="br-badge ${RES[r.res].c}">${RES[r.res].t}</span></span>
            <span><span class="br-badge ${PAY[r.pay].c}">${PAY[r.pay].t}</span></span>
            <span class="hide-md r-act ${r.cls}">${esc(r.act)}</span>
            <span class="r-chev">&rsaquo;</span>
          </a>`).join('') || '<div style="padding:1.2rem;color:#8a857d;font-size:.85rem">No requests match.</div>';
      }
      function draw() {
        document.getElementById('brTabs').innerHTML = TABS.map(t => `
          <button type="button" class="br-tab${tab === t.k ? ' active' : ''}" onclick="setTab('${t.k}')">${t.t}<span class="n">${countOf(t.k)}</span></button>`).join('')
          + `<input id="brSearch" class="br-search" type="text" value="${esc(q)}" placeholder="Search name, room, or request no." oninput="setQ(this.value)">`;
        const need = BR.filter(r => ['id', 'confirm', 'review', 'overdue', 'pos'].includes(r.cat)).length;
        document.getElementById('brCount').textContent = BR.length + ' booking requests · ' + need + ' need action';
        drawRows();
      }
      draw();
    </script>
  </body>
</html>
