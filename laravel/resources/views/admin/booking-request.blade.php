{{-- BOOKING REQUEST DETAIL (was admin/booking-request.php). The whole view is drawn by the script from DATA (every booking, keyed by reference); ?id= picks one. --}}
@extends('layouts.admin')
@section('title', 'Booking Request Detail')
@section('active', 'Booking Management')
@push('head')
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
@endpush
@push('styles')
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

      /* GCash upload history — every attempt, rejected ones too (DB-DECISIONS #6) */
      .br-att { border-top: 1px solid var(--vm-hairline); margin-top: 0.95rem; padding-top: 0.85rem; }
      .br-att-head { align-items: baseline; display: flex; gap: 0.6rem; justify-content: space-between; margin-bottom: 0.5rem; }
      .br-att-count { font-size: 0.76rem; font-weight: 600; }
      .br-att-row { align-items: flex-start; display: flex; gap: 0.7rem; padding: 0.55rem 0; }
      .br-att-row + .br-att-row { border-top: 1px solid var(--vm-hairline); }
      .br-att-row img { background: #f4f4f4; border: 1px solid var(--vm-border); border-radius: 6px; flex: none; height: 52px; object-fit: cover; width: 40px; }
      .br-att-no { color: var(--vm-muted); flex: none; font-size: 0.72rem; font-weight: 600; min-width: 4.2rem; padding-top: 0.15rem; }
      .br-att-main { flex: 1; font-size: 0.78rem; line-height: 1.5; min-width: 0; overflow-wrap: anywhere; }
      .br-att-main .f { color: var(--vm-muted); font-size: 0.72rem; }
      .br-vd { border-radius: 999px; display: inline-block; font-size: 0.68rem; font-weight: 650; margin-right: 0.35rem; padding: 0.08rem 0.5rem; }
      .br-vd.accepted { background: #e7f4ec; color: #1c7a4f; }
      .br-vd.manual_review { background: #fbf1dd; color: #8a5a12; }
      .br-vd.rejected { background: #fbe6e6; color: #b23a3a; }

      .br-ovr { border: 1px dashed #d9d7d2; border-radius: 11px; margin-top: 0.95rem; padding: 0.9rem 0.95rem; }
      .br-ovr input, .br-ovr textarea { border: 1px solid var(--vm-border); border-radius: 8px; font-family: inherit; font-size: 0.82rem; padding: 0.5rem 0.62rem; width: 100%; }
      .br-ovr input:focus, .br-ovr textarea:focus { border-color: #b9b5ad; outline: none; }
      .br-ovr textarea { resize: vertical; }
      .br-ovr select { background: #fff; border: 1px solid var(--vm-border); border-radius: 8px; font-family: inherit; font-size: 0.82rem; padding: 0.5rem 0.62rem; width: 100%; }
      .br-ovr select:focus { border-color: #b9b5ad; outline: none; }
      /* stacked label-over-control, so a staff decision reads top to bottom */
      .br-fld { margin-bottom: 0.65rem; }
      .br-fld label { color: var(--vm-label); display: block; font-size: 0.64rem; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 0.25rem; text-transform: uppercase; }
      .br-preview { background: #faf9f7; border: 1px solid var(--vm-hairline); border-radius: 8px; color: var(--vm-muted); font-size: 0.76rem; line-height: 1.5; margin-bottom: 0.65rem; padding: 0.5rem 0.62rem; }
      .br-preview b { color: var(--vm-text); font-weight: 600; }

      /* RECEIPT & EMAILS card (DB-DECISIONS #22) — what was emailed, Resend, the receipt PDF */
      .br-mail-to { color: var(--vm-muted); font-size: 0.76rem; font-weight: 500; letter-spacing: 0; text-transform: none; float: right; }
      .br-mail { align-items: center; border-top: 1px solid var(--vm-hairline); display: grid; gap: 0.2rem 0.9rem; grid-template-columns: 1fr auto; padding: 0.75rem 0; }
      .br-mail:first-of-type { border-top: 0; padding-top: 0; }
      .br-mail .what { font-size: 0.86rem; font-weight: 600; }
      .br-mail .what .soft { color: var(--vm-muted); font-weight: 400; }
      .br-mail .meta { color: var(--vm-muted); font-size: 0.75rem; grid-column: 1; }
      .br-mail .err { color: #b23a3a; font-size: 0.75rem; grid-column: 1 / -1; }
      .br-mail .side { align-items: center; display: inline-flex; gap: 0.5rem; grid-column: 2; grid-row: 1 / span 2; }
      .br-mail-note { color: #4a4440; display: flex; font-size: 0.8rem; gap: 0.55rem; line-height: 1.5; margin-bottom: 0.75rem; }
      .br-mail-note i { color: var(--vm-muted); }
      .br-rcpt { align-items: center; background: #faf9f7; border: 1px solid var(--vm-hairline); border-radius: 10px; display: flex; flex-wrap: wrap; font-size: 0.82rem; font-weight: 600; gap: 0.6rem; justify-content: space-between; margin-top: 0.85rem; padding: 0.6rem 0.75rem; }
      .br-rcpt a { text-decoration: none; }
      .br-mail-flash { color: var(--vm-muted); font-size: 0.76rem; margin-top: 0.6rem; }
    </style>
@endpush
@section('content')
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="br-page" id="brqRoot"></div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    {!! $pricingJs !!}
    <!-- Tesseract + the shared GCash engine. The SAME checker the customer uses
         to prove they paid in; here it proves staff paid out. It needs no changes
         for that — it asks the page whose account the money must land in, and
         this page answers "the customer's" (see gcAccount below). -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    {!! $gcashCheckerJs !!}
    <script>
      /* Booking Request detail — one full window per request (mockup).
         Reads ?id=BRQ-xxxx; actions update badges + timeline in-page (demo only).
         Statuses follow the agreed payment-status taxonomy. */
      /* Badges for EVERY status the database defines — status_badge_maps() in
         includes/bookings.php reads the lookup tables. These were hand-written
         here and covered three reservation codes out of seven: opening a
         completed, cancelled, rejected or disrupted booking threw on the
         undefined lookup below and left staff a blank page.

         Two states worth naming: `await_pos` is HOSTEL ONLY and is the one
         waiting state that waits on another OFFICE rather than the customer,
         and `refund_correction` is NOT a denial — it is a paperwork problem
         handed back with a window to fix it (agreed 2026-09-09: denial is
         final, so a blurry receipt must never be one). */
      const RES = {!! json_encode(status_badge_maps()['res'], JSON_UNESCAPED_UNICODE) !!};
      const PAY = {!! json_encode(status_badge_maps()['pay'], JSON_UNESCAPED_UNICODE) !!};

      /* Last line of defence: a code with no badge must still open the page. */
      function resBadge(code) { return RES[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }
      function payBadge(code) { return PAY[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }
      /* The header's payment wording. The hostel is paid before the bed is used,
         and takes its cash at the front desk, not the University Cashier (#26). */
      function payText() {
        if (cur.payToUse && cur.pay === 'await_gcash') return 'Payment due before check-in';
        if (cur.kind === 'hostel' && cur.pay === 'paid_cash') return {!! json_encode(HOSTEL_PAID_CASH_LABEL) !!};
        return payBadge(cur.pay).t;
      }

      /* A refund claim that is still OPEN — staff can still decide it. All four
         map to the same panel and the same three outcomes; the difference
         between them is only what the customer is waiting for. The server
         re-checks the refund row before recording anything (booking-action.php),
         so this list decides what is OFFERED, never what is allowed. */
      const OPEN_REFUND = ['refund_requested', 'refund_await_or', 'refund_processing', 'refund_correction'];

      /* Reservations nobody can act on any more — the event happened, the
         request was refused, or the dates went back on sale. */
      const CLOSED_RES = ['completed', 'cancelled', 'rejected', 'released'];

      /* PAYMENT TIMING follows the refund switch — the shared rule from
         includes/refund-policy.php (same as every customer page). Rows carry
         startIso/endIso so approve() can compute the pay-by for either policy. */
      {!! payment_policy_js() !!}
      /* EVERY request, from the database, keyed by its real reference. */
      const DATA = {!! json_encode($brqData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!};
      const CSRF = {!! json_encode(csrf_token()) !!};

      /* ============================================================
         STAFF ACTIONS — every one of them goes to admin/booking-action.php.

         These used to change `cur` in the browser and re-render, which looked
         identical but persisted nothing: approving a booking left the customer
         still seeing "Pending", and a refresh undid it. Now the server decides
         what the booking becomes (sp_approve_booking knows a pre-pay hostel
         approves into await_pos, a post-pay booking into await_event), and the
         reply is what gets drawn — so the screen can never claim a state the
         database did not reach.
         ============================================================ */
      let acting = false;
      async function staffAction(action, extra, onOk) {
        if (acting || !cur) return false;
        acting = true;
        const body = new URLSearchParams(Object.assign({ _token: CSRF, booking: cur.id, action: action }, extra || {}));
        try {
          const res = await fetch({!! json_encode(route('admin.booking-action')) !!}, { method: 'POST', body: body, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) {
            cur.actionError = out.message || 'Nothing was changed.';
            if (out.booking && out.booking.pay) cur.pay = out.booking.pay;   /* the booking moved on since this page loaded */
            acting = false; render(); return false;
          }
          cur.actionError = null;
          if (out.booking) {
            cur.res = out.booking.res;
            cur.pay = out.booking.pay;
            cur.total = out.booking.total;
            cur.discountPct = out.booking.discount;
            cur.payBy = out.booking.payBy;
            cur.graceEnds = out.booking.graceEnds;
            /* the timeline exactly as a reload shows it — real time, real actor */
            if (out.booking.tl) cur.tl = out.booking.tl;
          }
          if (typeof onOk === 'function') onOk(out);
          acting = false; render(); return true;
        } catch (e) {
          cur.actionError = 'Could not reach the server, so nothing was changed.';
          acting = false; render(); return false;
        }
      }

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      const qid = new URLSearchParams(location.search).get('id');

      /* A refund the customer filed used to have no record here — it lived in
         includes/refund-store.php, browser storage shared between two mockups
         and invisible on any other machine. It is a `refunds` row now, joined
         into DATA above, so a refund opens like any other request.

         An unknown reference shows the "not found" state rather than falling
         through to some other booking: silently showing staff a DIFFERENT
         customer's request is the one failure this page must never have. */
      const cur = DATA[qid] || null;
      const missingCustomerRef = !cur && !!qid;

      /* ---- GCASH CHECKER WIRING FOR THE REFUND PAYOUT (staff → customer) ----
         The shared engine asks the page three questions. For a payment the money
         must land in the VENUE's account; for a refund it must land in the
         CUSTOMER's. Same engine, different answers — that is the whole reason it
         needs no changes to run in this direction.

         The destination is the account that PAID: staff read it from the venue's
         GCash Transaction History (where they already go to confirm payments) and
         enter it here. The customer is never asked, so nobody can be talked into
         redirecting a refund. Losing access to that account is a staff-handled
         exception against the ID already on file (PROJECT-HANDOFF 4.10). */
      const state = { ocr: null };                     /* the engine's receipt slot */
      /* For a PAYOUT, "not rejected" is not good enough. receiptOk() means
         "not rejected", which since DB-DECISIONS #6 includes manual_review — and
         manual_review is precisely a wrong amount or a wrong recipient. On the
         customer side that is fine (staff review it afterwards); here STAFF are
         the reviewer, so only a fully clean receipt counts as verified. Anything
         less demands a written reason. */
      function payoutVerified() {
        return !!(state.ocr && state.ocr.phase === 'done' && state.ocr.rec && state.ocr.rec.status === 'accepted');
      }
      function refundCentavos() {
        const s = String((cur && cur.total) || "").replace(/[^0-9.]/g, "");
        const n = parseFloat(s);
        return isFinite(n) ? Math.round(n * 100) : 0;
      }
      /* Same normalisation the customer side and the server use, so a number is
         compared as a NUMBER: "0917 555 0123" and "09175550123" are never
         reported as different. Returns null if it cannot be a PH mobile. */
      function normMobile(raw) {
        let d = String(raw || "").replace(/\D+/g, "");
        if (d.length === 12 && d.slice(0, 2) === "63") d = "0" + d.slice(2);
        if (d.length === 10 && d.charAt(0) === "9")    d = "0" + d;
        return (d.length === 11 && d.slice(0, 2) === "09") ? d : null;
      }
      /* What staff will actually SEND to: their own entry if they typed one, else
         the number the CUSTOMER declared on the refund form, else the number on
         file. cur.refundTo is the declaration and is never overwritten — the
         panel compares the two and shouts when they differ. */
      function payoutTarget() {
        if (cur.payoutTo != null) return cur.payoutTo;
        return normMobile(cur.refundTo) || normMobile(cur.phone) || "";
      }
      function gcAccount()          { return { name: (cur && cur.name) || "", number: normMobile(payoutTarget()) || "" }; }
      function gcExpectedCentavos() { return refundCentavos(); }
      function gcBookingRef()       { return (cur && cur.id) || ""; }

      function openPayout()  { cur.payoutOpen = true;  cur.payoutErr = null; render(); }
      function closePayout() { cur.payoutOpen = false; cur.payoutErr = null; state.ocr = null; render(); }
      function setPayoutTo(v){ cur.payoutTo = v; }

      /* [SIM] Nobody is sending real money to produce a test screenshot, so these
         feed SYNTHETIC receipt text through the REAL parser and the REAL verdict
         engine. What you see is the engine's own answer, not a canned one — which
         also makes this the test harness for the three-verdict rule. */
      /* Where the destination came from, and a loud warning when the customer's
         declared account is NOT the one on their record — that is exactly what a
         redirected refund looks like, and it is the one thing software can flag
         but only a person can resolve. */
      function destinationNote() {
        const declared = normMobile(cur.refundTo);
        const onFile   = normMobile(cur.phone);
        const target   = normMobile(payoutTarget());
        const fmt  = function (n) { return n ? n.replace(/^(\d{4})(\d{3})(\d{4})$/, "$1 $2 $3") : "—"; };
        const line = function (c, t) { return `<div style="font-size:0.74rem;line-height:1.5;color:${c};margin-top:0.35rem">${t}</div>`; };
        if (!target) return line('#b23a3a', 'Enter the number before attaching a receipt — the check has nothing to compare against without it.');
        let out = "";
        if (declared && target !== declared)      out += line('#8a5a12', '<strong>You changed this.</strong> The customer asked for it to go to ' + fmt(declared) + '.');
        else if (declared)                        out += line('#8a857d', 'Given by the customer on their refund request.');
        else                                      out += line('#8a857d', 'No number was declared on the request — this is the number on their account.');
        if (declared && onFile && declared !== onFile)
          out += line('#b23a3a', '&#9888; <strong>This is NOT the number on their account</strong> (' + fmt(onFile) + '). Check it against the sender on the original payment before sending.');
        else if (declared && onFile)
          out += line('#1c7a4f', '&#10003; Matches the number on their account.');
        return out;
      }
      function simulateReceipt(kind) {
        if (!normMobile(payoutTarget())) { cur.payoutErr = 'Enter the GCash number first — without it the check has nothing to compare the receipt against.'; render(); return; }
        const acct = gcAccount();
        const owed = refundCentavos();
        const num  = kind === 'wrongnum' ? '09991112222' : (acct.number || '09171234567');
        const amt  = ((kind === 'wrongamt' ? Math.round(owed / 2) : owed) / 100).toFixed(2);
        const ref  = String(Date.now()).slice(-13);
        const text = kind === 'unreadable' ? "" : [
          'Sent via GCash', 'JU....L D.. C.', num, 'Ref No. ' + ref,
          'Sep 09, 2026 10:12 AM', 'Total Amount Sent PHP ' + amt, 'Amount PHP ' + amt
        ].join('\n');
        state.ocr = { phase: 'done', pct: 1, label: 'Simulated', fileName: '[SIM] ' + kind + '.png', thumb: null,
                      rec: evaluateReceipt({ text: text, sha: 'sim-' + ref, confidence: kind === 'unreadable' ? 12 : 93 }, owed) };
        render();
      }

      async function completeRefund() {
        const note = ((document.getElementById('payoutNote') || {}).value || "").trim();
        const to   = normMobile(payoutTarget());
        if (!to) { cur.payoutErr = 'Enter the GCash number the refund was sent to — it is the account that paid.'; render(); return; }
        /* THE OR RULE, enforced rather than merely asked about: no Official Receipt,
           no payout. An exception is allowed, but it costs a written reason — the
           same shape as the GCash override above, not a dialog anyone clicks past. */
        if (cur.refund && cur.refund.orPending && !note) {
          cur.payoutErr = 'The Official Receipt has not been provided, and a refund cannot be paid without it. If you are recording an exception, write why in the note.';
          render(); return;
        }
        if (!payoutVerified() && !note) {
          cur.payoutErr = 'Attach a receipt that verifies, or write a note explaining why you are recording this refund without one.';
          render(); return;
        }
        const rec = state.ocr && state.ocr.rec;
        const refNo = (rec && (rec.parsed.refDisplay || rec.parsed.ref)) || null;
        /* Write FIRST and check it landed. The customer can withdraw while this
           page is open; showing "Refunded" for a request that no longer exists
           would be a lie staff then act on. */
        const wrote = await pushOutcome('refunded', 'Refund sent to ' + to + (refNo ? ' · GCash ref ' + refNo : "") + (note ? ' · ' + note : ""), {
          reference: refNo, amount: cur.total, amountValue: refundCentavos() / 100, to: to,
          receiptFile: (state.ocr && state.ocr.fileName) || null,
          verified: payoutVerified()
        });
        if (!wrote) {
          cur.payoutErr = 'This request is no longer open — the customer withdrew it while this page was open. Nothing has been recorded. Go back to the queue.';
          render(); return;
        }
        if (rec) gcRemember(rec);        /* consume ref + file hash only once the refund really landed */
        cur.pay = 'refunded'; cur.confirmedBy = 'You · just now';
        cur.payoutOpen = false; cur.payoutErr = null;
        state.ocr = null;
        render();
      }

      /* Money compared as money. "₱1,500" and "₱1,500.00" are the same amount;
         comparing them as strings reported a false mismatch on a correct receipt. */
      function pesoCentavos(v) {
        const n = parseFloat(String(v == null ? "" : v).replace(/[^0-9.]/g, ""));
        return isFinite(n) ? Math.round(n * 100) : null;
      }
      function amountVerdict(read, required) {
        const a = pesoCentavos(read), b = pesoCentavos(required);
        if (a == null || b == null) return ' <span style="color:#8a857d">· could not compare</span>';
        if (a === b) return ' <span style="color:#1c7a4f">· matches</span>';
        return ' <span style="color:#b23a3a">· ' + (a < b ? 'SHORT by ' : 'OVER by ') + centavosFmt(Math.abs(b - a)) + '</span>';
      }
      function kv(k, v, wide) { return `<div class="br-kv${wide ? ' kv-wide' : ''}"><div class="k">${k}</div><div class="v">${v}</div></div>`; }
      /* Post-pay: due the day after the event (cur.payBy), Overdue only once the
         grace days have passed (cur.graceEnds) — Aron, 2026-10-30. */
      function graceKv() { return cur.postpay && cur.graceEnds ? kv('Overdue after', esc(cur.graceEnds)) : ''; }
      function banner(cls, title, sub) { return `<div class="br-banner ${cls}"><div>${title}${sub ? `<span class="s">${sub}</span>` : ''}</div></div>`; }
      function flags(list, color) { return list.map(f => `<div class="br-flag"><span class="dot" style="background:${color}"></span><span>${esc(f)}</span></div>`).join(''); }
      function initials(n) { return n.split(' ').map(w => w[0]).slice(0, 2).join(''); }
      /* The customer's own words, from customer/refund-request.php. Staff decide
         on this, so it belongs on screen rather than buried in the timeline. */
      /* Why a request goes back, or gets refused. These are STAFF vocabulary made
         explicit: window.prompt asked them to invent the wording every time, which
         is slow, inconsistent, and the customer reads the result. Returning is a
         PAPERWORK outcome only — nothing here questions the claim itself. */
      const FIX_REASONS = [
        'The image is blurry or cannot be read',
        'The wrong document was attached',
        'Part of the document is cut off or missing',
        'The details do not match this booking (name, amount, or reference)',
        'The document appears to have been edited',
        'The Official Receipt has not been provided yet',
        'Other — see the note below',
      ];
      /* Denial is FINAL, so every entry here is about the CLAIM, never the paper. */
      const DENY_REASONS = [
        'The reason given is not covered by the refund policy',
        'The request was made after the event had already taken place',
        'The payment could not be verified in our records',
        'The documents were still not valid after being returned for correction',
        'This booking has already been refunded',
        'Other — see the note below',
      ];
      function fixDocOptions() {
        const docs = ['VENUSeP System Receipt'];
        if (cur.method === 'gcash') docs.push('GCash Payment Receipt');
        docs.push('Official Receipt (OR)');
        return docs;
      }
      /* Inline panel, same pattern as the GCash override block above — this page
         has no modals and should not grow one for this. */
      /* The payout panel. Staff send the money in GCash FIRST, then prove it here
         — the receipt is what completes the refund, not a bare button. */
      function payoutPanel() {
        if (!cur.payoutOpen) return "";
        const o = state.ocr, rec = o && o.rec;
        const tone = !rec ? "" : (rec.status === 'rejected' ? 'bn-red' : (rec.status === 'manual_review' ? 'bn-amber' : 'bn-green'));
        const head = !rec ? "" : (rec.status === 'rejected' ? 'This receipt does not match'
                        : (rec.status === 'manual_review' ? 'Receipt needs a closer look' : 'Receipt verified'));
        const why  = !rec ? "" : (rec.reasons.concat(rec.flags).map(function (k) { return GC_LABELS[k] || k; }).join(' · ')
                        || 'Amount and recipient match the approved refund.');
        const verdict = !rec ? "" : banner(tone, head, why)
          + `<div class="br-kvgrid" style="margin-bottom:0.7rem">
               ${kv('GCash ref no.', esc(rec.parsed.refDisplay || rec.parsed.ref || '—'))}
               ${kv('Amount on receipt', rec.parsed.effAmountC != null ? esc(centavosFmt(rec.parsed.effAmountC)) : '—')}
               ${kv('Sent to', esc(rec.parsed.receiverNumber || '—'))}
               ${kv('Receipt file', esc((o && o.fileName) || '—'))}
             </div>`;
        const busy = o && o.phase === 'reading';
        return `<div class="br-ovr" style="margin-top:0.9rem">
            <div style="font-size:0.82rem;font-weight:650;margin-bottom:0.35rem">Record the refund payout</div>
            <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.55;margin-bottom:0.8rem">
              Send the money in GCash first, then attach the receipt. It must go back to the account that <strong>paid</strong> — you can see the sender on the original transaction in the venue GCash history. The check <strong>advises</strong>: if it cannot read the screenshot you can still record the refund with a note.
            </div>
            <div class="br-fld">
              <label for="payoutTo">Refund sent to (GCash number)</label>
              <input id="payoutTo" placeholder="09XX XXX XXXX — the account that paid" value="${esc(payoutTarget())}" oninput="setPayoutTo(this.value)">
              ${destinationNote()}
            </div>
            <div class="br-fld">
              <label>Amount to send</label>
              <div style="font-size:1.05rem;font-weight:700">${esc(cur.total)}</div>
            </div>
            <input type="file" id="gcFile" accept="image/*" style="display:none" onchange="checkReceipt(this)">
            <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.7rem">
              <button class="br-btn" onclick="pickReceipt()" ${busy ? 'disabled' : ''}>${o ? 'Choose a different screenshot' : 'Attach the GCash receipt'}</button>
              ${o ? `<button class="br-btn" onclick="removeReceipt()">Remove</button>` : ""}
            </div>
            ${busy ? `<div style="font-size:0.76rem;color:var(--vm-muted);margin-bottom:0.7rem" id="gcBarLabel">${esc(o.label)}</div>` : ""}
            ${verdict}
            <div style="border-top:1px dashed var(--vm-border);margin:0.8rem 0 0.7rem;padding-top:0.7rem">
              <div style="font-size:0.7rem;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;color:var(--vm-label);margin-bottom:0.4rem">[SIM] Simulate a receipt — runs the real engine</div>
              <div style="display:flex;gap:0.4rem;flex-wrap:wrap">
                <button class="br-btn" onclick="simulateReceipt('pass')">Passes</button>
                <button class="br-btn" onclick="simulateReceipt('wrongamt')">Wrong amount</button>
                <button class="br-btn" onclick="simulateReceipt('wrongnum')">Wrong number</button>
                <button class="br-btn" onclick="simulateReceipt('unreadable')">Unreadable</button>
              </div>
            </div>
            <div class="br-fld">
              <label for="payoutNote">Note ${payoutVerified() ? '(optional)' : '(required if the receipt does not verify)'}</label>
              <textarea id="payoutNote" rows="2" placeholder="e.g. screenshot unreadable — payment confirmed in the GCash transaction history.">${esc(cur.payoutNote || "")}</textarea>
            </div>
            ${cur.payoutErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-bottom:0.55rem">${esc(cur.payoutErr)}</div>` : ""}
            <div style="display:flex;gap:0.5rem">
              <button class="br-btn br-btn-primary" onclick="completeRefund()">Complete refund</button>
              <button class="br-btn" onclick="closePayout()">Cancel</button>
            </div>
          </div>`;
      }
      function actionPanel() {
        if (!cur.actionMode) return "";
        const deny = cur.actionMode === 'deny';
        const reasons = deny ? DENY_REASONS : FIX_REASONS;
        const blurb = deny
          ? 'A denial is <strong>final</strong> — the customer would have to book again from scratch. Their booking itself is not affected. Be plain about why: they read this.'
          : 'This is <strong>not</strong> a denial. The customer keeps their booking and has <strong>48 hours</strong> to send a corrected document. Tell them exactly what to fix.';
        const docField = deny ? "" : `<div class="br-fld">
                <label for="actDoc">Which document</label>
                <select id="actDoc">${fixDocOptions().map(d => `<option${cur.actDoc === d ? ' selected' : ''}>${esc(d)}</option>`).join("")}</select>
              </div>`;
        const preview = cur.actReason
          ? `<div class="br-preview">The customer will see: <b>${esc((deny ? "" : (cur.actDoc || fixDocOptions()[0]) + ' — ') + cur.actReason)}</b>${cur.actNote ? esc(' — ' + cur.actNote) : ""}</div>`
          : "";
        return `<div class="br-ovr" style="margin-top:0.9rem">
              <div style="font-size:0.82rem;font-weight:650;margin-bottom:0.35rem">${deny ? 'Deny this refund' : 'Return this request for correction'}</div>
              <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.55;margin-bottom:0.8rem">${blurb}</div>
              ${docField}
              <div class="br-fld">
                <label for="actReason">${deny ? 'Why is it being denied' : 'What is wrong with it'}</label>
                <select id="actReason" onchange="refreshAction()">
                  <option value="">Choose a reason…</option>
                  ${reasons.map(r => `<option${cur.actReason === r ? ' selected' : ''}>${esc(r)}</option>`).join("")}
                </select>
              </div>
              <div class="br-fld">
                <label for="actNote">Note to the customer ${deny || (cur.actReason || "").indexOf('Other') === 0 ? '(required)' : '(optional)'}</label>
                <textarea id="actNote" rows="2" placeholder="${deny ? 'Explain the decision in plain language.' : 'Anything else they need to know — which page is missing, what to re-scan.'}">${esc(cur.actNote || "")}</textarea>
              </div>
              ${preview}
              ${cur.actErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-bottom:0.55rem">${esc(cur.actErr)}</div>` : ""}
              <div style="display:flex;gap:0.5rem">
                <button class="br-btn br-btn-primary" onclick="submitAction()">${deny ? 'Deny refund' : 'Send back to the customer'}</button>
                <button class="br-btn" onclick="closeAction()">Cancel</button>
              </div>
            </div>`;
      }
      function refundReasonBlock() {
        if (!cur.refund || !cur.refund.reason) return "";
        return `<div style="margin-top:1rem;padding-top:0.9rem;border-top:1px solid var(--vm-hairline)">
            <div class="br-h2" style="margin-bottom:0.5rem">Why the customer asked</div>
            <div style="font-size:0.87rem;font-weight:600;margin-bottom:0.3rem">${esc(cur.refund.reason)}</div>
            <div style="font-size:0.83rem;line-height:1.6;color:var(--vm-muted)">${esc(cur.refund.details || "")}</div>
          </div>`;
      }

      /* Not before the first night of the stay (Aron, 2026-10-30) — the server refuses it too. */
      function checkInAction() {
        return cur.checkInOpen
          ? `<button class="br-btn br-btn-primary" onclick="checkIn()">Check the guest in</button>`
          : `<span class="none">Paid &mdash; check-in opens on ${esc(cur.checkIn)}</span>`;
      }

      /* which buttons make sense right now (reservation-level, in the header) */
      function hdrActions() {
        /* A finished booking is a RECORD, not a task. Most bookings in the
           system are `completed`, and offering them the live queue's buttons
           would invite staff to act on something that is already over. An open
           refund is the exception: the booking is cancelled, but the claim it
           was cancelled for can still be decided. So is an overdue payment on a
           completed (post-pay) booking: its deadline can be extended (Aron, 2026-10-30). */
        const overdueAfterEvent = cur.res === 'completed' && cur.pay === 'overdue';
        if (CLOSED_RES.includes(cur.res) && !OPEN_REFUND.includes(cur.pay) && !overdueAfterEvent) {
          return `<span class="none">${esc(resBadge(cur.res).t)} &middot; this booking is closed &mdash; nothing left to act on</span>`;
        }
        if (cur.res === 'disrupted') {
          return `<span class="none">The room was closed after this was approved &mdash; the customer has to be moved or refunded</span>`;
        }
        /* Three outcomes when the customer claims affiliation, because approving
           the ID and granting the discount are different judgements. When they
           have not claimed it, there is nothing to decide — one approve button. */
        if (cur.res === 'pending') return (claimsAffiliation()
            ? `<button class="br-btn br-btn-primary" onclick="approveIdRes(true)">Approve with ${DISCOUNT_PERCENT}% discount</button><button class="br-btn" onclick="approveIdRes(false)">Approve at full price</button>`
            : `<button class="br-btn br-btn-primary" onclick="approveIdRes(false)">Approve ID &amp; reservation</button>`)
          + `<button class="br-btn" onclick="rejectRequest()">Reject request</button>`;
        /* A post-pay booking is never released: its event already happened (Aron, 2026-10-30). */
        if (cur.pay === 'overdue') return cur.postpay || overdueAfterEvent
            ?`<button class="br-btn br-btn-primary" onclick="extendDeadline()">Extend 48 h</button>`
            : `<button class="br-btn br-btn-primary" onclick="releaseSlot()">Release the slot</button><button class="br-btn" onclick="extendDeadline()">Extend 48 h</button>`;
        if (OPEN_REFUND.includes(cur.pay)) return `<button class="br-btn br-btn-primary" onclick="openPayout()">Mark refunded</button><button class="br-btn" onclick="openAction('fix')">Return for correction</button><button class="br-btn" onclick="openAction('deny')">Deny refund</button>`;
        /* PAY TO USE (2026-10-27): no bed until the stay is paid. A receipt
           still under review is not paid; staff confirm it first. The OR is
           paperwork for later and holds nothing up. */
        if (cur.kind === 'hostel' && cur.payToUse && cur.res === 'approved' && !cur.checkedIn) {
          if (cur.pay === 'confirmed' || cur.pay === 'paid_cash') {
            return checkInAction();
          }
          /* Cash received only once the GUEST chose the front desk in Pay now
             (2026-10-28); if they cancel that, it goes away again. */
          return cur.pay === 'await_cash'
            ? `<button class="br-btn br-btn-primary" onclick="cashReceived()">Cash received</button><span class="none">Not paid yet &mdash; the guest chose to pay cash here</span>`
            : `<span class="none">Not paid yet &mdash; the guest pays before using the bed</span>`;
        }
        /* Check-in is hostel-only: an event venue has no arrivals. It needs the
           OR in hand, because that is what the guest is asked to show. */
        if (cur.kind === 'hostel' && cur.pay === 'confirmed' && !cur.checkedIn) {
          return cur.or
            ? checkInAction()
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
            ${cur.receiptId
              /* The real screenshot, so staff can read the reference off it
                 rather than trusting what the scanner claims it says. */
              ? `<a href="{{ route('root.valid-id-view') }}?receipt=${cur.receiptId}" title="Open the receipt">
                   <img src="{{ route('root.document-view') }}?receipt=${cur.receiptId}" alt="GCash receipt"
                        style="display:block;width:120px;height:150px;object-fit:cover;background:#f4f4f4;border:1px solid var(--vm-border);border-radius:8px">
                 </a>`
              : `<div class="br-receipt">receipt<br>screenshot</div>`}
            <div class="br-kvgrid" style="flex:1;align-content:start">
              ${kv('Ref no.', esc(r.ref))}${kv('Amount read', esc(r.amount))}${kv('Amount required', esc(cur.total) + amountVerdict(r.amount, cur.total))}
              ${kv('Receipt date', esc(r.date))}${kv('Sent to', esc(r.to))}
            </div>
          </div>
          <div style="color:var(--vm-muted);font-size:0.74rem;margin-bottom:0.5rem">OCR confidence ${r.conf} &middot; ${
            (r.flags || []).some(f => f === 'duplicate_ref' || f === 'duplicate_file')
              ? '<strong style="color:#b23a3a">this reference or image was already used on an earlier receipt</strong>'
              : 'the reference and the file were not seen on any earlier booking'}</div>`;
      }

      /* ============================================================
         GCASH UPLOAD HISTORY + THE 5-ATTEMPT RULE (DB-DECISIONS #6).
         Every upload is on record now, rejected ones included, so staff
         see what the customer actually tried — not just the last receipt.
         When the allowance is used up and payment is open again (staff
         rejected the last one), staff may allow 5 more, with a reason.
         ============================================================ */
      const ATT_FLAG_TEXT = {
        duplicate_ref: 'reference already used', duplicate_file: 'same image as an earlier upload',
        amount_mismatch: 'wrong amount', receiver_mismatch: 'wrong receiver',
        amount_unreadable: 'amount unreadable', receiver_unreadable: 'receiver unreadable',
        low_confidence: 'low OCR confidence', attempt_limit_reached: 'last allowed attempt',
        rejected_by_staff: 'rejected by staff', overridden_by_staff: 'confirmed by staff override',
      };
      const ATT_VERDICT = { accepted: 'Accepted', manual_review: 'Manual review', rejected: 'Rejected' };
      function attemptsUsedUp() { return (cur.attempts || []).length >= (cur.attemptsAllowed || 5); }
      function attemptsBlock() {
        const list = cur.attempts || [];
        if (!list.length) return '';
        const allowed = cur.attemptsAllowed || 5;
        const open = ['await_gcash', 'await_cash', 'overdue'].includes(cur.pay);
        let html = `<div class="br-att">
            <div class="br-att-head"><span class="br-h2" style="margin:0">GCash uploads</span>
              <span class="br-att-count" style="color:${attemptsUsedUp() ? '#b23a3a' : 'var(--vm-muted)'}">${list.length} of ${allowed} attempts used</span></div>` +
          list.slice().reverse().map(a => `
            <div class="br-att-row">
              <a href="{{ route('root.valid-id-view') }}?receipt=${a.id}" title="Open upload ${a.no}"><img src="{{ route('root.document-view') }}?receipt=${a.id}" alt="Upload ${a.no}"></a>
              <div class="br-att-no">Attempt ${a.no}<br><span style="font-weight:400">${esc(a.at)}</span></div>
              <div class="br-att-main">${a.flags.includes('rejected_by_staff')
                  /* a person's decision outranks the checker's verdict, which stays on record */
                  ? `<span class="br-vd rejected">Rejected by staff</span>`
                  : `<span class="br-vd ${esc(a.verdict)}">${ATT_VERDICT[a.verdict] || esc(a.verdict)}</span>`}Ref ${esc(a.ref)} &middot; ${esc(a.amount)} &middot; to ${esc(a.to || '—')}
                ${a.flags.length ? `<div class="f">${a.flags.filter(f => f !== 'rejected_by_staff').map(f => esc(ATT_FLAG_TEXT[f] || String(f).replace(/_/g, ' '))).join(' &middot; ')}${a.flags.includes('rejected_by_staff') ? `<div>Checker said: ${esc(ATT_VERDICT[a.verdict] || a.verdict)}${a.staffNote ? ' &middot; Staff: ' + esc(a.staffNote) : ''}</div>` : ''}</div>` : ''}
              </div>
            </div>`).join('');
        if (attemptsUsedUp() && open) {
          html += `
            <div class="br-ovr">
              <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">All ${allowed} receipt attempts are used</div>
              <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">The customer cannot upload another GCash receipt. If they should get another chance &mdash; an honest mistake, an unreadable screenshot &mdash; allow 5 more. Your reason goes into the booking's timeline. They can always pay in cash at the office instead.</div>
              <textarea id="attNote" rows="2" placeholder="Required note — why does this booking get more attempts?">${esc(cur.attNote || '')}</textarea>
              ${(cur.attErr || cur.actionError) ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.attErr || cur.actionError)}</div>` : ''}
              <div style="margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="allowAttempts()">Allow 5 more attempts</button></div>
            </div>`;
        }
        return html + `</div>`;
      }
      function allowAttempts() {
        cur.attNote = (document.getElementById('attNote') || {}).value || '';
        if (!cur.attNote.trim()) { cur.attErr = 'Write the reason first — it goes into the booking’s timeline.'; render(); return; }
        cur.attErr = null;
        const note = cur.attNote.trim();
        staffAction('allow_receipt_attempts', { note: note }, function (out) {
          if (out.booking && out.booking.attemptsAllowed) cur.attemptsAllowed = out.booking.attemptsAllowed;
          cur.attNote = '';
        });
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
            <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">The payment is <strong>confirmed and final</strong> — this is paperwork, not money. Record the OR number once the University Cashier issues it; ${cur.payToUse ? 'it does not hold up check-in.' : 'the guest needs it at check-in.'}</div>
            <input id="orNo" placeholder="OR number from the cashier" value="">
            <div style="margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="recordOr()">Record OR</button></div>
          </div>`;
      }

      /* PAY TO USE (2026-10-27): the POS from CEDU is paperwork recorded
         whenever it arrives — it no longer unlocks payment. */
      function posBlock() {
        if (!isHostel() || !cur.payToUse || cur.res === 'pending' || (CLOSED_RES.includes(cur.res) && !cur.pos)) return '';
        if (cur.pos) return `<div class="br-kvgrid" style="margin-top:0.7rem">${kv('POS', esc(cur.pos) + ' <span class="soft">(CEDU)</span>')}</div>`;
        return `
          <div class="br-ovr" style="margin-top:0.7rem">
            <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">POS not recorded yet</div>
            <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">Paperwork from CEDU. Record it whenever it arrives &mdash; it does not hold up payment or check-in.</div>
            <input id="posNo" placeholder="POS number from CEDU" value="">
            ${cur.posErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.posErr)}</div>` : ''}
            <div style="margin-top:0.6rem"><button class="br-btn" onclick="recordPos()">Record POS</button></div>
          </div>`;
      }

      function payBody() {
        /* HOSTEL, PAY TO USE (2026-10-27, DB-DECISIONS #26): payment opens at
           approval — GCash from Pay now, or cash here at the front desk — and
           is due by the end of the check-in day. A receipt under review, a
           paid stay and every other state fall through to the shared panels. */
        if (isHostel() && cur.payToUse) {
          if (cur.pay === 'locked') {
            return banner('bn-gray', 'Payment is locked', 'The guest cannot pay until you approve the ID and the reservation.') +
              `<div class="br-kvgrid">${kv('Payment timing', 'Pay to use <span class="soft">(opens on approval; due by the end of the check-in day, ' + esc(cur.checkIn) + ')</span>')}</div>`;
          }
          /* The GUEST decides how they pay, in Pay now (2026-10-28). Cash received
             is offered only after they chose the front desk — which also closes
             GCash for them until they cancel the choice. */
          if (cur.pay === 'await_gcash' && !rejectedUpload()) {   /* a rejected upload gets the override / undo box below */
            return banner('bn-amber', 'Payment due before check-in — waiting for the guest',
                'The guest pays by GCash from Pay now, or chooses to pay cash here at the front desk. Cash received appears once they choose cash. If it is not paid by the end of the check-in day, the beds are released.') +
              `<div class="br-kvgrid">${kv('Pay by', 'end of ' + esc(cur.checkIn))}${kv('Amount due', esc(cur.total))}${kv('Guest’s plan', 'Not chosen yet &mdash; GCash is open')}</div>`;
          }
          if (cur.pay === 'await_cash') {
            return banner('bn-amber', 'The guest will pay cash at the front desk',
                'They chose cash in Pay now, so GCash is closed for them. If they cancel that choice, this button goes away and GCash opens again.') +
              `<div class="br-kvgrid">${kv('Pay by', 'end of ' + esc(cur.checkIn))}${kv('Amount due', esc(cur.total))}</div>
               <div style="margin-top:0.8rem"><button class="br-btn br-btn-primary" onclick="cashReceived()">Cash received &mdash; ${esc(cur.total)}</button></div>
               <div style="color:var(--vm-muted);font-size:0.74rem;margin-top:0.45rem">Press once the cash is in your hand. The stay is marked paid and its System Receipt is issued.</div>`;
          }
        }
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
        /* A rejected upload (by staff or the checker) keeps payment open
           (await_gcash), so the override / undo box is drawn from the upload itself. */
        switch (rejectedUpload() ? 'rejected' : cur.pay) {
          case 'locked': return banner('bn-gray', 'Payment is locked', 'The customer cannot pay until you approve the ID and the reservation.') +
            `<div class="br-kvgrid">${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}${cur.prepay
              ? kv('Pay-by once approved', esc(cur.payBy) + ' <span class="soft">(1 day before the event)</span>')
              : kv('Payment timing', 'Post-pay <span class="soft">(due the day after the event · overdue if unpaid ' + PAY_POLICY.graceDays + ' days after it)</span>')}</div>`;
          case 'await_event': return banner('bn-gray', 'Payment opens after the event', 'Post-pay booking: nothing is owed until the event is over. The customer cannot pay early. The system opens payment the day after the last booked day.') +
            `<div class="br-kvgrid">${kv('Payment opens', 'the day after the event')}${kv('Due by', esc(cur.payBy))}${graceKv()}${kv('Amount due', esc(cur.total))}${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}</div>`;
          case 'await_gcash': return banner('bn-amber', 'Awaiting GCash payment', 'The customer was notified. The receipt will be auto-checked the moment it is uploaded.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${graceKv()}${kv('Amount due', esc(cur.total))}</div>`;
          case 'await_cash': return banner('bn-amber', 'Awaiting cash payment at the cashier', 'The customer chose to pay in cash, so GCash is closed for them until they cancel that choice — then this button goes away. Confirm here once the cashier records the payment.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${graceKv()}${kv('Amount due', esc(cur.total))}${kv('Where', 'USeP Cashier — Venue Reservations Window', true)}</div>
             <div style="margin-top:0.8rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Record cash payment</button></div>`;
          /* `auto_pass` is not a database state (an upload always lands in
             `under_review`, DB-DECISIONS #6); `rejected` is reached through
             rejectedUpload() above. */
          case 'auto_pass': return banner('bn-green', 'All automatic checks passed', 'Exact amount · correct receiver · fresh reference · genuine receipt markers.') + receiptBlock() +
            `<div style="display:flex;gap:0.5rem;margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="openRejectPay()">Reject after GCash check</button></div>` + rejectPayPanel() + `
             <div style="color:var(--vm-muted);font-size:0.74rem;margin-top:0.55rem">Find the reference number in the business GCash Transaction History before confirming.</div>`;
          /* Manual review is now the COMMON path, not a rarity — so it has to say
             out loud that the customer is waiting on US. The pay-by deadline must
             not quietly expire while a receipt sits here: the customer did their
             part, and letting the slot lapse would punish them for our queue. */
          case 'under_review': return banner('bn-amber', 'Needs manual review — the customer is waiting on you',
              'The auto-check could not verify everything. Compare the fields against the screenshot; the amount required is shown beside the amount read.') +
            (attemptsUsedUp() ? banner('bn-red', 'This was the customer’s last receipt attempt',
              'All ' + (cur.attemptsAllowed || 5) + ' GCash attempts are used, so this one came to you whatever the checker said. Confirm it if you find the payment in GCash; if you reject it, you can allow 5 more attempts below.') : '') +
            receiptBlock() +
            flags(cur.receipt.flags, '#c99a3c') +
            `<div class="br-banner bn-gray" style="margin-top:0.7rem"><div>Slot is held while this is with you
              <span class="s">Pay-by deadline: <strong>${esc(cur.payBy)}</strong>. The customer has already paid and cannot act further — clear this before the deadline, or extend it. Do not let it lapse to overdue.</span></div></div>` +
            `<div style="display:flex;gap:0.5rem;margin-top:0.7rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="openRejectPay()">Reject receipt</button></div>` + rejectPayPanel();
          /* WHO rejected it matters, and matters more now the scanner rejects
             less: after DB-DECISIONS #6 most rejections are a staff decision, and
             labelling those "auto-rejected" told staff the machine did something
             they did themselves — while the reason line underneath said otherwise. */
          case 'rejected': { const up = rejectedUpload(), byStaff = up.flags.includes('rejected_by_staff');
            return banner('bn-red',
              byStaff ? 'Receipt rejected by staff' : 'Receipt auto-rejected',
              (byStaff ? 'Checked against GCash and refused. ' : 'The automatic check refused it. ')
              + 'The customer was told to send another receipt. The slot stays held until the pay-by deadline (' + esc(cur.payBy) + ').') +
            flags(byStaff ? [up.staffNote || 'No reason recorded'] : up.flags.map(f => ATT_FLAG_TEXT[f] || String(f).replace(/_/g, ' ')), '#b23a3a') +
            `<div class="br-ovr">
              <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">Override &amp; confirm payment (staff)</div>
              <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">Use only after finding the payment in the business GCash Transaction History. <strong>Duplicate-receipt rejections can only be overridden by an admin.</strong> The auto-check verdict stays on record either way.</div>
              <input id="ovrRef" placeholder="Verified reference number (from GCash)" style="margin-bottom:0.5rem" value="${esc(cur.ovrRef || '')}">
              <textarea id="ovrNote" rows="2" placeholder="Required note — why is this override correct?">${esc(cur.ovrNote || '')}</textarea>
              ${cur.ovrErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.ovrErr)}</div>` : ''}
              <div style="display:flex;gap:0.5rem;margin-top:0.6rem">
                <button class="br-btn br-btn-primary" onclick="overrideConfirm()">Override &amp; confirm</button>
                ${byStaff ? `<button class="br-btn" onclick="undoRejection()">&larr; Undo rejection</button>` : ""}
              </div>
            </div>`; }
          case 'confirmed': return banner('bn-green', 'Payment confirmed', 'Confirmed by ' + esc(cur.confirmedBy || 'staff') + '. The reference number is permanently locked.') + (cur.receipt ? receiptBlock() : '');
          case 'paid_cash': return isHostel()
              /* the hostel takes cash at the front desk; its OR comes later from the cashier */
              ? banner('bn-green', 'Paid in cash at the front desk', esc(cur.confirmedBy || 'Recorded by staff') + ' · the System Receipt is issued; the Official Receipt follows from the University Cashier.')
              : banner('bn-green', 'Paid at the cashier', esc(cur.confirmedBy || 'Recorded by staff') + ' · official receipt issued at the counter.');
          case 'overdue': return banner('bn-red', 'Payment overdue', (cur.postpay
              ? 'It was due ' + esc(cur.payBy) + ' and the grace period ended ' + esc(cur.graceEnds || cur.payBy) + ' with no valid payment. The event already happened, so the booking is not released: the payment stays on record and can still be made. Extend the deadline or follow up with the customer.'
              : 'The pay-by deadline (' + esc(cur.payBy) + ') passed with no valid payment. The slot can be released or the deadline extended.')) +
            `<div class="br-kvgrid">${kv('Was due', esc(cur.payBy))}${graceKv()}${kv('Amount due', esc(cur.total))}${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}</div>`;
          case 'refund_requested':
          case 'refund_await_or':
          case 'refund_processing':
          case 'refund_correction': {
            const pendingOR = cur.pay === 'refund_await_or' || !!(cur.refund && cur.refund.orPending);
            const returned  = cur.pay === 'refund_correction';
            const head = returned ? 'Returned to the customer for correction'
                       : (pendingOR ? 'Refund requested — awaiting Official Receipt'
                       : (cur.pay === 'refund_processing' ? 'Refund approved — payout in progress'
                                    : 'Refund requested — under verification'));
            const orNote = pendingOR
              ? ' <strong>The Official Receipt is still outstanding</strong> — the refund cannot be PAID until it arrives, but the claim can be decided on its merits now.'
              : "";
            /* The `refunds` row can be gone (withdrawn, or an older booking
               whose claim was purged) while the booking still carries a refund
               payment status — the panel has to open either way. */
            return banner(returned || pendingOR ? 'bn-amber' : 'bn-gray', head,
                          esc(cur.refund ? cur.refund.docs : 'No refund paperwork is on file for this booking.'))
              + refundReasonBlock()
              + actionPanel()
              + payoutPanel()
              + (cur.receipt ? receiptBlock() : "")
              + `<div style="color:var(--vm-muted);font-size:0.76rem;line-height:1.55;margin-top:0.9rem">The booking is <strong>not cancelled</strong> while this is open — the date is released only once the refund is completed. Chain: Requested &rarr; Under verification &rarr; Refunded / Returned for correction / Denied.${orNote}</div>`;
          }
          case 'refunded': return banner('bn-green', 'Refunded — booking closed, date released', esc(cur.confirmedBy || 'Processed by staff') + ' · the refund was paid, so this booking is closed and its date is free to be booked again.');
          case 'refund_denied': return banner('bn-red', 'Refund denied — booking unaffected', esc(cur.denyReason || 'Requirements not met.') + ' A denial is final; the booking itself is untouched and remains the customer\u2019s.');
          /* The deadline ran out and the system released the slot itself
             (sp_expire_due_bookings). Nothing is owed and nothing can be paid —
             the dates are already back on sale. */
          case 'expired': return cur.neverApproved
              /* nobody approved it before its date (2026-10-27): nothing was owed */
              ? banner('bn-gray', 'Request expired — never approved',
                  'This request was still waiting for approval when its ' + (cur.kind === 'hostel' ? 'check-in day' : 'start time') + ' arrived, so the system expired it and released the ' + (cur.kind === 'hostel' ? 'beds' : 'date') + '. Nothing was charged.')
              : banner('bn-gray', 'Payment window expired — slot released',
                  'The pay-by deadline (' + esc(cur.payBy) + ') passed with no payment, so the system released the dates. They are bookable again.');
          /* Never blank: an unrecognised code still has to name itself, or staff
             are left looking at an empty panel with no way to tell whether the
             booking is fine or the page is broken. */
          default: return banner('bn-gray', esc(payBadge(cur.pay).t),
              'There is no staff action for this payment state.');
        }
      }

      /* The ID card carries the DISCOUNT DECISION, because that is what the ID is
         being read for. Two separate questions live here and staff answer both:
         is the ID valid, and does it prove USeP affiliation? A valid driver's
         licence is a YES to the first and a NO to the second — which is why
         approval has three outcomes and not two.
         The account address is EVIDENCE. Nothing verifies it at registration, so
         it never decides a price on its own (includes/pricing.php). */
      function claimsAffiliation() {
        if (cur.affiliated != null) return !!cur.affiliated;
        return /^USeP/i.test(String(cur.idLabel || ""));   /* seeded records: infer from the ID they gave */
      }
      function idBlock() {
        const st = cur.idStatus;
        const claim = claimsAffiliation();
        const usepAcct = typeof isUsepAccount === 'function' && isUsepAccount(cur.email);
        return `
          <div style="margin-top:0.85rem">
            ${cur.docs && cur.docs.customer_id
              ? `<a href="{{ route('root.valid-id-view') }}?doc=${cur.docs.customer_id}" title="Open the ID">
                   <img src="{{ route('root.document-view') }}?doc=${cur.docs.customer_id}" alt="${esc(cur.idLabel)}"
                        style="display:block;width:100%;max-height:260px;object-fit:contain;background:#f4f4f4;border:1px solid var(--vm-border);border-radius:10px">
                 </a>`
              /* No document row: the booking is real but its ID never stored.
                 Say so plainly — a staff member must not read an empty tile as
                 "no ID was required". */
              : `<div class="br-idtile">${cur.idStatus === 'pending' ? 'No ID file on record &mdash; ask the customer to re-upload' : esc(cur.idLabel) + ' &mdash; no file stored'}</div>`}
            <div style="align-items:center;display:flex;gap:0.5rem;justify-content:space-between;margin-top:0.6rem">
              <span class="br-badge ${st === 'approved' ? 'b-green' : st === 'rejected' ? 'b-red' : 'b-amber'}">${st === 'approved' ? 'ID approved' : st === 'rejected' ? 'ID rejected' : 'ID awaiting review'}</span>
              ${st === 'pending' ? '<span style="color:var(--vm-muted);font-size:0.74rem">Approve via the header button — it approves the ID and the reservation together.</span>' : ''}
            </div>
            <div style="margin-top:0.7rem;padding-top:0.7rem;border-top:1px solid var(--vm-hairline)">
              <div class="br-h2" style="margin-bottom:0.45rem">USeP affiliation</div>
              <div style="font-size:0.83rem;font-weight:600;margin-bottom:0.25rem">
                ${claim ? 'Claimed &mdash; ' + DISCOUNT_PERCENT + '% discount requested' : 'Not claimed &mdash; full price'}
              </div>
              <div style="font-size:0.76rem;color:var(--vm-muted);line-height:1.5">
                ID uploaded: <strong>${esc(cur.idLabel)}</strong><br>
                Account: <strong>${esc(cur.email || '—')}</strong>
                ${usepAcct
                  ? ' <span style="color:#1c7a4f">&#10003; a USeP address</span> <span style="color:var(--vm-label)">&mdash; supporting evidence only, not proof</span>'
                  : ' <span style="color:var(--vm-label)">&mdash; not a USeP address</span>'}
              </div>
              ${cur.discountPct != null ? `<div style="font-size:0.76rem;margin-top:0.4rem;color:${cur.discountPct > 0 ? '#1c7a4f' : 'var(--vm-muted)'}">
                Decided: <strong>${cur.discountPct > 0 ? cur.discountPct + '% applied' : 'no discount — full price'}</strong></div>` : ''}
            </div>
          </div>`;
      }

      function render() {
        // venue-only: a hostel booking has named guests, not an attendee count
        const over = !isHostel() && cur.attendees > cur.capacity;
        document.getElementById('brqRoot').innerHTML = `
          <a class="br-back" href="{{ route('admin.booking-requests') }}">&larr; Back to Booking Requests</a>
          <div class="br-head">
            <div>
              <h1 class="br-title">${cur.id} · ${esc(cur.event)}</h1>
              <p class="br-subtitle">Submitted ${esc(cur.submitted)}</p>
            </div>
            <div>
              <div style="display:flex;flex-wrap:wrap;gap:0.45rem;justify-content:flex-end;margin-bottom:0.6rem">
                <span class="br-badge ${resBadge(cur.res).c}">${esc(resBadge(cur.res).t)}</span>
                <span class="br-badge ${payBadge(cur.pay).c}">${esc(payText())}</span>
              </div>
              <div class="br-actions">${hdrActions()}</div>
              ${cur.actionError && !cur.attErr ? `<div role="alert" style="color:#b23a3a;font-size:0.78rem;margin-top:0.4rem;text-align:right">${esc(cur.actionError)}</div>` : ''}
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
                    ${kv('Fee', esc(cur.total) + ' <span class="soft">(' + esc(cur.feeDay) + '/day × ' + cur.days + ')</span>')}${!cur.prepay
                      ? kv('Pay-by rule', esc(cur.payBy) + ' <span class="soft">(post-pay · the day after the event · overdue after ' + esc(cur.graceEnds || cur.payBy) + ')</span>')
                      : kv('Pay-by rule', esc(cur.payBy) + ' <span class="soft">(1 day before the event)</span>')}
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
                  <h2 class="br-h2">Payment · ${isHostel() && cur.payToUse
                    ? (cur.pay === 'await_cash' || cur.method === 'cash' && cur.methodChosen ? 'Cash at the front desk' : (!cur.methodChosen ? 'GCash online or cash at the desk' : 'GCash')) + ' <span class="soft">before check-in</span>'
                    : isHostel()
                    ? (cur.method === 'cash' ? 'Cash to staff' : 'GCash · staff account') + ' <span class="soft">via CEDU</span>'
                    : (cur.method === 'cash' ? 'Cash (walk-in)' : 'GCash')}</h2>
                  ${payBody()}
                  ${attemptsBlock()}
                  ${posBlock()}
                  ${orBlock()}
                </div>
              </div>

              ${mailCard()}

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

      /* ============================================================
         RECEIPT & EMAILS (DB-DECISIONS #22). Read from booking-emails.php
         when the page opens and again after a payment is confirmed (that is
         when a receipt is issued and a walk-in's receipt email goes out).
         Walk-ins are emailed if they gave an address at the counter;
         account holders get nothing automatic — they use My Bookings.
         ============================================================ */
      const MAIL_KIND = { walkin_booking: 'Booking confirmation', walkin_receipt: 'System Receipt', receipt_copy: 'System Receipt' };
      let mail = { loaded: false, data: null, error: null, busy: 0, flash: '' };

      async function loadMail() {
        try {
          const res = await fetch({!! json_encode(route('admin.booking-emails') . '?booking=') !!} + encodeURIComponent(cur.id), { credentials: 'same-origin' });
          const out = await res.json();
          mail = Object.assign(mail, { loaded: true, data: out.ok ? out : null, error: out.ok ? null : (out.message || 'Could not load the emails.'), busy: 0 });
        } catch (e) {
          mail = Object.assign(mail, { loaded: true, data: null, error: 'Could not reach the server.', busy: 0 });
        }
        render();
      }

      async function resendMail(id) {
        if (mail.busy) return;
        mail.busy = id; mail.flash = ''; render();
        try {
          const body = new URLSearchParams({ _token: CSRF, booking: cur.id, action: 'resend', id: String(id) });
          const res = await fetch({!! json_encode(route('admin.booking-emails')) !!}, { method: 'POST', body: body, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { message: 'The server sent an unreadable reply.' }; });
          mail.flash = out.message || '';
        } catch (e) {
          mail.flash = 'Could not reach the server.';
        }
        loadMail();
      }

      function mailRow(m) {
        const rc = mail.data.receipt;
        const what = MAIL_KIND[m.kind] + (m.receiptId && rc ? ' ' + rc.number : '')
          + (m.kind === 'receipt_copy' ? ' <span class="soft">· requested by the customer</span>' : (m.receiptId ? ' <span class="soft">· PDF</span>' : ''));
        const chip = m.status === 'sent' ? '<span class="br-badge b-green">Sent</span>'
                   : m.status === 'failed' ? '<span class="br-badge b-red">Failed</span>'
                   : '<span class="br-badge b-amber">Sending</span>';
        const meta = m.status === 'sent' ? 'Sent ' + esc(m.sent)
                   : 'Tried ' + esc(m.created) + ' · ' + m.attempts + ' attempt' + (m.attempts === 1 ? '' : 's');
        const btn = m.status === 'failed'
          ? `<button class="br-btn br-btn-primary" type="button" onclick="resendMail(${m.id})" ${mail.busy ? 'disabled' : ''}>${mail.busy === m.id ? 'Sending…' : 'Resend'}</button>` : '';
        const err = m.status === 'failed'
          ? `<div class="err" title="${esc(m.error || '')}">Couldn&rsquo;t send it. The booking and payment are still recorded; only the email is waiting.</div>` : '';
        return `<div class="br-mail"><div class="what">${what}</div><div class="meta">${meta}</div><div class="side">${chip}${btn}</div>${err}</div>`;
      }

      function mailCard() {
        if (!mail.loaded) return '';
        if (!mail.data) return `<div class="br-card"><div class="br-sec"><h2 class="br-h2">Receipt &amp; emails</h2><div class="br-mail-note">${esc(mail.error)}</div></div></div>`;
        const d = mail.data;
        const to = d.to ? 'to ' + esc(d.to) : (d.isWalkIn ? 'no email given' : '');
        let note = '';
        if (d.isWalkIn && !d.to) {
          note = d.receipt ? 'The guest has no email. Print the receipt and hand it over.'
                           : 'The guest has no email, so nothing is emailed. Print the receipt once the payment is recorded.';
        } else if (!d.isWalkIn) {
          note = 'No automatic emails for account holders. They download the receipt or email it to themselves from My Bookings.';
        }
        const rcpt = d.receipt
          ? `<div class="br-rcpt"><span>System Receipt ${esc(d.receipt.number)}</span><a class="br-btn" href="{{ route('root.receipt-pdf') }}?booking=${encodeURIComponent(cur.id)}">Download PDF</a></div>`
          : '';
        return `<div class="br-card"><div class="br-sec">
            <h2 class="br-h2">Receipt &amp; emails <span class="br-mail-to">${to}</span></h2>
            ${note ? `<div class="br-mail-note">${note}</div>` : ''}
            ${d.emails.map(mailRow).join('')}
            ${(!note && !d.emails.length) ? '<div class="br-mail-note">Nothing emailed yet.</div>' : ''}
            ${rcpt}
            ${mail.flash ? `<div class="br-mail-flash" role="status">${mail.flash}</div>` : ''}
          </div></div>`;
      }

      /* Recompute and LOCK the price at approval. DB-DECISIONS #2: the booking
         stores the % it received, so changing the live rate later never rewrites
         it. The rate the customer was quoted at booking is the rate they get. */
      function pesoStr(n) { return '₱' + Number(n).toLocaleString('en-PH'); }
      /* Approving answers TWO questions: is the ID valid, and does it prove USeP
         affiliation? `withDiscount` is the second. A valid government ID is a yes
         to the first and a no to the second — approved, at full price.

         The discount is applied and SNAPSHOTTED server-side, so a later change to
         the live percentage cannot re-price a booking that was already quoted. */
      function approveIdRes(withDiscount) {
        staffAction('approve', { discount: withDiscount ? '1' : '0' }, function () {
          cur.idStatus = 'approved';
        });
      }

      /* Staff came back from CEDU with the POS — for a PRE-PAY hostel booking
         this is the moment the guest becomes able to pay at all. */
      function recordPos() {
        const box = document.getElementById('posNo');
        const val = box ? box.value.trim() : '';
        if (!val) { cur.posErr = 'Enter the POS number CEDU issued — payment cannot open without it.'; render(); return; }
        cur.posErr = null;
        staffAction('record_pos', { pos: val }, function () {
          cur.pos = val;
        });
      }

      /* PAY TO USE (2026-10-27): the guest paid cash at the front desk. The
         stay becomes a cash booking, paid, with its System Receipt. */
      async function cashReceived() {
        if (!(await venusepConfirm('Mark ' + cur.total + ' as received in cash? The stay is marked paid and its System Receipt is issued.',
              { title: 'Cash received?', okLabel: 'Cash received' }))) return;
        staffAction('cash_received', {}, function (out) {
          if (out.booking && out.booking.method) cur.method = String(out.booking.method).toLowerCase();
          cur.methodChosen = true;
          loadMail();                      /* a receipt was just issued (and maybe emailed) */
          cur.confirmedBy = 'You · just now';
        });
      }

      /* The cashier issued the OR — AFTER the booking was already confirmed.
         Nothing about the PAYMENT changes here: the OR is a document. */
      function recordOr() {
        const box = document.getElementById('orNo');
        const val = box ? box.value.trim() : '';
        if (!val) return;
        staffAction('record_or', { or: val }, function () {
          cur.or = val;
        });
      }

      /* The guest arrived and showed their POS + OR. Hostel-only, manual. */
      function checkIn() {
        staffAction('check_in', {}, function () {
          cur.checkedIn = true;
        });
      }

      function rejectRequest() {
        staffAction('reject', { note: 'Reservation released back to availability.' });
      }

      function confirmPay() {
        staffAction('confirm_payment', {}, function () {
          loadMail();                      /* a receipt was just issued (and maybe emailed) */
          cur.confirmedBy = 'You · just now';
        });
      }

      /* REJECT A RECEIPT (Aron, 2026-10-30): staff pick why from a short list,
         plus a note — required for "Other". The customer reads the result, in
         the same words the server stores (reason — note). */
      const REJECT_REASONS = {!! json_encode(receipt_reject_reasons(), JSON_UNESCAPED_UNICODE) !!};
      function rejectMessage() {
        const note = (cur.rejNote || '').trim();
        if (!cur.rejReason) return '';
        return cur.rejReason === 'Other' ? note : cur.rejReason + (note ? ' — ' + note : '');
      }
      function rejectPayPanel() {
        if (!cur.rejOpen) return '';
        const msg = rejectMessage();
        return `<div class="br-ovr">
            <div style="font-size:0.82rem;font-weight:650;margin-bottom:0.35rem">Reject this receipt</div>
            <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.55;margin-bottom:0.8rem">Payment reopens so the customer can upload another receipt before the deadline. This one stays on record and cannot be used again. Be plain about why: they read it.</div>
            <div class="br-fld">
              <label for="rejReason">Why is it being rejected</label>
              <select id="rejReason" onchange="readRejectPay(); render()">
                <option value="">Choose a reason…</option>
                ${REJECT_REASONS.map(r => `<option${cur.rejReason === r ? ' selected' : ''}>${esc(r)}</option>`).join('')}
              </select>
            </div>
            <div class="br-fld">
              <label for="rejNote">Note to the customer ${cur.rejReason === 'Other' ? '(required)' : '(optional)'}</label>
              <textarea id="rejNote" rows="2" placeholder="e.g. the amount sent was ₱500 short.">${esc(cur.rejNote || '')}</textarea>
            </div>
            ${msg ? `<div class="br-preview">The customer will see: <b>${esc(msg)}</b></div>` : ''}
            ${cur.rejErr ? `<div role="alert" style="color:#b23a3a;font-size:0.74rem;margin-bottom:0.55rem">${esc(cur.rejErr)}</div>` : ''}
            <div style="display:flex;gap:0.5rem">
              <button class="br-btn br-btn-primary" onclick="rejectPay()">Reject and tell the customer</button>
              <button class="br-btn" onclick="cur.rejOpen = false; render()">Cancel</button>
            </div>
          </div>`;
      }
      function openRejectPay() { cur.rejOpen = true; cur.rejErr = null; render(); }
      function readRejectPay() {
        const r = document.getElementById('rejReason'), n = document.getElementById('rejNote');
        if (r) cur.rejReason = r.value;
        if (n) cur.rejNote = n.value;
      }
      function rejectPay() {
        readRejectPay();
        if (!cur.rejReason) { cur.rejErr = 'Choose a reason — the customer sees it.'; render(); return; }
        if (cur.rejReason === 'Other' && !(cur.rejNote || '').trim()) { cur.rejErr = 'Write the reason in the note — the customer sees it.'; render(); return; }
        const why = rejectMessage();
        staffAction('reject_payment', { reason: cur.rejReason, note: (cur.rejNote || '').trim() }, function () {
          cur.rejOpen = false; cur.rejReason = ''; cur.rejNote = ''; cur.rejErr = null;
          /* the server marked the upload under review (the latest) — show it without a reload */
          const lastUp = (cur.attempts || []).slice(-1)[0];
          if (lastUp && !lastUp.flags.includes('rejected_by_staff')) { lastUp.flags.push('rejected_by_staff'); lastUp.staffNote = why; }
        });
      }
      /* The latest upload, when it was rejected (by staff or by the checker) and
         payment is open again for another one — or null. */
      function rejectedUpload() {
        const up = (cur.attempts || []).slice(-1)[0];
        return cur.pay === 'await_gcash' && up && (up.verdict === 'rejected' || up.flags.includes('rejected_by_staff')) ? up : null;
      }
      /* Take back a rejection STAFF made: the receipt is under review again.
         Only offered for staff rejections: an auto-rejection is a duplicate or
         a total mismatch, and the considered way back from those is Override &
         confirm with a written reason. The timeline keeps both entries. */
      function undoRejection() {
        staffAction('undo_rejection', {}, function () {
          const up = (cur.attempts || []).slice(-1)[0];
          if (up) { up.flags = up.flags.filter(f => f !== 'rejected_by_staff'); up.staffNote = ''; }
        });
      }
      /* Staff found the payment in the venue's GCash history after all. */
      function overrideConfirm() {
        cur.ovrRef = (document.getElementById('ovrRef') || {}).value || '';
        cur.ovrNote = (document.getElementById('ovrNote') || {}).value || '';
        if (!cur.ovrRef.trim() || !cur.ovrNote.trim()) { cur.ovrErr = 'Both the verified reference number and a note are required.'; render(); return; }
        cur.ovrErr = '';
        staffAction('override_confirm', { ref: cur.ovrRef.trim(), note: cur.ovrNote.trim() }, function () {
          const up = (cur.attempts || []).slice(-1)[0];
          if (up) up.flags.push('overridden_by_staff');
          cur.ovrRef = ''; cur.ovrNote = '';
          loadMail();                      /* a receipt was just issued (and maybe emailed) */
          cur.confirmedBy = 'You (override) · just now';
        });
      }
      /* OVERDUE: stop waiting and free the dates — no undo, so ask first. */
      async function releaseSlot() {
        if (!(await venusepConfirm('Release ' + cur.id + '? The reservation ends, its payment is marked expired and the customer is told. This cannot be undone.',
              { title: 'Release the slot?', okLabel: 'Release the slot' }))) return;
        staffAction('release_slot', {});
      }
      /* OVERDUE: 48 more hours from now to pay. */
      function extendDeadline() {
        staffAction('extend_deadline', {});
      }
      /* NOT a denial. A paperwork problem hands the request back with a bounded
         window to fix it; the claim is untouched, and so is the booking. */
      /* The three refund outcomes share one inline panel. openAction shows it,
         refreshAction keeps the live preview honest as fields change, submitAction
         validates and applies. Replaces window.prompt, which gave staff a blank
         box and no idea what the customer would end up reading. */
      /* Switching between the two modes clears the selection: the reason lists are
         different, so a value carried over from the other one would sit invisible
         in cur while the select showed nothing. */
      function openAction(mode) {
        if (cur.actionMode !== mode) { cur.actReason = ""; cur.actNote = ""; }
        cur.actionMode = mode; cur.actErr = null; render();
      }
      function closeAction() { cur.actionMode = null; cur.actErr = null; cur.actNote = ""; cur.actReason = ""; render(); }
      function readAction() {
        const val = function (id) { const el = document.getElementById(id); return el ? el.value : ""; };
        if (document.getElementById('actReason')) {
          cur.actDoc = val('actDoc'); cur.actReason = val('actReason'); cur.actNote = val('actNote');
        }
      }
      function refreshAction() { readAction(); render(); }
      async function submitAction() {
        readAction();
        const deny = cur.actionMode === 'deny';
        if (!cur.actReason) { cur.actErr = 'Choose a reason — the customer sees it.'; render(); return; }
        const note = (cur.actNote || "").trim();
        if ((deny || cur.actReason.indexOf('Other') === 0) && !note) {
          cur.actErr = 'A note is required for this reason.'; render(); return;
        }
        /* Write first, and only change what staff see if it landed. The customer
           can withdraw while this page is open. */
        const outcome = deny ? 'denied' : 'fix';
        const msg = deny
          ? cur.actReason + (note ? ' — ' + note : "")
          : (cur.actDoc || fixDocOptions()[0]) + ' — ' + cur.actReason + (note ? ' — ' + note : "");
        if (!await pushOutcome(outcome, msg)) {
          cur.actErr = 'This request is no longer open — the customer withdrew it while this page was open. Nothing has been recorded.';
          render(); return;
        }
        if (deny) {
          cur.pay = 'refund_denied';
          cur.denyReason = msg;
        } else {
          cur.pay = 'refund_correction';
          cur.refund = cur.refund || {};
          cur.refund.stage = 'Returned for correction';
          cur.refund.docs = 'Returned to the customer: ' + msg;
        }
        cur.actionMode = null; cur.actNote = ""; cur.actReason = ""; cur.actErr = null;
        render();
      }

      /* Decisions on a customer-filed request must go back into the shared store,
         or the customer would never learn the outcome. No-op for the seeded BRQ
         records, which live only in this page. */
      /* Returns FALSE when the store refused the write — which happens when the
         customer withdrew the request while this page was open. Callers must check
         it: showing "Refunded" for a request that no longer exists would be a lie
         staff act on. `proof` is forwarded; dropping it silently is what made the
         customer's proof panel read "not recorded". */
      /* Write the refund decision to the `refunds` row, and report whether it
         landed. Callers MUST check the result: the customer can withdraw while
         this page is open, and showing "Refunded" for a request that no longer
         exists would be a lie staff then act on. The server re-reads the
         request's state for exactly that reason.

         Returns a promise now — it talks to admin/booking-action.php instead of
         browser storage, so callers await it. */
      async function pushOutcome(status, note, proof) {
        const map = { fix: 'refund_return', denied: 'refund_deny', refunded: 'refund_complete' };
        const action = map[status];
        if (!action) return false;
        const extra = { note: note || '' };
        if (proof) {
          if (proof.reference) extra.payout_reference = proof.reference;
          if (proof.amountValue) extra.amount = proof.amountValue;
        }
        const body = new URLSearchParams(Object.assign({ _token: CSRF, booking: cur.id, action: action }, extra));
        try {
          const res = await fetch({!! json_encode(route('admin.booking-action')) !!}, { method: 'POST', body: body, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) { cur.outcomeError = out.message || 'Nothing was recorded.'; return false; }
          if (out.booking) { cur.res = out.booking.res; cur.pay = out.booking.pay; if (out.booking.tl) cur.tl = out.booking.tl; }
          return true;
        } catch (e) {
          cur.outcomeError = 'Could not reach the server, so nothing was recorded.';
          return false;
        }
      }
      /* Nothing to show: a VB- reference with no request behind it. Better than
         silently rendering a different customer's booking. */
      function showMissing() {
        document.getElementById('brqRoot').innerHTML =
          `<div class="br-page"><a class="br-back" href="{{ route('admin.booking-requests') }}">&larr; Back to Booking Requests</a>
            <h1 class="br-title">Request not found</h1>
            <p class="br-subtitle">There is no refund request for <strong>${esc(qid)}</strong>. It may have been withdrawn by the customer, or already decided.</p>
          </div>`;
      }
      if (!cur) { showMissing(); } else { render(); loadMail(); }
    </script>
@endpush
