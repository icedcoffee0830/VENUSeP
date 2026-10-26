<?php require_once __DIR__ . '/../includes/auth.php'; customer_require_login(); /* customers only — guests go to the login page */ ?>
<?php
/* ==================================================================
   PAY — one booking's payment step, reached from "Pay now" in Booking
   History.

   GET  ?booking=<VB-ref>[&sent=accepted|manual_review|cash]

   WHY THIS PAGE EXISTS. The booking pages (room-reservation.php,
   hostel-reservation.php) only ever reached their payment screen through
   the demo-mode "Simulate staff approval" buttons. Once staff REALLY
   approved a booking, the customer had no way back to pay it, so no
   GCash receipt could reach customer/payment-submit.php outside a demo.

   EVERYTHING COMES FROM THE DATABASE: the amount owed is the booking's
   own total_amount (which already carries any USeP discount staff
   granted at approval), and the account is the venue's active
   gcash_accounts row (includes/payment-settings.php). Nothing here is
   recomputed in the browser the way the booking wizard prices a stay.

   THE RECEIPT CHECK is the shared engine (includes/gcash-checker.php),
   the same one the booking pages use. Its verdict in the browser is a
   preview; customer/payment-submit.php recomputes it against the
   database and has the final word.

   Someone else's booking: 404, like receipt.php and document-view.php.
   ================================================================== */
require_once __DIR__ . '/../includes/customer-bookings.php';
require_once __DIR__ . '/../includes/payment-settings.php';   /* $gcAccounts — the ONE source of each venue's GCash account */
require_once __DIR__ . '/../includes/demo-mode.php';

function pay_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$payRef = (string) ($_GET['booking'] ?? '');
/* Scoped to THIS customer's own bookings (demo ones included): typing another
   customer's reference finds nothing. */
$payB = cb_find($customerBookings, $payRef);
if ($payB === null) {
    pay_not_found();
}

$payCode    = (string) $payB['paymentCode'];
$payOpen    = in_array($payCode, ['await_gcash', 'await_cash', 'overdue'], true);
$payIsDemo  = !empty($payB['isDemo']);
$payIsHostel= $payB['type'] === 'hostel';
$payAccount = gcAccountFor($gcAccounts, $payB['venueName']);   /* null = this venue cannot take GCash yet */
/* DEMO MODE: only bookings made during the demo can be "paid" — a real booking
   paid while the switch is on would be written for real. payment-submit.php
   refuses it too; this just says so before anyone uploads anything. */
$payDemoBlocked = demo_mode_on() && !$payIsDemo;

/* The receipt staff are reviewing, if one is in. Demo bookings never stored one. */
$payReceipt = (!$payIsDemo && in_array($payCode, ['under_review', 'confirmed'], true)) ? booking_receipt($payB['id']) : null;

$paySent = in_array($_GET['sent'] ?? '', ['accepted', 'manual_review', 'cash', 'limit'], true) ? $_GET['sent'] : '';

/* The 5-attempt rule (DB-DECISIONS #6): uploads made, uploads allowed. A demo
   booking has neither, so it simply starts at 0 of 5. */
$payUsed    = (int) ($payB['receiptAttempts'] ?? 0);
$payAllowed = (int) ($payB['receiptAttemptsAllowed'] ?? 5);
/* This booking's own earlier uploads (the customer's own), so the page can
   say "you already uploaded this receipt" BEFORE it is sent: payment-submit.php
   refuses it without counting an attempt, and this spares the wait for it. */
$payPrior = ($payOpen && !$payIsDemo) ? array_map(function ($a) {
    return ['no' => (int) $a['attempt_no'], 'sha' => $a['sha256_hash'], 'ref' => (string) $a['reference_number'],
            'outcome' => $a['outcome']];
}, booking_receipt_attempts($payB['id'])) : [];

/* Where cash is handed over. [SIM] the same placeholders as the booking pages'
   CASH_PAY (room-reservation.php / hostel-reservation.php) — swap all three
   together when the real payment location is decided. */
$payCash = $payIsHostel
    ? ['where' => 'USeP Hostel front desk', 'address' => '', 'hours' => 'Mon–Sat · 8:00 AM – 5:00 PM']
    : ['where' => 'USeP Cashier — Venue Reservations Window',
       'address' => 'Ground Floor, Administration Building, USeP Obrero Campus, Davao City',
       'hours' => 'Mon–Fri · 8:00 AM – 5:00 PM'];

$payCsrf = csrf_token();
header('Cache-Control: private, no-store');

/* One sentence for every state that is NOT "pay now". */
function pay_state_note(array $b, $code) {
    switch ($code) {
        case 'locked':      return ['Waiting for approval', 'Staff are reviewing your ID and reservation. Payment opens here once they approve it.'];
        case 'await_event': return ['Payment opens after your event', 'This booking is paid after it happens. Payment opens ' . $b['payment']['opensLabel'] . ' and is due by ' . $b['payment']['payByLabel'] . '.'];
        case 'await_pos':   return ['Waiting for your POS', 'Hostel staff are getting your POS (order of payment) from CEDU. Payment opens here as soon as it is recorded.'];
        case 'confirmed':
        case 'paid_cash':   return ['Paid', 'Your payment is confirmed. Nothing more to do.'];
        case 'expired':     return ['This booking expired', 'It was not paid before its deadline, so the dates were released.'];
        default:            return ['Nothing to pay here', 'This booking is not waiting on a payment.'];
    }
}
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
       [4] CONTENT (summary + the payment card) · [5] GCASH CHECKER · [6] SCRIPT
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Pay <?php echo bh_e($payB['bookingId']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
    <!-- Tesseract.js — in-browser OCR for the GCash receipt checker (needs internet) -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>

    <!-- [0] SHELL CSS — shared layout (same block as receipt.php) -->
    <style>
      :root {
        --black: #1f1e1e; --border: #e5e5e5; --muted: #606a75;
        --venusep-sidebar-width: 235px; --venusep-header-height: 58px;
      }
      * { box-sizing: border-box; }
      html, body { margin: 0; min-height: 100%; }
      body { background: #fff; color: var(--black); font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; overflow-x: hidden; }
      .app-main { margin-left: var(--venusep-sidebar-width); padding-top: var(--venusep-header-height); min-height: 100vh; background: #fff; }
      .container-fluid { width: 100%; padding-inline: 30px; }
      .app-content { padding: 0.25rem 0 3rem; }
      @media (max-width: 767.98px) {
        :root { --venusep-sidebar-width: 0px; --venusep-header-height: 56px; }
        .sidebar { transform: translateX(-100%); }
        .app-header { left: 0; }
        .app-main { margin-left: 0; }
        .container-fluid { padding-inline: 16px; }
      }
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
  </head>
  <body class="pay-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Booking History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="pay-wrap">
              <a class="pay-back" href="booking-history.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Booking History</a>
              <h1 class="pay-title"><?php echo $payOpen ? 'Pay for your booking' : 'Payment'; ?></h1>
              <p class="pay-sub"><?php echo bh_e($payB['bookingId']); ?> &middot; <?php echo bh_e($payB['roomName']); ?>, <?php echo bh_e($payB['venueName']); ?></p>

              <?php if ($paySent === 'accepted'): ?>
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Receipt received and checked</strong>Staff give the final confirmation once they find the payment in GCash.</div></div>
              <?php elseif ($paySent === 'manual_review'): ?>
                <div class="pay-note is-warn" role="status"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Receipt received — a coordinator will check it</strong>Something did not match automatically, so a person will look at it. Your slot is held while they do; you do not need to resubmit.</div></div>
              <?php elseif ($paySent === 'limit'): ?>
                <div class="pay-note is-bad" role="status"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>That was your last receipt attempt</strong>The receipt could not be accepted automatically, and you have now used all <?php echo $payAllowed; ?> attempts. Staff will review your payment and contact you.</div></div>
              <?php elseif ($paySent === 'cash'): ?>
                <div class="pay-note is-ok" role="status"><i class="bi bi-check-circle" aria-hidden="true"></i><div><strong>Noted — you will pay in cash</strong>Pay at <?php echo bh_e($payCash['where']); ?> and quote your reference. Staff record it there.</div></div>
              <?php endif; ?>

              <!-- the booking being paid -->
              <section class="pay-card" aria-label="Booking">
                <div class="pay-grid">
                  <div><div class="pay-k">Reference</div><div class="pay-v"><?php echo bh_e($payB['bookingId']); ?></div></div>
                  <div><div class="pay-k"><?php echo $payIsHostel ? 'Stay' : 'Event'; ?></div><div class="pay-v"><?php echo bh_e($payB['eventName']); ?></div></div>
                  <div><div class="pay-k"><?php echo $payIsHostel ? 'Check-in' : 'Date'; ?></div><div class="pay-v"><?php echo bh_e($payB['eventDate']); ?></div></div>
                  <div><div class="pay-k">Amount due</div><div class="pay-v"><?php echo bh_e($payB['amount']); ?></div></div>
                  <div><div class="pay-k">Payment</div><div class="pay-v"><?php echo bh_e($payB['paymentStatus']); ?></div></div>
                  <?php if ($payOpen || $payCode === 'await_event'): ?>
                    <div><div class="pay-k"><?php echo $payCode === 'overdue' ? 'Was due' : 'Pay by'; ?></div><div class="pay-v"><?php echo bh_e($payB['payment']['payByLabel']); ?></div></div>
                  <?php endif; ?>
                </div>
              </section>

              <?php if ($payCode === 'under_review'): ?>
                <!-- a receipt is in; staff have it -->
                <div class="pay-note is-warn"><i class="bi bi-hourglass-split" aria-hidden="true"></i><div><strong>Your receipt is with staff</strong>They match the reference number in the business GCash history, then confirm. You cannot upload another receipt while this one is being reviewed.<?php
                  if ($payUsed >= $payAllowed): ?> This was your last allowed attempt (<?php echo $payUsed; ?> of <?php echo $payAllowed; ?>).<?php endif; ?></div></div>
                <?php if ($payReceipt): ?>
                  <section class="pay-card" aria-label="Receipt under review">
                    <div class="pay-grid">
                      <div><div class="pay-k">Reference no.</div><div class="pay-v"><?php echo bh_e($payReceipt['reference_number']); ?></div></div>
                      <div><div class="pay-k">Amount read</div><div class="pay-v"><?php echo $payReceipt['amount_centavos'] !== null ? '₱' . number_format(((int) $payReceipt['amount_centavos']) / 100, 2) : '—'; ?></div></div>
                      <div><div class="pay-k">Sent to</div><div class="pay-v"><?php echo bh_e($payReceipt['receiver_number'] ?: '—'); ?></div></div>
                    </div>
                  </section>
                <?php endif; ?>

              <?php elseif (!$payOpen): ?>
                <?php [$payNoteHead, $payNoteText] = pay_state_note($payB, $payCode); ?>
                <div class="pay-note <?php echo in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'is-ok' : ($payCode === 'expired' ? 'is-bad' : 'is-info'); ?>">
                  <i class="bi <?php echo in_array($payCode, ['confirmed', 'paid_cash'], true) ? 'bi-check-circle' : 'bi-info-circle'; ?>" aria-hidden="true"></i>
                  <div><strong><?php echo bh_e($payNoteHead); ?></strong><?php echo bh_e($payNoteText); ?></div>
                </div>

              <?php elseif ($payDemoBlocked): ?>
                <div class="pay-note is-warn"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div><strong>Demo mode is on</strong>Payments on real bookings are not recorded during a demo. Ask an administrator to turn demo mode off, then pay here.</div></div>

              <?php else: ?>
                <?php if ($payCode === 'overdue'): ?>
                  <div class="pay-note is-bad"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><div><strong>This payment is overdue</strong>It was due <?php echo bh_e($payB['payment']['payByLabel']); ?>. It can still be paid, but staff may release the slot.</div></div>
                <?php endif; ?>
                <!-- [4b] the payment itself — drawn by the script below -->
                <div id="payApp"></div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </main>
    </div>

    <?php if ($payOpen && !$payDemoBlocked): ?>
    <!-- [5] GCASH CHECKER — the shared engine (OCR + verdict). It calls back into
         gcAccount(), gcExpectedCentavos(), gcBookingRef(), state and render(). -->
    <?php include __DIR__ . '/../includes/gcash-checker.php'; ?>

    <!-- [6] SCRIPT -->
    <script>
      const PAY = <?php echo json_encode([
          'ref'       => $payB['bookingId'],
          'centavos'  => (int) round(((float) $payB['amountValue']) * 100),
          'amount'    => $payB['amount'],
          'account'   => $payAccount,                       /* null = no GCash for this venue */
          'method'    => $payCode === 'await_cash' ? 'cash' : 'gcash',
          'cashChosen'=> $payCode === 'await_cash',
          'cash'      => $payCash,
          'hostel'    => $payIsHostel,
          'used'      => $payUsed,                          /* GCash receipt uploads so far (5-attempt rule) */
          'allowed'   => $payAllowed,
          'prior'     => $payPrior,                         /* earlier uploads on this booking: no, sha, ref, outcome */
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
      const CSRF = <?php echo json_encode($payCsrf); ?>;

      /* `state` and render() are what the shared checker drives. */
      const state = { ocr: null, agreeExact: false, method: PAY.account ? PAY.method : 'cash', sending: false, error: null };

      function gcAccount()          { return PAY.account || { name: '', number: '' }; }
      function gcExpectedCentavos() { return PAY.centavos; }
      function gcBookingRef()       { return PAY.ref; }
      function gcPriorUploads()     { return PAY.prior; }

      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
      function fmtNumber(n) { return String(n || '').replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function setMethod(m) { if (m === 'gcash' && !PAY.account) return; state.method = m; state.error = null; render(); }
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
            ${methodBtn('gcash', 'GCash', PAY.account ? 'Send online, then upload your receipt' : 'Not available for this venue yet', !PAY.account)}
            ${methodBtn('cash', PAY.hostel ? 'Cash — hostel front desk' : 'Cash — at the office', 'Pay in person; staff record it')}
          </div>
          ${cash ? cashBody() : gcashBody()}
          <button type="button" class="pay-btn" ${ready && !state.sending ? '' : 'disabled'} onclick="submitPayment()">${
            state.sending ? 'Sending…' : (cash ? (PAY.cashChosen ? 'You chose cash — pay at the office' : 'I will pay in cash') : 'Submit receipt')}</button>
          ${state.error ? `<div class="pay-err" role="alert">${esc(state.error)}</div>` : ''}
          <div class="pay-small">${cash ? 'Your booking stays as it is until staff record the payment.' : 'Staff confirm every GCash payment against the business GCash history.'} Need help? <a href="faq.php" style="color:#a11626;font-weight:600">Check the FAQ</a></div>`;
      }

      /* Send it FOR REAL. The server recomputes the verdict against the database
         and has the final word; the page only moves on its answer. */
      async function submitPayment() {
        if (state.sending) return;
        const cash = state.method === 'cash';
        if (!cash && !receiptOk()) return;
        const fd = new FormData();
        fd.append('csrf', CSRF);
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
          const res = await fetch('payment-submit.php', { method: 'POST', body: fd, credentials: 'same-origin' });
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
          location.href = 'pay.php?booking=' + encodeURIComponent(PAY.ref) + '&sent=' + sent;
        } catch (e) {
          state.sending = false;
          state.error = 'Could not reach the server, so your payment was not recorded.';
          render();
        }
      }

      render();
    </script>
    <?php endif; ?>
  </body>
</html>
