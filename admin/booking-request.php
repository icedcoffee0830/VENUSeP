<?php ?>
<!DOCTYPE html>
<!-- ==================================================================
  BOOKING REQUEST DETAIL — VENUSeP merged system (ported from the AdminLTE mockup)
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  empty container — the whole view is built by the script
    [6] PAGE SCRIPT   status maps, 9 demo records, cards, timeline, actions

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
    <title>VENUSeP | Booking Request Detail</title>

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
         the file is self-contained. Grouped: badges / key-value
         fields / daily schedule (see the /* … */ labels below).
         ============================================================ -->
    <style>
      /*
        Booking Request detail — one full window per request (mockup).
        Self-contained page CSS following the team redesign style.
        v2 — airy fields (no filled grey boxes), hairline separators.
      */
      :root {
        --vm-border: #e7e6e3;
        --vm-hairline: #f0efec;
        --vm-text: #1f1e1e;
        --vm-muted: #8a8680;
        --vm-label: #a3a09a;
        --vm-hover: #f5f4f2;
        --vm-dark: #1f1e1e;
        --vm-navy: #1f2a44;
        --vm-font: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        --vm-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
      }

      .app-main { background: #ffffff; font-family: var(--vm-font); }

      .br-page { color: var(--vm-text); padding: 22px 0 0; width: 100%; } /* 22px + 4px from .app-content = 26px top, sides owned by .container-fluid */
      .br-back { color: var(--vm-muted); display: inline-block; font-size: 0.85rem; font-weight: 600; margin-bottom: 0.9rem; text-decoration: none; }
      .br-back:hover { color: var(--vm-text); }
      .br-title { font-size: 1.35rem; font-weight: 700; letter-spacing: -0.01em; margin: 0 0 0.2rem; }
      .br-subtitle { color: var(--vm-muted); font-size: 0.84rem; margin: 0; }

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

      .br-btn { background: #ffffff; border: 1px solid var(--vm-border); border-radius: 9px; color: var(--vm-text); cursor: pointer; font-family: inherit; font-size: 0.83rem; font-weight: 600; padding: 0.5rem 0.95rem; }
      .br-btn:hover { background: var(--vm-hover); }
      .br-btn-primary { background: var(--vm-dark); border-color: var(--vm-dark); color: #ffffff; }
      .br-btn-primary:hover { background: #000000; }

      .br-head { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; margin-bottom: 1.1rem; }
      .br-actions { display: flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-end; }
      .br-actions .none { color: var(--vm-muted); font-size: 0.8rem; padding-top: 0.5rem; }

      .br-grid { align-items: start; display: grid; gap: 1.1rem; grid-template-columns: 1.05fr 1fr; }
      @media (max-width: 1100px) { .br-grid { grid-template-columns: 1fr; } }

      .br-card { background: #ffffff; border: 1px solid var(--vm-border); border-radius: 14px; box-shadow: var(--vm-shadow); }
      .br-card + .br-card { margin-top: 1.1rem; }
      .br-sec { padding: 1.15rem 1.3rem 1.25rem; }
      .br-sec + .br-sec { border-top: 1px solid var(--vm-hairline); }
      .br-h2 { color: var(--vm-muted); font-size: 0.7rem; font-weight: 600; letter-spacing: 0.06em; margin: 0 0 0.95rem; text-transform: uppercase; }

      /* key-value fields — open, label-over-value, no filled boxes */
      .br-kvgrid { display: grid; gap: 0.9rem 1.6rem; grid-template-columns: 1fr 1fr; }
      .br-kv { min-width: 0; }
      .br-kv .k { color: var(--vm-label); font-size: 0.64rem; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 0.22rem; text-transform: uppercase; }
      .br-kv .v { font-size: 0.87rem; font-weight: 600; line-height: 1.4; overflow-wrap: anywhere; }
      .br-kv .v .soft { color: var(--vm-muted); font-weight: 500; }
      .kv-wide { grid-column: 1 / -1; }

      .br-banner { align-items: flex-start; border: 1px solid; border-radius: 11px; display: flex; font-size: 0.82rem; font-weight: 400; gap: 0.55rem; margin-bottom: 1rem; padding: 0.72rem 0.9rem; }
      .br-banner .s { display: block; font-size: 0.76rem; font-weight: 400; margin-top: 0.15rem; opacity: 0.85; }
      .bn-green { background: #f2faf5; border-color: #d4ebdd; color: #1c7a4f; }
      .bn-amber { background: #fdf7ee; border-color: #f3e3c8; color: #8a5a12; }
      .bn-red   { background: #fdf2f2; border-color: #f2d8d8; color: #b23a3a; }
      .bn-gray  { background: #f5f7fb; border-color: #dfe4ef; color: #44506b; }

      .br-flag { align-items: flex-start; color: #4a463f; display: flex; font-size: 0.8rem; gap: 0.5rem; line-height: 1.5; padding: 0.26rem 0; }
      .br-flag .dot { border-radius: 999px; flex: none; height: 7px; margin-top: 6px; width: 7px; }

      /* daily schedule — plain hairline rows, no grey strip */
      .br-sched { border-top: 1px solid var(--vm-hairline); }
      .br-sched .d { display: flex; font-size: 0.8rem; gap: 1rem; justify-content: space-between; padding: 0.55rem 0.1rem; }
      .br-sched .d + .d { border-top: 1px solid var(--vm-hairline); }
      .br-sched .skip { color: #b3afa8; }

      .br-avatar { align-items: center; background: var(--vm-navy); border-radius: 999px; color: #ffffff; display: flex; flex: none; font-size: 0.82rem; font-weight: 650; height: 42px; justify-content: center; letter-spacing: 0.02em; width: 42px; }
      .br-idtile { align-items: center; aspect-ratio: 16 / 9; background: linear-gradient(135deg, #f0f3f8, #e2e8f1); border: 1px solid var(--vm-border); border-radius: 11px; color: #94a0b2; display: flex; font-size: 0.78rem; font-weight: 600; justify-content: center; }
      .br-receipt { align-items: center; background: linear-gradient(135deg, #f1f4f8, #e5eaf1); border: 1px solid var(--vm-border); border-radius: 10px; color: #9aa4b0; display: flex; flex: none; font-size: 0.66rem; font-weight: 600; height: 112px; justify-content: center; text-align: center; width: 88px; }

      .br-tl { list-style: none; margin: 0; padding: 0; position: relative; }
      .br-tl::before { background: var(--vm-hairline); border-radius: 2px; bottom: 8px; content: ''; left: 7px; position: absolute; top: 8px; width: 2px; }
      .br-tl li { padding: 0 0 1rem 26px; position: relative; }
      .br-tl li:last-child { padding-bottom: 0; }
      .br-tl .p { background: #ffffff; border: 3px solid #d6d3cd; border-radius: 999px; height: 14px; left: 1px; position: absolute; top: 3px; width: 14px; }
      .br-tl li.hi .p { border-color: var(--vm-navy); }
      .br-tl .w { color: var(--vm-muted); font-size: 0.72rem; }
      .br-tl .x { font-size: 0.83rem; font-weight: 600; margin: 0.06rem 0 0.1rem; }
      .br-tl .m { color: var(--vm-muted); font-size: 0.76rem; line-height: 1.45; }

      .br-ovr { border: 1px dashed #d9d7d2; border-radius: 11px; margin-top: 0.95rem; padding: 0.9rem 0.95rem; }
      .br-ovr input, .br-ovr textarea { border: 1px solid var(--vm-border); border-radius: 8px; font-family: inherit; font-size: 0.82rem; padding: 0.5rem 0.62rem; width: 100%; }
      .br-ovr input:focus, .br-ovr textarea:focus { border-color: #b9b5ad; outline: none; }
      .br-ovr textarea { resize: vertical; }
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
           [4] PAGE CONTENT — intentionally just an empty container:
           the PAGE SCRIPT [6] reads ?id=BRQ-xxxx and renders the
           whole detail view into it.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="br-page" id="brqRoot"></div>
          </div>
        </div>
      </main>
    </div>

<!-- ============================================================
         [6] PAGE SCRIPT — everything on this page (demo, no backend):
         · RES / PAY / REF   status-code → badge label + color maps
         · DATA [SIM]        9 fake requests, one per payment status —
                             the real page will load ONE request from
                             the database using the ?id= in the URL
         · header + actions  contextual buttons per current status
         · cards             Customer & ID / Reservation / Payment
                             (receipt evidence, flags, override box)
         · timeline          the audit trail of status changes
         · demo actions [SIM] change the page in memory only (refresh
                             resets) — the real app will POST to the
                             server and save to the database
         ============================================================ -->
    <script>
      /* Booking Request detail — one full window per request (mockup).
         Reads ?id=BRQ-xxxx; actions update badges + timeline in-page (demo only).
         Statuses follow the agreed payment-status taxonomy. */
      const RES = {
        pending:  { t: 'Reservation pending review', c: 'b-amber' },
        approved: { t: 'Reservation approved',       c: 'b-green' },
        released: { t: 'Slot released',              c: 'b-gray'  },
      };
      const PAY = {
        locked:        { t: 'Payment locked',              c: 'b-gray'  },
        /* HOSTEL ONLY. The one genuinely new state the CEDU flow adds: the
           customer has booked and cannot pay yet, but nothing is wrong and
           nobody is waiting on THEM — staff are out fetching the POS. Every
           other waiting state in this list waits on the customer. */
        await_pos:     { t: 'Awaiting POS · CEDU',         c: 'b-amber' },
        await_gcash:   { t: 'Awaiting payment · GCash',    c: 'b-amber' },
        await_cash:    { t: 'Awaiting payment · Cash',     c: 'b-amber' },
        auto_pass:     { t: 'Receipt passed auto-check',   c: 'b-navy'  },
        review:        { t: 'Receipt · manual review',     c: 'b-amber' },
        rejected:      { t: 'Receipt auto-rejected',       c: 'b-red'   },
        confirmed:     { t: 'Payment confirmed',           c: 'b-green' },
        paid_cash:     { t: 'Paid at cashier',             c: 'b-green' },
        overdue:       { t: 'Payment overdue',             c: 'b-red'   },
        refund_req:    { t: 'Refund · under verification', c: 'b-navy'  },
        refund_done:   { t: 'Refunded',                    c: 'b-green' },
        refund_denied: { t: 'Refund denied',               c: 'b-red'   },
      };

      const DATA = {
        'BRQ-2431': { id:'BRQ-2431', name:'Juan Miguel Dela Cruz', type:'Student · CIC', email:'jmdelacruz@usep.edu.ph', phone:'0917 555 0123',
          event:'CIC Research Colloquium', room:'Alumni Grand Ballroom', venue:'Bahay Alumni', capacity:300, attendees:220,
          dates:'Jul 23 – 25, 2026', feeDay:'₱5,000', days:3, total:'₱15,000', payBy:'Jul 22, 2026', method:'gcash',
          submitted:'Jul 14, 2026 · 9:02 AM', res:'pending', pay:'locked', idStatus:'pending', idLabel:'USeP Student ID',
          sched:[{d:'Day 1 · Thu, Jul 23',t:'7:00 AM – 4:30 PM'},{d:'Day 2 · Fri, Jul 24',t:'7:00 AM – 4:30 PM'},{d:'Day 3 · Sat, Jul 25',t:'7:00 AM – 4:30 PM'}],
          tl:[{w:'Jul 14 · 9:02 AM — Customer',x:'Booking submitted',m:'Multi-day request (3 days) with USeP Student ID attached.'}] },

        'BRQ-2430': { id:'BRQ-2430', name:'Maria Santos', type:'Faculty · CBA', email:'msantos@usep.edu.ph', phone:'0918 220 4411',
          event:'CBA Faculty Planning Workshop', room:'Heritage Function Room', venue:'Bahay Alumni', capacity:80, attendees:45,
          dates:'Jul 20, 2026', feeDay:'₱2,500', days:1, total:'₱2,500', payBy:'Jul 19, 2026', method:'gcash',
          submitted:'Jul 12, 2026 · 2:15 PM', res:'approved', pay:'await_gcash', idStatus:'approved', idLabel:'USeP Employee ID',
          sched:[{d:'Mon, Jul 20',t:'8:00 AM – 5:00 PM'}],
          tl:[{w:'Jul 12 · 2:15 PM — Customer',x:'Booking submitted',m:'Single-day request with USeP Employee ID attached.'},
              {w:'Jul 13 · 8:40 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked. Pay-by deadline set to Jul 19 (1 day before the event).'}] },

        'BRQ-2429': { id:'BRQ-2429', name:'Rafael Lim', type:'Org · JPIA', email:'jpia@usep.edu.ph', phone:'0917 884 2020',
          event:'JPIA General Assembly', room:'CIC Audio-Visual Room', venue:'USeP Venues', capacity:120, attendees:110,
          dates:'Jul 18, 2026', feeDay:'₱2,000', days:1, total:'₱2,000', payBy:'Jul 17, 2026', method:'gcash',
          submitted:'Jul 13, 2026 · 10:05 AM', res:'approved', pay:'auto_pass', idStatus:'approved', idLabel:'USeP Student ID',
          sched:[{d:'Sat, Jul 18',t:'1:00 PM – 6:00 PM'}],
          receipt:{ ref:'3042 137 089838', amount:'₱2,000.00', date:'Jul 14, 2026 · 9:12 AM', to:'0995 194 ****', conf:'96%', flags:[] },
          tl:[{w:'Jul 13 · 10:05 AM — Customer',x:'Booking submitted',m:'Single-day request with USeP Student ID attached.'},
              {w:'Jul 13 · 11:20 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked. Pay-by deadline Jul 17.'},
              {w:'Jul 14 · 9:12 AM — Customer',x:'GCash receipt uploaded',m:'Auto-check PASSED: exact amount, correct receiver, fresh reference, receipt markers OK.'}] },

        'BRQ-2428': { id:'BRQ-2428', name:'Ana Reyes', type:'Student · CoE', email:'areyes@usep.edu.ph', phone:'0916 300 7788',
          event:'CoE Thesis Defense Panel', room:'Alumni Boardroom', venue:'Bahay Alumni', capacity:20, attendees:12,
          dates:'Jul 21, 2026', feeDay:'₱1,500', days:1, total:'₱1,500', payBy:'Jul 20, 2026', method:'gcash',
          submitted:'Jul 12, 2026 · 4:44 PM', res:'approved', pay:'review', idStatus:'approved', idLabel:'USeP Student ID',
          sched:[{d:'Tue, Jul 21',t:'9:00 AM – 12:00 PM'}],
          receipt:{ ref:'— (unreadable)', amount:'₱1,500.00', date:'Jun 27, 2026 · 3:41 PM', to:'MI....A J.. J.', conf:'41%',
            flags:['Reference number could not be read','Receipt is more than 14 days old','OCR confidence is low — double-check against the image'] },
          tl:[{w:'Jul 12 · 4:44 PM — Customer',x:'Booking submitted',m:'Single-day request with USeP Student ID attached.'},
              {w:'Jul 13 · 9:02 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked. Pay-by deadline Jul 20.'},
              {w:'Jul 14 · 8:31 AM — Customer',x:'GCash receipt uploaded',m:'Auto-check: NEEDS MANUAL REVIEW — 3 soft flags raised.'}] },

        'BRQ-2427': { id:'BRQ-2427', name:'Leo Garcia', type:'Staff · OSAS', email:'lgarcia@usep.edu.ph', phone:'0919 555 6710',
          event:'Student Leaders Summit', room:'Obrero Function Hall', venue:'USeP Venues', capacity:200, attendees:180,
          dates:'Jul 24 – 27, 2026 (3 of 4 days)', feeDay:'₱3,000', days:3, total:'₱9,000', payBy:'Jul 23, 2026', method:'gcash',
          submitted:'Jul 11, 2026 · 1:10 PM', res:'approved', pay:'rejected', idStatus:'approved', idLabel:'USeP Employee ID',
          resubmitBy:'Jul 16, 2026 · 2:10 PM',
          sched:[{d:'Day 1 · Fri, Jul 24',t:'8:00 AM – 5:00 PM'},{d:'Sat, Jul 25 — already booked',t:'Not available',skip:true},{d:'Day 2 · Sun, Jul 26',t:'8:00 AM – 5:00 PM'},{d:'Day 3 · Mon, Jul 27',t:'8:00 AM – 5:00 PM'}],
          rejects:['Amount read ₱2,900.00 — this booking requires the EXACT total ₱9,000.00 (3 days × ₱3,000)','This exact image file was already submitted before (same fingerprint)'],
          tl:[{w:'Jul 11 · 1:10 PM — Customer',x:'Booking submitted',m:'Range Jul 24–27; Jul 25 excluded automatically (already booked).'},
              {w:'Jul 12 · 10:15 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked. Pay-by deadline Jul 23.'},
              {w:'Jul 14 · 2:10 PM — Customer',x:'GCash receipt uploaded',m:'Auto-check REJECTED: amount mismatch + duplicate file. 48-hour resubmit window started (ends Jul 16 · 2:10 PM).'}] },

        'BRQ-2426': { id:'BRQ-2426', name:'Carmen Uy', type:'Faculty · CAS', email:'cuy@usep.edu.ph', phone:'0917 002 9931',
          event:'CAS Research In-Service Training', room:'Admin Conference Hall', venue:'USeP Venues', capacity:60, attendees:40,
          dates:'Jul 22, 2026', feeDay:'₱1,800', days:1, total:'₱1,800', payBy:'Jul 21, 2026', method:'cash',
          submitted:'Jul 13, 2026 · 3:25 PM', res:'approved', pay:'await_cash', idStatus:'approved', idLabel:'USeP Employee ID',
          sched:[{d:'Wed, Jul 22',t:'8:00 AM – 12:00 PM'}],
          tl:[{w:'Jul 13 · 3:25 PM — Customer',x:'Booking submitted',m:'Single-day request; payment method: cash (walk-in).'},
              {w:'Jul 13 · 4:50 PM — M. Robles (staff)',x:'ID + reservation approved',m:'Customer will pay at the cashier, quoting BRQ-2426, by Jul 21.'}] },

        'BRQ-2425': { id:'BRQ-2425', name:'Paolo Mendoza', type:'Org · Honor Society', email:'honorsoc@usep.edu.ph', phone:'0916 777 1122',
          event:'Recognition Night', room:'USeP Gymnasium', venue:'USeP Venues', capacity:1000, attendees:850,
          dates:'Aug 2, 2026', feeDay:'₱8,000', days:1, total:'₱8,000', payBy:'Aug 1, 2026', method:'gcash',
          submitted:'Jul 10, 2026 · 9:30 AM', res:'approved', pay:'confirmed', idStatus:'approved', idLabel:'USeP Student ID',
          sched:[{d:'Sun, Aug 2',t:'3:00 PM – 10:00 PM'}],
          receipt:{ ref:'2045 667 982375', amount:'₱8,000.00', date:'Jul 13, 2026 · 3:58 PM', to:'0995 194 ****', conf:'94%', flags:[] },
          confirmedBy:'M. Robles · Jul 13, 2026 · 4:22 PM',
          tl:[{w:'Jul 10 · 9:30 AM — Customer',x:'Booking submitted',m:'Single-day request with USeP Student ID attached.'},
              {w:'Jul 10 · 11:00 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked.'},
              {w:'Jul 13 · 3:58 PM — Customer',x:'GCash receipt uploaded',m:'Auto-check PASSED.'},
              {w:'Jul 13 · 4:22 PM — M. Robles (staff)',x:'Payment confirmed',m:'Reference 2045 667 982375 matched in GCash Transaction History. Reference locked.'}] },

        'BRQ-2424': { id:'BRQ-2424', name:'Grace Tan', type:'Student · CIC', email:'gtan@usep.edu.ph', phone:'0918 445 9012',
          event:'ACM Student Chapter Meetup', room:'Garden Pavilion', venue:'Bahay Alumni', capacity:150, attendees:95,
          dates:'Jul 17, 2026', feeDay:'₱3,500', days:1, total:'₱3,500', payBy:'Jul 16, 2026 (passed)', method:'gcash',
          submitted:'Jul 8, 2026 · 5:12 PM', res:'approved', pay:'overdue', idStatus:'approved', idLabel:'USeP Student ID',
          sched:[{d:'Fri, Jul 17',t:'2:00 PM – 8:00 PM'}],
          tl:[{w:'Jul 8 · 5:12 PM — Customer',x:'Booking submitted',m:'Single-day request with USeP Student ID attached.'},
              {w:'Jul 9 · 8:30 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked. Pay-by deadline Jul 16.'},
              {w:'Jul 16 · 11:59 PM — System',x:'Payment overdue',m:'No receipt submitted by the deadline. Slot is releasable.'}] },

        'BRQ-2423': { id:'BRQ-2423', name:'Diego Cruz', type:'Alumni', email:'dcruz@alumni.usep.edu.ph', phone:'0917 660 3345',
          event:'Batch ’16 Reunion Dinner', room:'Heritage Function Room', venue:'Bahay Alumni', capacity:80, attendees:70,
          dates:'Jul 12, 2026', feeDay:'₱2,500', days:1, total:'₱2,500', payBy:'Jul 11, 2026', method:'gcash',
          submitted:'Jul 5, 2026 · 7:48 PM', res:'approved', pay:'refund_req', idStatus:'approved', idLabel:'Government ID (Driver’s License)',
          sched:[{d:'Sun, Jul 12',t:'5:00 PM – 10:00 PM'}],
          receipt:{ ref:'2044 118 555209', amount:'₱2,500.00', date:'Jul 9, 2026 · 6:02 PM', to:'0995 194 ****', conf:'93%', flags:[] },
          refund:{ stage:'Under verification', docs:'System transaction receipt + GCash receipt submitted · identity re-verified (email + password)' },
          tl:[{w:'Jul 5 · 7:48 PM — Customer',x:'Booking submitted',m:'Single-day request with a government ID attached.'},
              {w:'Jul 6 · 9:00 AM — M. Robles (staff)',x:'ID + reservation approved',m:'Payment unlocked.'},
              {w:'Jul 9 · 6:02 PM — Customer',x:'GCash receipt uploaded',m:'Auto-check PASSED.'},
              {w:'Jul 10 · 8:15 AM — M. Robles (staff)',x:'Payment confirmed',m:'Reference matched in GCash.'},
              {w:'Jul 14 · 10:40 AM — Customer',x:'Refund requested',m:'Event cancelled by the organizer. Both receipts submitted; identity re-verified.'}] },

        /* ============================================================
           [SIM] HOSTEL requests. Same queue, same detail page, same
           receipt machinery — `kind:'hostel'` branches only the two
           cards where the DATA genuinely differs (Reservation, Payment).

           Note what is NOT here: a `beds` field. beds = occupants.length,
           exactly as on the customer side. Storing both would let the
           roster and the counter disagree.
           ============================================================ */
        'BRQ-2450': { kind:'hostel', id:'BRQ-2450', name:'Ana Reyes', type:'Student · CAS', email:'areyes@usep.edu.ph', phone:'0916 233 8890',
          event:'Hostel stay · 2 beds', room:'Hostel Room 1', venue:'USeP Hostel', crType:'Communal CR',
          checkIn:'Aug 1, 2026', checkOut:'Aug 4, 2026', nights:3, rate:'₱350', total:'₱2,100', method:'gcash',
          occupants:[{n:'Ana Reyes',g:'F'},{n:'Bea Cruz',g:'F'}],
          pos:null, or:null, checkedIn:false,
          submitted:'Jul 15, 2026 · 8:41 AM', res:'approved', pay:'await_pos', idStatus:'approved', idLabel:'USeP Student ID',
          tl:[{w:'Jul 15 · 8:41 AM — Customer',x:'Booking submitted',m:'2 beds for 3 nights, both guests named. Valid ID attached.'},
              {w:'Jul 15 · 9:02 AM — R. Delos Reyes (staff)',x:'ID + reservation approved',m:'Payment stays locked — POS not requested yet.'},
              {w:'Jul 15 · 9:05 AM — R. Delos Reyes (staff)',x:'POS request raised at CEDU',m:'Walked the booking over. Waiting on CEDU to issue the POS.'}] },

        'BRQ-2451': { kind:'hostel', id:'BRQ-2451', name:'Luis Ramos', type:'Student · CIC', email:'lramos@usep.edu.ph', phone:'0918 771 2245',
          event:'Hostel stay · 3 beds', room:'Hostel Room 4', venue:'USeP Hostel', crType:'Private CR',
          checkIn:'Jul 20, 2026', checkOut:'Jul 22, 2026', nights:2, rate:'₱400', total:'₱2,400', method:'gcash',
          occupants:[{n:'Luis Ramos',g:'M'},{n:'Mara Ilagan',g:'F'},{n:'Nico Perez',g:'M'}],
          /* Confirmed and PAID, but the OR has not come back from the cashier.
             This is the whole reason the OR is a document flag and not a payment
             status: payment is finished, the paperwork is not. */
          pos:'POS-48213', or:null, checkedIn:false,
          submitted:'Jul 12, 2026 · 3:12 PM', res:'approved', pay:'confirmed', idStatus:'approved', idLabel:'USeP Student ID',
          confirmedBy:'R. Delos Reyes (staff)',
          receipt:{ ref:'2044 771 990412', amount:'₱2,400.00', date:'Jul 13, 2026 · 10:22 AM', to:'0917 123 ****', conf:'95%', flags:[] },
          tl:[{w:'Jul 12 · 3:12 PM — Customer',x:'Booking submitted',m:'3 beds for 2 nights (mixed group), all guests named.'},
              {w:'Jul 12 · 4:30 PM — R. Delos Reyes (staff)',x:'POS received from CEDU',m:'POS-48213 recorded. Payment unlocked.'},
              {w:'Jul 13 · 10:22 AM — Customer',x:'GCash receipt uploaded',m:'Auto-check PASSED against the hostel staff account.'},
              {w:'Jul 13 · 11:05 AM — R. Delos Reyes (staff)',x:'Payment confirmed',m:'Reference matched. Booking is confirmed; OR still to come.'},
              {w:'Jul 13 · 2:00 PM — R. Delos Reyes (staff)',x:'Cash handed to the University Cashier',m:'Cashed out the GCash payment. Waiting on the OR.'}] },
      };

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      const qid = new URLSearchParams(location.search).get('id');
      const cur = DATA[qid] || DATA['BRQ-2429'];

      function kv(k, v, wide) { return `<div class="br-kv${wide ? ' kv-wide' : ''}"><div class="k">${k}</div><div class="v">${v}</div></div>`; }
      function banner(cls, title, sub) { return `<div class="br-banner ${cls}"><div>${title}${sub ? `<span class="s">${sub}</span>` : ''}</div></div>`; }
      function flags(list, color) { return list.map(f => `<div class="br-flag"><span class="dot" style="background:${color}"></span><span>${esc(f)}</span></div>`).join(''); }
      function initials(n) { return n.split(' ').map(w => w[0]).slice(0, 2).join(''); }

      /* which buttons make sense right now (reservation-level, in the header) */
      function hdrActions() {
        if (cur.res === 'pending') return `<button class="br-btn br-btn-primary" onclick="approveIdRes()">Approve ID &amp; reservation</button><button class="br-btn" onclick="rejectRequest()">Reject request</button>`;
        if (cur.pay === 'overdue') return `<button class="br-btn br-btn-primary" onclick="releaseSlot()">Release the slot</button><button class="br-btn" onclick="extendDeadline()">Extend 48 h</button>`;
        if (cur.pay === 'refund_req') return `<button class="br-btn br-btn-primary" onclick="markRefunded()">Mark refunded</button><button class="br-btn" onclick="denyRefund()">Deny refund</button>`;
        /* Check-in is hostel-only: an event venue has no arrivals. It needs the
           OR in hand, because that is what the guest is asked to show. */
        if (cur.kind === 'hostel' && cur.pay === 'confirmed' && !cur.checkedIn) {
          return cur.or
            ? `<button class="br-btn br-btn-primary" onclick="checkIn()">Check the guest in</button>`
            : `<span class="none">Record the OR before check-in — the guest has to show it</span>`;
        }
        if (cur.kind === 'hostel' && cur.checkedIn) return `<span class="none">Guest checked in &check;</span>`;
        return `<span class="none">Payment actions are in the Payment panel &darr;</span>`;
      }

      /* receipt evidence block shared by several payment states */
      function receiptBlock() {
        const r = cur.receipt;
        return `
          <div style="display:flex;gap:0.85rem;margin-bottom:0.7rem">
            <div class="br-receipt">receipt<br>screenshot</div>
            <div class="br-kvgrid" style="flex:1;align-content:start">
              ${kv('Ref no.', esc(r.ref))}${kv('Amount read', esc(r.amount))}
              ${kv('Receipt date', esc(r.date))}${kv('Sent to', esc(r.to))}
            </div>
          </div>
          <div style="color:var(--vm-muted);font-size:0.74rem;margin-bottom:0.5rem">OCR confidence ${r.conf} · duplicate check: file + reference not seen before</div>`;
      }

      /* ============================================================
         HOSTEL BRANCH — only the two cards where the DATA differs.
         Everything else on this page (customer + ID, the receipt
         evidence block, the override box, the timeline) is identical
         machinery and is NOT branched.
         ============================================================ */
      const isHostel = () => cur.kind === 'hostel';
      /* beds is DERIVED. There is no cur.beds and there must never be one. */
      const bedCount = () => (cur.occupants || []).length;
      function mixLabel() {
        const F = (cur.occupants || []).filter(o => o.g === 'F').length;
        const M = (cur.occupants || []).length - F;
        return [F ? F + ' female' : '', M ? M + ' male' : ''].filter(Boolean).join(', ');
      }
      /* the guest roster replaces the venue's daily schedule — a stay has no
         per-day hours, it has a list of named people */
      function rosterBlock() {
        return `
          <div style="margin-top:0.8rem">
            <div class="br-h2" style="margin-bottom:0.45rem">Guests · one per bed</div>
            <div class="br-sched">
              ${cur.occupants.map((o, i) => `<div class="d"><span>Bed ${i + 1} · ${esc(o.n)}</span><span style="font-weight:600">${o.g === 'F' ? 'Female' : 'Male'}</span></div>`).join('')}
            </div>
            <div style="color:var(--vm-muted);font-size:0.74rem;margin-top:0.45rem">${bedCount()} bed${bedCount() > 1 ? 's' : ''} · ${esc(mixLabel())} — gender is shown for the room's make-up only; no bed is reserved by gender.</div>
          </div>`;
      }
      /* The OR is NOT a payment status — payment already ended at `confirmed`.
         It is a document the cashier still owes, tracked so staff can chase it
         and so nobody mistakes a paid booking for an unpaid one. */
      function orBlock() {
        if (!isHostel() || (cur.pay !== 'confirmed' && cur.pay !== 'paid_cash')) return '';
        if (cur.or) return `<div class="br-kvgrid" style="margin-top:0.7rem">${kv('Official Receipt', esc(cur.or) + ' <span class="soft">(University Cashier)</span>')}${kv('Check-in', cur.checkedIn ? 'Guest checked in' : 'Not yet arrived')}</div>`;
        return `
          <div class="br-ovr" style="margin-top:0.7rem">
            <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">Official Receipt not issued yet</div>
            <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">The payment is <strong>confirmed and final</strong> — this is paperwork, not money. Record the OR number once the University Cashier issues it; the guest needs it at check-in.</div>
            <input id="orNo" placeholder="OR number from the cashier" value="">
            <div style="margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="recordOr()">Record OR</button></div>
          </div>`;
      }

      function payBody() {
        /* HOSTEL: the POS gate sits in FRONT of every payment state. Without a
           POS from CEDU the customer literally cannot pay, and the thing being
           waited on is another office — not the customer. */
        if (isHostel() && cur.pay === 'await_pos') {
          return banner('bn-amber', 'Awaiting the POS from CEDU', 'Staff requested it. The customer cannot pay until it is recorded — nothing is wrong and nothing is late.') +
            `<div class="br-kvgrid">${kv('Method chosen', cur.method === 'cash' ? 'Cash · to staff' : 'GCash · staff account')}${kv('Amount once unlocked', esc(cur.total))}</div>
             <div class="br-ovr" style="margin-top:0.7rem">
               <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">Record the POS</div>
               <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">Enter the POS number CEDU issued. This unlocks payment for the guest.</div>
               <input id="posNo" placeholder="POS number from CEDU" value="">
               ${cur.posErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.posErr)}</div>` : ''}
               <div style="margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="recordPos()">Record POS &amp; unlock payment</button></div>
             </div>`;
        }
        switch (cur.pay) {
          case 'locked': return banner('bn-gray', 'Payment is locked', 'The customer cannot pay until you approve the ID and the reservation.') +
            `<div class="br-kvgrid">${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}${kv('Pay-by once approved', esc(cur.payBy) + ' <span class="soft">(1 day before the event)</span>')}</div>`;
          case 'await_gcash': return banner('bn-amber', 'Awaiting GCash payment', 'The customer was notified. The receipt will be auto-checked the moment it is uploaded.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${kv('Amount due', esc(cur.total))}</div>`;
          case 'await_cash': return banner('bn-amber', 'Awaiting cash payment at the cashier', 'Confirm here once the cashier records the payment.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${kv('Amount due', esc(cur.total))}${kv('Where', 'USeP Cashier — Venue Reservations Window', true)}</div>
             <div style="margin-top:0.8rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Record cash payment</button></div>`;
          case 'auto_pass': return banner('bn-green', 'All automatic checks passed', 'Exact amount · correct receiver · fresh reference · genuine receipt markers.') + receiptBlock() +
            `<div style="display:flex;gap:0.5rem;margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="rejectPay()">Reject after GCash check</button></div>
             <div style="color:var(--vm-muted);font-size:0.74rem;margin-top:0.55rem">Find the reference number in the business GCash Transaction History before confirming.</div>`;
          case 'review': return banner('bn-amber', 'Needs manual review', 'The auto-check could not verify everything — compare the fields against the screenshot.') + receiptBlock() +
            flags(cur.receipt.flags, '#c99a3c') +
            `<div style="display:flex;gap:0.5rem;margin-top:0.7rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="rejectPay()">Reject receipt</button></div>`;
          case 'rejected': return banner('bn-red', 'Receipt auto-rejected', 'Customer was told to fix and resubmit. Slot held for the 48-hour window (until ' + esc(cur.resubmitBy || cur.payBy) + '), bounded by the pay-by deadline.') +
            flags(cur.rejects || [], '#b23a3a') +
            `<div class="br-ovr">
              <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">Override &amp; confirm payment (staff)</div>
              <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">Use only after finding the payment in the business GCash Transaction History. <strong>Duplicate-receipt rejections can only be overridden by an admin.</strong> The auto-check verdict stays on record either way.</div>
              <input id="ovrRef" placeholder="Verified reference number (from GCash)" style="margin-bottom:0.5rem" value="${esc(cur.ovrRef || '')}">
              <textarea id="ovrNote" rows="2" placeholder="Required note — why is this override correct?">${esc(cur.ovrNote || '')}</textarea>
              ${cur.ovrErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.ovrErr)}</div>` : ''}
              <div style="margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="overrideConfirm()">Override &amp; confirm</button></div>
            </div>`;
          case 'confirmed': return banner('bn-green', 'Payment confirmed', 'Confirmed by ' + esc(cur.confirmedBy || 'staff') + '. The reference number is permanently locked.') + (cur.receipt ? receiptBlock() : '');
          case 'paid_cash': return banner('bn-green', 'Paid at the cashier', esc(cur.confirmedBy || 'Recorded by staff') + ' · official receipt issued at the counter.');
          case 'overdue': return banner('bn-red', 'Payment overdue', 'The pay-by deadline (' + esc(cur.payBy) + ') passed with no valid payment. The slot can be released or the deadline extended.') +
            `<div class="br-kvgrid">${kv('Amount due', esc(cur.total))}${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}</div>`;
          case 'refund_req': return banner('bn-amber', 'Refund requested — under verification', esc(cur.refund.docs)) + (cur.receipt ? receiptBlock() : '') +
            `<div style="color:var(--vm-muted);font-size:0.76rem;line-height:1.55">Refund chain: Requested &rarr; <strong>Under verification</strong> &rarr; Refunded / Denied. Both receipts are required — a request missing either is denied automatically.</div>`;
          case 'refund_done': return banner('bn-green', 'Refunded', esc(cur.confirmedBy || 'Processed by staff') + ' · refund recorded on this booking.');
          case 'refund_denied': return banner('bn-red', 'Refund denied', esc(cur.denyReason || 'Requirements not met.'));
          default: return '';
        }
      }

      function idBlock() {
        const st = cur.idStatus;
        return `
          <div style="margin-top:0.85rem">
            <div class="br-idtile">${esc(cur.idLabel)} — image</div>
            <div style="align-items:center;display:flex;gap:0.5rem;justify-content:space-between;margin-top:0.6rem">
              <span class="br-badge ${st === 'approved' ? 'b-green' : st === 'rejected' ? 'b-red' : 'b-amber'}">${st === 'approved' ? 'ID approved' : st === 'rejected' ? 'ID rejected' : 'ID awaiting review'}</span>
              ${st === 'pending' ? '<span style="color:var(--vm-muted);font-size:0.74rem">Approve via the header button — it approves the ID and the reservation together.</span>' : ''}
            </div>
          </div>`;
      }

      function render() {
        // venue-only: a hostel booking has named guests, not an attendee count
        const over = !isHostel() && cur.attendees > cur.capacity;
        document.getElementById('brqRoot').innerHTML = `
          <a class="br-back" href="booking-requests.php">&larr; Back to Booking Requests</a>
          <div class="br-head">
            <div>
              <h1 class="br-title">${cur.id} · ${esc(cur.event)}</h1>
              <p class="br-subtitle">Submitted ${esc(cur.submitted)}</p>
            </div>
            <div>
              <div style="display:flex;flex-wrap:wrap;gap:0.45rem;justify-content:flex-end;margin-bottom:0.6rem">
                <span class="br-badge ${RES[cur.res].c}">${RES[cur.res].t}</span>
                <span class="br-badge ${PAY[cur.pay].c}">${PAY[cur.pay].t}</span>
              </div>
              <div class="br-actions">${hdrActions()}</div>
            </div>
          </div>

          <div class="br-grid">
            <div>
              <div class="br-card">
                <div class="br-sec">
                  <h2 class="br-h2">Customer &amp; ID</h2>
                  <div style="align-items:center;display:flex;gap:0.7rem;margin-bottom:0.8rem">
                    <div class="br-avatar">${esc(initials(cur.name))}</div>
                    <div>
                      <div style="font-size:0.92rem;font-weight:650">${esc(cur.name)}</div>
                      <div style="color:var(--vm-muted);font-size:0.76rem">${esc(cur.type)}</div>
                    </div>
                  </div>
                  <div class="br-kvgrid">${kv('Email', esc(cur.email))}${kv('Phone', esc(cur.phone))}</div>
                  ${idBlock()}
                </div>
              </div>

              <div class="br-card">
                <div class="br-sec">
                  <h2 class="br-h2">Reservation</h2>
                  ${isHostel() ? `
                  <div class="br-kvgrid">
                    ${kv('Room', esc(cur.room) + ' <span class="soft">· ' + esc(cur.crType) + '</span>')}${kv('Venue', esc(cur.venue))}
                    ${kv('Check-in', esc(cur.checkIn))}${kv('Check-out', esc(cur.checkOut))}
                    ${kv('Stay', cur.nights + ' night' + (cur.nights > 1 ? 's' : '') + ' <span class="soft">(check-out is not a night)</span>')}${kv('Beds', bedCount() + ' <span class="soft">(counted from the roster below)</span>')}
                    ${kv('Total', esc(cur.total) + ' <span class="soft">(' + bedCount() + ' × ' + esc(cur.rate) + '/head × ' + cur.nights + ' night' + (cur.nights > 1 ? 's' : '') + ')</span>', true)}
                  </div>
                  ${rosterBlock()}` : `
                  <div class="br-kvgrid">
                    ${kv('Room', esc(cur.room))}${kv('Venue', esc(cur.venue))}
                    ${kv(cur.days > 1 ? 'Dates' : 'Date', esc(cur.dates))}${kv('Attendees', cur.attendees + ' / ' + cur.capacity + (over ? ' <span style="color:#8a5a12">· over capacity</span>' : ''))}
                    ${kv('Fee', esc(cur.total) + ' <span class="soft">(' + esc(cur.feeDay) + '/day × ' + cur.days + ')</span>')}${kv('Pay-by rule', esc(cur.payBy) + ' <span class="soft">(1 day before the event)</span>')}
                  </div>
                  <div style="margin-top:0.8rem">
                    <div class="br-h2" style="margin-bottom:0.45rem">Daily schedule</div>
                    <div class="br-sched">
                      ${cur.sched.map(s => s.skip
                        ? `<div class="d skip"><span><s>${esc(s.d)}</s></span><span>${esc(s.t)}</span></div>`
                        : `<div class="d"><span>${esc(s.d)}</span><span style="font-weight:600">${esc(s.t)}</span></div>`).join('')}
                    </div>
                  </div>`}
                </div>
              </div>
            </div>

            <div>
              <div class="br-card">
                <div class="br-sec">
                  <h2 class="br-h2">Payment · ${isHostel()
                    ? (cur.method === 'cash' ? 'Cash to staff' : 'GCash · staff account') + ' <span class="soft">via CEDU</span>'
                    : (cur.method === 'cash' ? 'Cash (walk-in)' : 'GCash')}</h2>
                  ${payBody()}
                  ${orBlock()}
                </div>
              </div>

              <div class="br-card">
                <div class="br-sec">
                  <h2 class="br-h2">Timeline</h2>
                  <ul class="br-tl">
                    ${cur.tl.map((e, i) => `<li${i === cur.tl.length - 1 ? ' class="hi"' : ''}><span class="p"></span><div class="w">${esc(e.w)}</div><div class="x">${esc(e.x)}</div><div class="m">${esc(e.m)}</div></li>`).join('')}
                  </ul>
                </div>
              </div>
            </div>
          </div>`;
      }

      /* demo actions — update statuses + timeline in-page (no persistence) */
      function now() { return 'Jul 14, 2026 — You (staff)'; }
      function approveIdRes() {
        cur.idStatus = 'approved'; cur.res = 'approved';
        /* HOSTEL: approving does NOT unlock payment. The POS has to come back
           from CEDU first — that is the extra gate the venue flow does not have. */
        if (cur.kind === 'hostel') {
          cur.pay = 'await_pos';
          cur.tl.push({ w: now(), x: 'ID + reservation approved', m: 'Payment stays locked until the POS comes back from CEDU.' });
          render(); return;
        }
        cur.pay = cur.method === 'cash' ? 'await_cash' : 'await_gcash';
        cur.tl.push({ w: now(), x: 'ID + reservation approved', m: 'Payment unlocked. Pay-by deadline ' + cur.payBy + ' (1 day before the event).' });
        render();
      }
      /* [SIM] staff came back from CEDU with the POS — this is the moment the
         customer becomes able to pay at all. */
      function recordPos() {
        const box = document.getElementById('posNo');
        const val = box ? box.value.trim() : '';
        if (!val) { cur.posErr = 'Enter the POS number CEDU issued — payment cannot open without it.'; render(); return; }
        cur.posErr = null;
        cur.pos = val;
        cur.pay = cur.method === 'cash' ? 'await_cash' : 'await_gcash';
        cur.tl.push({ w: now(), x: 'POS received from CEDU', m: val + ' recorded. Payment unlocked for the guest.' });
        render();
      }
      /* [SIM] the cashier issued the OR — AFTER the booking was already
         confirmed. Nothing about the payment changes here. */
      function recordOr() {
        const box = document.getElementById('orNo');
        const val = box ? box.value.trim() : '';
        if (!val) return;
        cur.or = val;
        cur.tl.push({ w: now(), x: 'Official Receipt recorded', m: val + ' issued by the University Cashier. Emailed to the guest; needed at check-in.' });
        render();
      }
      /* [SIM] the guest arrived and showed their POS + OR. Hostel-only. */
      function checkIn() {
        cur.checkedIn = true;
        cur.tl.push({ w: now(), x: 'Guest checked in', m: 'POS ' + cur.pos + ' and OR ' + cur.or + ' presented and matched against the valid ID.' });
        render();
      }
      function rejectRequest() {
        cur.res = 'released'; cur.pay = 'locked';
        cur.tl.push({ w: now(), x: 'Request rejected', m: 'Reservation released back to availability. Customer notified.' });
        render();
      }
      function confirmPay() {
        const cash = cur.method === 'cash';
        cur.pay = cash ? 'paid_cash' : 'confirmed';
        cur.confirmedBy = 'You · just now';
        cur.tl.push({ w: now(), x: cash ? 'Cash payment recorded' : 'Payment confirmed', m: cash ? 'Recorded at the cashier; official receipt issued.' : 'Reference matched in GCash Transaction History. Reference locked.' });
        render();
      }
      function rejectPay() {
        cur.pay = 'rejected';
        cur.rejects = ['Rejected by staff after checking GCash — the reference was not found in the Transaction History (reference freed, record kept for audit).'];
        cur.resubmitBy = 'Jul 16, 2026 · ' + (cur.resubmitBy ? cur.resubmitBy.split('· ')[1] || '5:00 PM' : '5:00 PM');
        cur.tl.push({ w: now(), x: 'Receipt rejected', m: '48-hour resubmit window started; customer notified.' });
        render();
      }
      function overrideConfirm() {
        cur.ovrRef = (document.getElementById('ovrRef') || {}).value || '';
        cur.ovrNote = (document.getElementById('ovrNote') || {}).value || '';
        if (!cur.ovrRef.trim() || !cur.ovrNote.trim()) { cur.ovrErr = 'Both the verified reference number and a note are required.'; render(); return; }
        cur.ovrErr = ''; cur.pay = 'confirmed'; cur.confirmedBy = 'You (override) · just now';
        cur.tl.push({ w: now(), x: 'Override — payment confirmed manually', m: 'Verified ref ' + cur.ovrRef.trim() + ' in GCash. Note: ' + cur.ovrNote.trim() + ' (Auto-check verdict kept on record.)' });
        render();
      }
      function releaseSlot() {
        cur.res = 'released';
        cur.tl.push({ w: now(), x: 'Slot released', m: 'Payment deadline passed; the dates are bookable again. Customer notified.' });
        render();
      }
      function extendDeadline() {
        cur.pay = cur.method === 'cash' ? 'await_cash' : 'await_gcash';
        cur.payBy = 'Jul 16, 2026 (extended +48 h)';
        cur.tl.push({ w: now(), x: 'Deadline extended 48 hours', m: 'New pay-by: Jul 16, 2026. Customer notified.' });
        render();
      }
      function markRefunded() {
        cur.pay = 'refund_done'; cur.confirmedBy = 'You · just now';
        cur.tl.push({ w: now(), x: 'Refund processed', m: 'Both receipts verified; refund recorded on this booking.' });
        render();
      }
      function denyRefund() {
        cur.pay = 'refund_denied'; cur.denyReason = 'Requirements not met after verification.';
        cur.tl.push({ w: now(), x: 'Refund denied', m: 'Verification failed; customer notified with the reason.' });
        render();
      }

      render();
    </script>
  </body>
</html>
