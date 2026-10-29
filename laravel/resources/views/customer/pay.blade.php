@extends('layouts.customer')
{{-- PAY (was customer/pay.php) — one booking's payment step, reached from "Pay now" in Booking History. --}}
@section('title', 'Pay ' . $payB['bookingId'])
@section('active', 'Booking History')
@section('body-class', 'pay-page')
@push('head')
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="{{ asset('assets/css/confirm-modal.css') }}" />
    <script src="{{ asset('assets/js/confirm-modal.js') }}"></script>
    <!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
@endpush
@push('styles')
    <style>
      @media (max-width: 767.98px) { .container-fluid { padding-inline: 16px; } }
    </style>
    <!-- [1] PAGE CSS -->
    <style>
      :root { --crimson: #a11626; --crimson-deep: #7d0f1e; --ink: #1d1214; --soft: #6e6a64; --line: #ece9e4; }
      /* white page, like Booking History (this page is its sub-page) — not the crimson account backdrop */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      .pay-wrap { max-width: 720px; margin: 0 auto; padding-top: 22px; }
      .pay-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #4a4440; text-decoration: none; margin-bottom: 14px; }
      .pay-back:hover { color: var(--crimson); }
      .pay-title { margin: 0 0 4px; font-size: 24px; font-weight: 700; letter-spacing: -.01em; }
      .pay-sub { margin: 0 0 18px; color: var(--soft); font-size: 13.5px; }
      .pay-card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 18px 20px; margin-bottom: 16px; }
      .pay-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px 18px; }
      .pay-k { font-size: 11.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #a3a09a; margin-bottom: 3px; }
      .pay-v { font-size: 14px; font-weight: 640; color: #1c1b19; overflow-wrap: anywhere; }
      .pay-amount { font-size: 30px; font-weight: 780; letter-spacing: -.02em; color: var(--crimson); }
      .pay-note { display: flex; gap: 10px; align-items: flex-start; border-radius: 12px; padding: 13px 15px; margin-bottom: 16px; font-size: 13px; line-height: 1.55; }
      .pay-note strong { display: block; font-size: 14px; margin-bottom: 2px; }
      .pay-note .bi { font-size: 17px; flex: none; margin-top: 1px; }
      .pay-note.is-ok   { background: #f2faf5; border: 1px solid #d4ebdd; color: #1c7a4f; }
      .pay-note.is-warn { background: #fdf7ee; border: 1px solid #f3e3c8; color: #8a5a12; }
      .pay-note.is-info { background: #f5f7fb; border: 1px solid #dfe4ef; color: #34405a; }
      .pay-note.is-bad  { background: #fdf2f2; border: 1px solid #f2d8d8; color: #b23a3a; }
      .pay-methods { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-bottom: 16px; }
      .pay-method { border: 1.5px solid rgba(0,0,0,.12); background: #fff; border-radius: 12px; padding: 12px 14px; cursor: pointer; text-align: left; font: inherit; }
      .pay-method.is-on { border-color: var(--crimson); background: #f9f3f3; }
      .pay-method b { display: block; font-size: 14px; color: #1c1b19; }
      .pay-method span { display: block; font-size: 12px; color: #8a857d; margin-top: 2px; }
      .pay-btn { width: 100%; height: 50px; border: none; border-radius: 12px; background: var(--crimson); color: #fff; font: 700 15px Inter, system-ui, sans-serif; cursor: pointer; margin-top: 16px; }
      .pay-btn:hover { background: var(--crimson-deep); }
      .pay-btn[disabled] { background: #b7b3ab; cursor: not-allowed; }
      .pay-err { margin-top: 10px; color: #b23a3a; font-size: 13px; font-weight: 600; }
      .pay-small { font-size: 12.5px; color: #8a857d; margin-top: 9px; text-align: center; }
      .pay-agree { display: flex; gap: 9px; align-items: flex-start; margin: 14px 0 10px; cursor: pointer; font-size: 12.5px; color: #4a463f; line-height: 1.5; }
      .pay-agree input { width: 15px; height: 15px; margin-top: 2px; accent-color: var(--crimson); flex: none; }
      @media (max-width: 520px) { .pay-methods { grid-template-columns: 1fr; } .pay-amount { font-size: 26px; } }
    </style>
@endpush
@section('content')
      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="pay-wrap">
              <a class="pay-back" href="{{ route('customer.booking-history') }}"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Booking History</a>
              <h1 class="pay-title">{{ $payOpen ? 'Pay for your booking' : 'Payment' }}</h1>
              <p class="pay-sub">{{ $payB['bookingId'] }} &middot; {{ $payB['roomName'] }}, {{ $payB['venueName'] }}</p>

              @if ($paySent === 'accepted')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Receipt received and checked</strong>Staff give the final confirmation once they find the payment in GCash.</div></div>
              @elseif ($paySent === 'manual_review')
                <div class="pay-note is-warn" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Receipt received — a coordinator will check it</strong>Something did not match automatically, so a person will look at it. Your slot is held while they do; you do not need to resubmit.</div></div>
              @elseif ($paySent === 'limit')
                <div class="pay-note is-bad" role="status"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>That was your last receipt attempt</strong>The receipt could not be accepted automatically, and you have now used all {{ $payAllowed }} attempts. Staff will review your payment and contact you.</div></div>
              @elseif ($paySent === 'cash')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Noted — you will pay in cash</strong>Pay at {{ $payCash['where'] }} and quote your reference. Staff record it there.{{ $payCashLocked ? ' Changed your mind? Cancel it below to pay by GCash instead.' : '' }}</div></div>
              @elseif ($paySent === 'cash_cancelled')
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Cash payment cancelled</strong>GCash is open again. You can still choose to pay in cash at {{ $payCash['where'] }}.</div></div>
              @endif

              <!-- the booking being paid -->
              <section class="pay-card" aria-label="Booking">
                <div class="pay-grid">
                  <div><div class="pay-k">Reference</div><div class="pay-v">{{ $payB['bookingId'] }}</div></div>
                  <div><div class="pay-k">{{ $payIsHostel ? 'Stay' : 'Event' }}</div><div class="pay-v">{{ $payB['eventName'] }}</div></div>
                  <div><div class="pay-k">{{ $payIsHostel ? 'Check-in' : 'Date' }}</div><div class="pay-v">{{ $payB['eventDate'] }}</div></div>
                  <div><div class="pay-k">Amount due</div><div class="pay-v">{{ $payB['amount'] }}</div></div>
                  <div><div class="pay-k">Payment</div><div class="pay-v">{{ $payB['paymentStatus'] }}</div></div>
                  @if ($payOpen || $payCode === 'await_event')
                    <div><div class="pay-k">{{ $payCode === 'overdue' ? 'Was due' : 'Pay by' }}</div><div class="pay-v">{{ $payB['payment']['payByLabel'] }}</div></div>
                  @endif
                </div>
              </section>

              @if ($payCode === 'under_review')
                <!-- a receipt is in; staff have it -->
                <div class="pay-note is-warn"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Your receipt is with staff</strong>They match the reference number in the business GCash history, then confirm. You cannot upload another receipt while this one is being reviewed.@if ($payUsed >= $payAllowed) This was your last allowed attempt ({{ $payUsed }} of {{ $payAllowed }}).@endif</div></div>
                @if ($payReceipt)
                  <section class="pay-card" aria-label="Receipt under review">
                    <div class="pay-grid">
                      <div><div class="pay-k">Reference no.</div><div class="pay-v">{{ $payReceipt['reference_number'] }}</div></div>
                      <div><div class="pay-k">Amount read</div><div class="pay-v">{{ $payReceipt['amount_centavos'] !== null ? '₱' . number_format(((int) $payReceipt['amount_centavos']) / 100, 2) : '—' }}</div></div>
                      <div><div class="pay-k">Sent to</div><div class="pay-v">{{ $payReceipt['receiver_number'] ?: '—' }}</div></div>
                    </div>
                  </section>
                @endif

              @elseif (!$payOpen)
                @php [$payNoteHead, $payNoteText] = $payStateNote; @endphp
                <div class="pay-note {{ in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'is-ok' : ($payCode === 'expired' ? 'is-bad' : 'is-info') }}">
                  <i class="bi {{ in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'bi-check-circle' : 'bi-info-circle' }}" aria-hidden="true"></i>
                  <div><strong>{{ $payNoteHead }}</strong>{{ $payNoteText }}</div>
                </div>

              @elseif ($payDemoBlocked)
                <div class="pay-note is-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>Demo mode is on</strong>Payments on real bookings are not recorded during a demo. Ask an administrator to turn demo mode off, then pay here.</div></div>

              @else
                @if ($payCode === 'overdue')
                  <div class="pay-note is-bad"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>This payment is overdue</strong>It was due {{ $payB['payment']['payByLabel'] }}. It can still be paid, but staff may release the slot.</div></div>
                @elseif (!empty($payB['payToUse']))
                  <!-- the hostel is pay to use (2026-10-27, DB-DECISIONS #26) -->
                  <div class="pay-note is-info"><i class="bi bi-info-circle" aria-hidden="true"></i><div><strong>Pay before you check in</strong>Pay here by GCash, or in cash at the front desk when you arrive. If it is not paid by the end of {{ $payB['payment']['payByLabel'] }}, the beds are released. You get your bed once the stay is paid.</div></div>
                @endif
                <!-- [4b] the payment itself — drawn by the script below -->
                <div id="payApp"></div>
              @endif
            </div>
          </div>
        </div>
      </main>
@endsection
@push('scripts')
    @if ($payOpen && !$payDemoBlocked)
    <!-- [5] GCASH CHECKER — the shared engine (OCR + verdict). It calls back into
         gcAccount(), gcExpectedCentavos(), gcBookingRef(), state and render(). -->
    @php include base_path('legacy/includes/gcash-checker.php'); @endphp

    <!-- [6] SCRIPT -->
    <script>
      const PAY = {!! json_encode([
          'ref'       => $payB['bookingId'],
          'centavos'  => (int) round(((float) $payB['amountValue']) * 100),
          'amount'    => $payB['amount'],
          'account'   => $payAccount,                       /* null = no GCash for this venue */
          'method'    => $payCode === 'await_cash' ? 'cash' : 'gcash',
          'cashChosen'=> $payCode === 'await_cash',
          'cashLocked'=> $payCashLocked,                        /* cash chosen -> GCash closed until cancelled */
          'cash'      => $payCash,
          'hostel'    => $payIsHostel,
          'used'      => $payUsed,                          /* GCash receipt uploads so far (5-attempt rule) */
          'allowed'   => $payAllowed,
          'prior'     => $payPrior,                         /* earlier uploads on this booking: no, sha, ref, outcome */
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!};
      const CSRF = @json(csrf_token());
      const SUBMIT_URL = @json(route('customer.payment-submit')), PAY_URL = @json(route('customer.pay')), FAQ_URL = @json(route('customer.faq'));

      /* `state` and render() are what the shared checker drives. */
      const state = { ocr: null, agreeExact: false, method: (PAY.account && !PAY.cashLocked) ? PAY.method : 'cash', sending: false, error: null };

      function gcAccount()          { return PAY.account || { name: '', number: '' }; }
      function gcExpectedCentavos() { return PAY.centavos; }
      function gcBookingRef()       { return PAY.ref; }
      function gcPriorUploads()     { return PAY.prior; }

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      function fmtNumber(n) { return String(n || '').replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function setMethod(m) { if (m === 'gcash' && (!PAY.account || PAY.cashLocked)) return; state.method = m; state.error = null; render(); }
      function toggleAgree(el) { state.agreeExact = !!el.checked; render(); }

      /* The receipt panel: upload zone -> OCR progress -> verdict card (same three
         phases and wording as the booking pages). */
      function receiptPanelHtml() {
        const o = state.ocr;
        const fileInput = `<input type="file" id="gcFile" accept="image/png,image/jpeg,image/webp" style="display:none" onchange="checkReceipt(this)">`;
        if (!o) {
          const on = state.agreeExact;
          return fileInput + `
            <div onclick="${on ? 'pickReceipt()' : ''}" role="button" tabindex="${on ? 0 : -1}" style="border:1.5px dashed rgba(0,0,0,.18);border-radius:11px;padding:26px 14px;text-align:center;background:#fff;${on ? 'cursor:pointer' : 'opacity:.55;cursor:not-allowed'}">
              <div style="font-size:13.5px;font-weight:600;color:#4a463f">${on ? 'Tap to upload a screenshot of your GCash receipt' : 'Tick the exact-amount box above to enable the upload'}</div>
              <div style="font-size:12.5px;color:#a5a19a;margin-top:3px">PNG or JPG · keep the reference number and amount visible · checked instantly with OCR</div>
            </div>`;
        }
        if (o.phase === 'reading') {
          return `
            <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;padding:14px;background:#fff">
              <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:9px">
                <span style="font-size:13px;font-weight:600;color:#4a463f;overflow-wrap:anywhere">${esc(o.fileName)}</span>
                <span id="gcBarLabel" style="font-size:12px;color:#8a857d;flex:none">${esc(o.label)} · ${Math.round((o.pct || 0) * 100)}%</span>
              </div>
              <div style="height:6px;border-radius:999px;background:#e7e4de;overflow:hidden">
                <div id="gcBar" style="height:100%;width:${Math.round((o.pct || 0) * 100)}%;background:#a11626;border-radius:999px;transition:width .25s"></div>
              </div>
              <div style="font-size:12.5px;color:#a5a19a;margin-top:8px">Reading the receipt with OCR — runs in your browser, usually 5–15 seconds.</div>
            </div>`;
        }
        const r = o.rec;
        /* One of THIS booking's earlier uploads again: said plainly, with what
           became of it — it is not sent, so no attempt is used. */
        const V = r.prior
          ? { bg: '#fdf2f2', bd: '#f2d8d8', fg: '#b23a3a', t: 'You already uploaded this receipt',
              s: `Attempt ${r.prior.no} — ${r.prior.outcome}. The same receipt cannot be accepted again, so it will not be sent and no attempt is used. Please upload a different receipt.` }
          : {
          accepted:      { bg: '#f2faf5', bd: '#d4ebdd', fg: '#1c7a4f', t: 'Receipt looks right', s: 'All automatic checks passed. Submit it below; staff give the final confirmation in GCash.' },
          manual_review: { bg: '#fdf7ee', bd: '#f3e3c8', fg: '#8a5a12', t: 'A coordinator will check this receipt', s: 'Something did not match automatically. You can still submit it — a person will look at it and your slot is held meanwhile.' },
          rejected:      { bg: '#fdf2f2', bd: '#f2d8d8', fg: '#b23a3a', t: 'This receipt cannot be accepted', s: 'Fix the issues below, then upload a new receipt.' },
        }[r.status];
        const p = r.parsed;
        /* Same image: the OCR was skipped, so there is nothing read to show. */
        const kv = r.reasons.includes('already_uploaded_file') ? [] : [
          ['Ref no.', p.refDisplay || p.ref || '—'],
          ['Amount read', p.effAmountC != null ? centavosFmt(p.effAmountC) : '—'],
          ['Amount required', centavosFmt(gcExpectedCentavos())],
          ['Date & time', p.datetime || '—'],
          ['Sent to', p.receiverNumber || p.receiverNameMasked || p.receiverNameShort || '—'],
        ];
        const issues = r.prior ? [] : [...r.reasons.map(c => ({ c, hard: true })), ...r.flags.map(c => ({ c, hard: false }))];
        return fileInput + `
          <div style="border:1px solid rgba(0,0,0,.12);border-radius:11px;overflow:hidden;background:#fff">
            <div style="display:flex;gap:11px;align-items:flex-start;padding:13px 14px;background:${V.bg};border-bottom:1px solid ${V.bd}">
              <div style="flex:1">
                <div style="font-size:13.5px;font-weight:640;color:${V.fg}">${V.t}</div>
                <div style="font-size:12px;color:${V.fg};opacity:.85;margin-top:2px">${esc(V.s)}</div>
              </div>
              <button type="button" onclick="removeReceipt()" style="flex:none;background:#fff;border:1px solid rgba(0,0,0,.14);border-radius:8px;padding:6px 11px;font-size:12px;font-weight:600;color:#4a463f;cursor:pointer">${r.status === 'rejected' ? 'Try another' : 'Remove'}</button>
            </div>
            <div style="display:flex;gap:13px;padding:13px 14px">
              ${o.thumb ? `<img src="${o.thumb}" alt="receipt" style="width:64px;height:84px;object-fit:cover;border-radius:8px;border:1px solid rgba(0,0,0,.1);flex:none">` : ''}
              <div style="flex:1;display:grid;grid-template-columns:1fr 1fr;gap:8px 14px;align-content:start">
                ${kv.map(([k, v]) => `<div><div style="font-size:12px;color:#8a857d">${k}</div><div style="font-size:13px;font-weight:640;color:#1c1b19;overflow-wrap:anywhere">${esc(v)}</div></div>`).join('')}
              </div>
            </div>
            ${issues.length ? `
            <div style="padding:2px 14px 12px">
              ${issues.map(i => `<div style="display:flex;gap:8px;align-items:flex-start;padding:4px 0"><span style="width:7px;height:7px;border-radius:999px;background:${i.hard ? '#b23a3a' : '#c99a3c'};margin-top:5px;flex:none"></span><span style="font-size:12.5px;color:#4a463f;line-height:1.45">${esc(GC_LABELS[i.c] || i.c)}</span></div>`).join('')}
            </div>` : ''}
            ${r.confidence != null ? `<div style="padding:0 14px 11px;font-size:12px;color:#a5a19a">OCR confidence ${r.confidence}% · ${esc(o.fileName)}</div>` : ''}
          </div>`;
      }

      /* The 5-attempt rule (DB-DECISIONS #6). Every upload counts, rejected ones
         too; the server enforces it — this only says where the customer stands. */
      function attemptsLeft() { return Math.max(0, PAY.allowed - PAY.used); }
      function attemptLine() {
        const next = PAY.used + 1, last = attemptsLeft() === 1;
        return `<div style="display:flex;justify-content:space-between;gap:10px;font-size:12.5px;margin:2px 0 8px">
            <span style="font-weight:600;color:${last ? '#b23a3a' : '#5c584f'}">Attempt ${next} of ${PAY.allowed}</span>
            <span style="color:#8a857d">${last ? 'Last try — it goes to staff whatever the check says' : attemptsLeft() + ' receipt uploads left'}</span>
          </div>`;
      }

      function gcashBody() {
        const a = gcAccount();
        if (attemptsLeft() === 0) {
          return `
            <div class="pay-note is-bad"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>All ${PAY.allowed} receipt attempts are used</strong>You cannot upload another GCash receipt for this booking. Staff will review your payment and contact you &mdash; or pay in cash at the office instead.</div></div>`;
        }
        return `
          <div class="pay-note is-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>Send the EXACT amount — not more, not less.</strong>A different amount is not confirmed automatically; a coordinator has to review it.</div></div>
          <section class="pay-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid var(--line)">
              <div><div style="font-size:12.5px;color:#8a857d">Amount to send</div><div class="pay-amount">${esc(PAY.amount)}</div></div>
              <div style="width:56px;height:56px;border-radius:14px;background:#0a6cf0;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750">GCash</div>
            </div>
            <div class="pay-grid" style="margin:14px 0">
              <div><div class="pay-k">GCash account name</div><div class="pay-v">${esc(a.name)}</div></div>
              <div><div class="pay-k">GCash number</div><div class="pay-v">${esc(fmtNumber(a.number))}</div></div>
              <div><div class="pay-k">Reference to include</div><div class="pay-v">${esc(PAY.ref)}</div></div>
            </div>
            <label class="pay-agree"><input type="checkbox" ${state.agreeExact ? 'checked' : ''} onchange="toggleAgree(this)"><span>I understand I must send the <strong>exact amount &mdash; ${esc(PAY.amount)}</strong>.</span></label>
            ${attemptLine()}
            ${receiptPanelHtml()}
          </section>`;
      }

      function cashBody() {
        const c = PAY.cash;
        return `
          <section class="pay-card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;padding-bottom:14px;border-bottom:1px solid var(--line)">
              <div><div style="font-size:12.5px;color:#8a857d">Amount to pay</div><div class="pay-amount">${esc(PAY.amount)}</div></div>
              <div style="width:56px;height:56px;border-radius:14px;background:#a11626;color:#fff;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:750">Cash</div>
            </div>
            <div style="margin-top:14px">
              <div class="pay-k">Where to pay</div>
              <div class="pay-v" style="font-size:15px">${esc(c.where)}</div>
              ${c.address ? `<div style="font-size:13px;color:#4a463f;margin-top:3px">${esc(c.address)}</div>` : ''}
              <div style="font-size:12.5px;color:#8a857d;margin-top:3px">${esc(c.hours)}</div>
            </div>
            <div style="font-size:12.5px;color:#4a463f;line-height:1.7;border-top:1px solid var(--line);margin-top:14px;padding-top:12px">Bring your reference <strong>${esc(PAY.ref)}</strong> and a valid ID. Staff record the payment there and your booking updates on its own.</div>
          </section>`;
      }

      function render() {
        const el = document.getElementById('payApp');
        if (!el) return;
        const cash = state.method === 'cash';
        const ready = cash ? !PAY.cashChosen : (attemptsLeft() > 0 && typeof receiptOk === 'function' && receiptOk());
        const methodBtn = (m, title, sub, off) =>
          `<button type="button" class="pay-method${state.method === m ? ' is-on' : ''}" ${off ? 'disabled style="opacity:.5;cursor:not-allowed"' : ''} onclick="setMethod('${m}')"><b>${title}</b><span>${sub}</span></button>`;
        el.innerHTML = `
          <div class="pay-methods" role="group" aria-label="Payment method">
            ${methodBtn('gcash', 'GCash',
                PAY.cashLocked ? 'Closed while you pay in cash — cancel below to use GCash'
                  : (PAY.account ? 'Send online, then upload your receipt' : 'Not available for this venue yet'),
                !PAY.account || PAY.cashLocked)}
            ${methodBtn('cash', PAY.hostel ? 'Cash — hostel front desk' : 'Cash — at the office', 'Pay in person; staff record it')}
          </div>
          ${cash ? cashBody() : gcashBody()}
          <button type="button" class="pay-btn" ${ready && !state.sending ? '' : 'disabled'} onclick="submitPayment()">${
            state.sending ? 'Sending…' : (cash ? (PAY.cashChosen ? (PAY.hostel ? 'You chose cash — pay at the front desk' : 'You chose cash — pay at the office') : 'I will pay in cash') : 'Submit receipt')}</button>
          ${PAY.cashLocked ? `<button type="button" class="pay-btn" style="background:#fff;color:#a11626;border:1.5px solid #a11626;margin-top:10px" ${state.sending ? 'disabled' : ''} onclick="cancelCash()">Cancel cash payment — pay by GCash instead</button>` : ''}
          ${state.error ? `<div class="pay-err" role="alert">${esc(state.error)}</div>` : ''}
          <div class="pay-small">${cash ? 'Your booking stays as it is until staff record the payment.' : 'Staff confirm every GCash payment against the business GCash history.'} Need help? <a href="${FAQ_URL}" style="color:#a11626;font-weight:600">Check the FAQ</a></div>`;
      }

      /* THE CASH CHOICE (2026-10-28): take back "I will pay in cash" — at the
         hostel front desk or the venue cashier. GCash opens again, and staff
         stop seeing their cash button. The server refuses it once staff have
         already recorded the cash. */
      async function cancelCash() {
        if (state.sending) return;
        if (!(await venusepConfirm('You will pay by GCash instead. Staff will no longer expect cash from you at ' + PAY.cash.where + '.',
              { title: 'Cancel your cash payment?', okLabel: 'Yes, cancel cash', cancelLabel: 'Keep paying cash' }))) return;
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('booking', PAY.ref);
        fd.append('method', 'cancel_cash');
        state.sending = true; state.error = null; render();
        try {
          const res = await fetch(SUBMIT_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) { state.sending = false; state.error = out.message || 'Nothing was changed.'; render(); return; }
          location.href = PAY_URL + '?booking=' + encodeURIComponent(PAY.ref) + '&sent=cash_cancelled';
        } catch (e) {
          state.sending = false;
          state.error = 'Could not reach the server, so nothing was changed.';
          render();
        }
      }

      /* Send it FOR REAL. The server recomputes the verdict against the database
         and has the final word; the page only moves on its answer. */
      async function submitPayment() {
        if (state.sending) return;
        const cash = state.method === 'cash';
        if (!cash && !receiptOk()) return;
        const fd = new FormData();
        fd.append('_token', CSRF);
        fd.append('booking', PAY.ref);
        fd.append('method', cash ? 'cash' : 'gcash');
        if (!cash) {
          const rec = state.ocr.rec, p = rec.parsed || {};
          fd.append('receipt', state.ocr.file, state.ocr.fileName || 'receipt.jpg');
          fd.append('ref', p.ref || '');
          fd.append('receiver_name', p.receiverNameMasked || p.receiverNameShort || '');
          fd.append('receiver_number', p.receiverNumber || '');
          fd.append('amount_centavos', p.effAmountC != null ? p.effAmountC : '');
          fd.append('receipt_datetime', p.datetime || '');
          fd.append('confidence', rec.confidence != null ? rec.confidence : '');
          fd.append('flags', JSON.stringify(rec.flags || []));
          fd.append('text_excerpt', rec.textExcerpt || '');
        }
        state.sending = true; state.error = null; render();
        try {
          const res = await fetch(SUBMIT_URL, { method: 'POST', body: fd, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          /* The server counts every upload, rejected ones too — take its numbers. */
          if (typeof out.attemptsUsed === 'number') PAY.used = out.attemptsUsed;
          if (typeof out.attemptsAllowed === 'number') PAY.allowed = out.attemptsAllowed;
          if (!out.ok) {
            state.sending = false;
            state.error = out.message || 'Your payment was not recorded.';
            /* The server's rejection outranks the browser's preview. */
            if (out.verdict === 'rejected' && state.ocr && state.ocr.rec) { state.ocr.rec.status = 'rejected'; }
            render(); return;
          }
          /* ok + rejected = the LAST attempt was refused but went to staff anyway. */
          const sent = cash ? 'cash' : (out.verdict === 'rejected' ? 'limit' : (out.verdict === 'accepted' ? 'accepted' : 'manual_review'));
          location.href = PAY_URL + '?booking=' + encodeURIComponent(PAY.ref) + '&sent=' + sent;
        } catch (e) {
          state.sending = false;
          state.error = 'Could not reach the server, so your payment was not recorded.';
          render();
        }
      }

      render();
    </script>
    @endif
@endpush
