<?php require_once __DIR__ . '/../includes/auth.php'; customer_require_login(); /* customers only — guests go to the login page */ ?>
<?php
/* ==================================================================
   REFUND REQUEST — VENUSeP (customer portal)
   ==================================================================
   The customer's only entry into the refund chain. Reached from the
   "Request Refund" action in booking-history.php, which only offers it
   on bookings cb_is_refundable() accepts.
   AGREED 2026-09-09:
     · The BOOKING IS NOT CANCELLED when the request is submitted. It stays
       the customer's for the whole process — they can withdraw at any time
       before staff decide, and a DENIED request leaves the booking intact.
       The date is released only when the refund is actually COMPLETED, i.e.
       when the customer has their money. Nothing is taken until something
       is given, and staff (who chase the OR) are the ones who feel the delay.
     · Denial is FINAL. The customer would have to book again from scratch.
       A PAPERWORK problem is therefore not a denial — staff return it for
       correction instead, with a bounded window to resubmit.
     · The customer never types an amount — it is the full amount paid,
       shown read-only. Staff set the final figure
       (refunds.amount_approved); admin has the final say either way.
     · Reason = category + required free text. The category exists so
       refunds are reportable, and so venue-fault cases (a maintenance
       closure — DB-DECISIONS #11 'disrupted') stay distinguishable from
       customer-fault ones. Staff SEE the reason on the request.
     · Documents: the transaction receipt and the proof of payment are
       required TO FILE. The Official Receipt is required to be PAID but may
       follow later — it needs a trip to the Cashier, and gating submission
       on it would let an honest customer miss the filing deadline through
       paperwork timing alone. Same principle as PROJECT-HANDOFF 4.8: the OR
       is a document, not a status. No OR, no money — but the request stands.
     · Filing deadline = at least 1 day before the event, the SAME rule as
       the payment deadline (PROJECT-HANDOFF 4.7). cb_is_refundable() already
       expresses exactly this; no separate check is needed.
     · Venue bookings only for now. Hostel refunds are a separate decision.

   ELIGIBILITY IS RE-CHECKED HERE, not trusted from the link — someone can
   type ?booking= by hand. Both pages ask the same cb_is_refundable() so
   they can never disagree. Since 2026-09-16 that includes the refund policy
   snapshot: a booking made while the admin refund switch was OFF is refused
   here, server-side (includes/refund-policy.php).

   [SIM] NOT WIRED: no POST handler, no database, no upload is stored. On
   submit the page shows its confirmation state and drops the reference in
   sessionStorage so booking-history.php can show the request as pending.
   The shape it produces is what admin/booking-request.php consumes
   (pay:'refund_req').
   ================================================================== */
require_once __DIR__ . '/../includes/customer-bookings.php';

/* is_string matters: ?booking[]=x makes the (string) cast raise an
   "Array to string conversion" warning, and PHP prints the full server path
   in it. Guard the type, do not cast a hostile shape. */
$requestedRef = (isset($_GET['booking']) && is_string($_GET['booking']))
    ? substr(trim($_GET['booking']), 0, 64)
    : null;
$requestedRef = $requestedRef === null ? "" : $requestedRef;
$booking      = $requestedRef === "" ? null : cb_find($customerBookings, $requestedRef);

/* Why the form cannot be shown, in the customer's words. null = show it.
   Order matters: the payment axis carries the whole refund chain, so a booking
   that has ALREADY been refunded is not "unpaid" — checking `!== Paid` first
   would tell a refunded customer their booking was never paid for. */
$refusal = null;
if ($booking === null) {
    $refusal = $requestedRef === ""
        ? 'No booking was selected. Open a booking from your history and choose "Request Refund".'
        : 'We could not find booking ' . $requestedRef . ' on your account.';
} elseif (!cb_is_refundable($booking)) {
    $ref = $booking['bookingId'];
    if ($booking['paymentStatus'] === 'Refunded') {
        $refusal = 'Booking ' . $ref . ' has already been refunded, so there is nothing left to request. If the amount never reached you, contact the venue office with your reference.';
    } elseif ($booking['paymentStatus'] === 'Refund requested') {
        $refusal = 'Booking ' . $ref . ' already has a refund request with USeP staff. Withdraw the existing one from your Booking History if you need to change it.';
    } elseif ($booking['paymentStatus'] !== 'Paid') {
        $refusal = 'Booking ' . $ref . ' has not been paid, so there is nothing to refund. Its payment status is "' . $booking['paymentStatus'] . '".';
    } elseif ($booking['eventDateIso'] <= date('Y-m-d')) {
        $refusal = 'The event for booking ' . $ref . ' was held on ' . $booking['eventDate'] . '. Refunds can only be requested before the event takes place — please contact the venue office directly.';
    } elseif (empty($booking['refundsAllowed'])) {
        /* The policy snapshot (2026-09-16). Checked on the SERVER, so typing
           ?booking= by hand for a non-refundable booking gets this, not a form. */
        $refusal = 'Booking ' . $ref . ' is non-refundable. It was made while USeP\'s no-refund policy was in effect, and a booking keeps the policy it was made under. If USeP closes or cancels your venue, the venue office will offer you a replacement room or a new date instead.';
    } else {
        $refusal = 'Booking ' . $ref . ' is not eligible for a refund request. Its booking status is "' . $booking['bookingStatus'] . '".';
    }
}

/* Documents (PROJECT-HANDOFF 4.10, refined 2026-09-09).
   `optional => false` = required before the request can be FILED.
   `optional => true`  = required before the refund can be PAID; the customer
   may submit without it and say so. The server decides this, not the browser.

   Note the asymmetry, and it is deliberate: a GCash payer already HAS their
   proof of payment on their phone, so it is required up front. A cash payer's
   proof of payment IS the official receipt they were handed at the counter —
   the same document, not a second one — so it is asked for once, as the
   Official Receipt, and may follow later. */
$requiredDocs = [];
if ($booking !== null) {
    $requiredDocs[] = ['key' => 'txn', 'label' => 'System Transaction Receipt', 'optional' => false,
        'hint' => 'Issued by VENUSeP when your booking was confirmed.'];
    if ($booking['method'] === 'GCash') {
        $requiredDocs[] = ['key' => 'gcash', 'label' => 'GCash Payment Receipt', 'optional' => false,
            'hint' => 'The GCash receipt screenshot you sent when you paid.'];
    }
    $requiredDocs[] = ['key' => 'or', 'label' => 'Official Receipt (OR)', 'optional' => true,
        'hint' => $booking['method'] === 'GCash'
            ? 'Issued by the University Cashier. Needed before your refund can be paid out — send it later if you do not have it yet.'
            : 'The official receipt you were given at the counter when you paid. Needed before your refund can be paid out — send it later if you do not have it to hand.'];
}

$refundReasons = [
    'Event cancelled by the organiser',
    'Schedule conflict — need a different date',
    'Booked the wrong room or venue',
    'Room unavailable or closed by USeP',
    'Paid twice / wrong amount sent',
    'Other',
];
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
       [4] CONTENT (refusal panel OR form + booking summary) ·
       [5] SCRIPT (validation, confirmation, refund marker)
  [SIM] = demo-only, replace at database time.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Request a Refund</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS — shared layout, identical to the other customer pages -->
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
      }
    </style>

    <!-- [1] PAGE CSS — same palette as booking-history.php -->
    <style>
      .rr-page { padding: 22px 0 0; }
      .rr-back { display: inline-flex; align-items: center; gap: 6px; font-size: 12.5px; font-weight: 600; color: var(--muted); text-decoration: none; margin-bottom: 12px; }
      .rr-back:hover { color: var(--black); }
      .rr-page > h1 { font-size: 22px; font-weight: 700; letter-spacing: -0.01em; margin: 0; }
      .rr-lede { color: var(--muted); font-size: 13px; margin: 4px 0 20px; max-width: 86ch; line-height: 1.6; }

      .rr-grid { display: grid; grid-template-columns: minmax(0, 1fr) 340px; gap: 20px; align-items: start; }
      .rr-card { border: 1px solid var(--border); border-radius: 12px; background: #fff; }
      .rr-card-head { padding: 15px 18px 13px; border-bottom: 1px solid #f2efe9; }
      .rr-card-head h2 { margin: 0; font-size: 14.5px; font-weight: 650; }
      .rr-card-head p { margin: 3px 0 0; font-size: 12.5px; color: var(--muted); }
      .rr-card-body { padding: 18px; }
      /* Wide screens: the form's OWN sections split into two columns so the card
         fills the width instead of running down the page as one long ribbon.
         Reason + explanation on the left, documents + the consequences on the
         right. Collapses back to a single column below 1180px. */
      .rr-cols { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 26px; align-items: start; }
      .rr-col > .rr-section:last-child { margin-bottom: 0; }
      @media (max-width: 1180px) {
        .rr-cols { grid-template-columns: minmax(0, 1fr); }
        .rr-col > .rr-section:last-child { margin-bottom: 20px; }
      }

      .rr-section { margin-bottom: 20px; }
      .rr-section:last-child { margin-bottom: 0; }
      .rr-label { display: block; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: #6b6258; margin-bottom: 7px; }
      .rr-req::after { content: " *"; color: #b23a3a; }
      .rr-control { width: 100%; border: 1px solid #d7d7d7; border-radius: 9px; background: #fff; font: inherit; font-size: 13px; color: var(--black); padding: 10px 11px; }
      .rr-control:focus { outline: none; border-color: var(--black); }
      textarea.rr-control { min-height: 108px; resize: vertical; line-height: 1.55; }
      .rr-hint { margin: 6px 0 0; font-size: 11.5px; color: var(--muted); }

      .rr-docs { display: grid; gap: 8px; }
      .rr-doc { display: flex; align-items: center; gap: 10px; border: 1px solid #e6e2da; border-radius: 10px; padding: 11px 12px; }
      .rr-doc > i { font-size: 15px; color: var(--muted); flex: none; }
      .rr-doc-txt { min-width: 0; flex: 1; }
      .rr-doc-txt b { display: block; font-size: 12.5px; font-weight: 640; }
      .rr-doc-txt small { display: block; color: var(--muted); font-size: 11.5px; line-height: 1.45; }
      .rr-file { flex: none; max-width: 178px; font-size: 11.5px; }
      .rr-doc[data-filled="1"] { border-color: #bfe0cd; background: #f6fbf8; }
      .rr-doc[data-filled="1"] > i { color: #1c7a4f; }

      .rr-warn { display: flex; gap: 10px; padding: 13px; border: 1px solid #f0d9b8; background: #fdf7ec; border-radius: 10px; }
      .rr-warn i { color: #8a5a00; font-size: 15px; flex: none; }
      .rr-warn p { margin: 0; font-size: 12.5px; line-height: 1.6; color: #5d4a26; }
      .rr-check { display: flex; gap: 9px; align-items: flex-start; font-size: 12.5px; line-height: 1.55; cursor: pointer; }
      .rr-check input { margin-top: 2px; flex: none; }
      /* "I do not have this yet" — only on the Official Receipt row. Ticking it
         lets the request be filed; it never lets the refund be paid. */
      .rr-later { display: flex; gap: 7px; align-items: flex-start; margin-top: 7px; font-size: 11.5px; line-height: 1.45; color: var(--muted); cursor: pointer; }
      .rr-later input { margin: 2px 0 0; flex: none; }
      .rr-doc[data-optional="1"][data-pending="1"] { border-color: #e2d9c4; background: #fdfaf3; }
      .rr-doc[data-optional="1"][data-pending="1"] > i { color: #8a5a00; }
      /* the post-submit notice is information, not a warning — calmer than the
         amber alert this used to be, because nothing is being taken away now. */
      .rr-warn-calm { border-color: #cddbe8; background: #f4f8fc; }
      .rr-warn-calm i { color: #2b4a7e; }
      .rr-warn-calm p { color: #2f4257; }
      .rr-error { margin: 0 0 16px; padding: 11px 13px; border-radius: 9px; background: #fcecec; color: #b23a3a; font-size: 12.5px; line-height: 1.5; display: none; }
      .rr-error[open] { display: block; }

      .rr-actions { display: flex; gap: 8px; justify-content: flex-end; padding: 15px 18px; border-top: 1px solid #f2efe9; }
      .rr-btn { height: 38px; padding: 0 16px; border-radius: 9px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font: inherit; font-size: 13px; font-weight: 640; text-decoration: none; display: inline-flex; align-items: center; cursor: pointer; }
      .rr-btn:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .rr-btn-primary { background: var(--black); border-color: var(--black); color: #fff; }
      .rr-btn-primary:hover { background: #332f2f; border-color: #332f2f; }

      /* summary aside */
      .rr-aside { position: sticky; top: calc(var(--venusep-header-height) + 20px); }
      .rr-kv { padding: 11px 0; border-bottom: 1px solid #f2efe9; }
      .rr-kv:last-of-type { border-bottom: 0; }
      .rr-kv span { display: block; font-size: 10.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #a3a09a; margin-bottom: 3px; }
      .rr-kv strong { font-size: 13.5px; font-weight: 640; }
      .rr-total { margin-top: 6px; padding: 13px 14px; background: #faf9f7; border: 1px solid #f0ece4; border-radius: 10px; }
      .rr-total span { display: block; font-size: 10.5px; font-weight: 600; letter-spacing: .05em; text-transform: uppercase; color: #a3a09a; margin-bottom: 3px; }
      .rr-total b { font-size: 24px; font-weight: 760; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
      .rr-total small { display: block; margin-top: 4px; font-size: 11.5px; color: var(--muted); line-height: 1.5; }

      /* refusal + confirmation */
      .rr-panel { max-width: 620px; border: 1px solid var(--border); border-radius: 12px; padding: 30px 26px; text-align: center; }
      .rr-panel i { font-size: 34px; }
      .rr-panel h2 { margin: 12px 0 8px; font-size: 17px; font-weight: 680; }
      .rr-panel p { margin: 0 auto 18px; max-width: 46ch; font-size: 13px; line-height: 1.65; color: var(--muted); }
      .rr-panel-bad i { color: #b23a3a; }
      .rr-panel-good i { color: #1c7a4f; }
      .rr-panel-info i { color: #2b4a7e; }

      @media (max-width: 900px) { .rr-grid { grid-template-columns: minmax(0, 1fr); } .rr-aside { position: static; } }
      @media (max-width: 560px) { .rr-doc { flex-wrap: wrap; } .rr-file { max-width: none; width: 100%; } }
    
      /* on the crimson page background (painted by includes/header.php): light text, cards that float */
      .rr-page > h1 { color: #fff; }
      .rr-page > p { color: #e9d0cd; }
      .rr-back { color: #f2d0cb; }
      .rr-back:hover { color: #fff; }
      .rr-card { box-shadow: 0 18px 44px rgba(10,4,5,.28), 0 2px 6px rgba(10,4,5,.18); border-color: rgba(255,255,255,.18); }
    
      /* primary button: crimson, not black */
      .rr-btn-primary { background: #a11626; border-color: #a11626; color: #ffffff; }
      .rr-btn-primary:hover { background: #7d0f1e; border-color: #7d0f1e; color: #ffffff; }
          /* type floor (readability): nothing on the page below 12px */
      .rr-label, .rr-hint, .rr-doc-txt small, .rr-file, .rr-later, .rr-kv span, .rr-total span, .rr-total small { font-size: 12px; }
    </style>
  </head>
  <body class="refund-request-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant.
           $active stays on Booking History: this page is a step inside that
           flow, not a menu item of its own. -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Booking History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rr-page">
              <a class="rr-back" href="booking-history.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back to Booking History</a>

              <?php if ($refusal !== null): ?>
                <!-- [4a] REFUSAL — bad or absent reference, or a booking that is
                     not eligible. Same check the history page used to decide
                     whether to offer the link, re-run here because ?booking= can
                     be typed by hand. -->
                <h1>Request a refund</h1>
                <p class="rr-lede">This booking cannot be refunded through the online form.</p>
                <div class="rr-panel rr-panel-bad">
                  <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                  <h2>Refund not available</h2>
                  <p><?php echo bh_e($refusal); ?></p>
                  <a class="rr-btn" href="booking-history.php">Back to Booking History</a>
                </div>

              <?php else: ?>
                <h1>Request a refund</h1>
                <p class="rr-lede">Tell us why you are requesting a refund and attach the required receipts. USeP staff review every request and make the final decision &mdash; submitting this form does not guarantee a refund.</p>

                <p class="rr-error" id="rr-error" role="alert"></p>
                <!-- Shown when staff sent the request back. Not a denial: the
                     claim is untouched, only the paperwork needs fixing. -->
                <div class="rr-warn" id="rr-fix" hidden style="margin-bottom:18px">
                  <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
                  <p><strong>Staff sent this back for correction.</strong> <span id="rr-fix-note"></span> Fix it and submit again &mdash; your booking is unaffected and your place in the queue is kept.</p>
                </div>

                <form id="rr-form" novalidate>
                  <div class="rr-grid">
                    <!-- [4b] THE FORM -->
                    <div class="rr-card">
                      <div class="rr-card-head">
                        <h2>Your refund request</h2>
                        <p>Booking <?php echo bh_e($booking['bookingId']); ?> &middot; <?php echo bh_e($booking['eventName']); ?></p>
                      </div>
                      <div class="rr-card-body">
                        <div class="rr-cols">
                          <div class="rr-col">

                          <div class="rr-section">
                            <label class="rr-label rr-req" for="rr-reason">Reason for the refund</label>
                            <select class="rr-control" id="rr-reason" name="reason">
                              <option value="">Select a reason&hellip;</option>
                              <?php foreach ($refundReasons as $reason): ?>
                                <option><?php echo bh_e($reason); ?></option>
                              <?php endforeach; ?>
                            </select>
                          </div>
  
                          <div class="rr-section">
                            <label class="rr-label rr-req" for="rr-details">Tell us what happened</label>
                            <textarea class="rr-control" id="rr-details" name="details" placeholder="Explain briefly why you are requesting this refund. Staff read this before deciding."></textarea>
                            <p class="rr-hint" id="rr-count">At least 20 characters.</p>
                          </div>

                          <?php if ($booking['method'] === 'GCash'): ?>
                            <!-- WHERE THE MONEY GOES. Asked HERE, at refund time, on
                                 purpose: staff cannot read a full sender number out of
                                 GCash (incoming senders are masked), so without this
                                 the system genuinely does not know where to pay. The
                                 fraud risk of letting a customer nominate a destination
                                 is handled where it actually lives — every refund is
                                 reviewed by a staff member who holds this customer's
                                 verified ID, and the admin panel flags it loudly when
                                 this differs from the number on file.
                                 Cash bookings never see this: a cash refund is handed
                                 over at the counter (same channel back). -->
                            <div class="rr-section">
                              <label class="rr-label rr-req" for="rr-gcash">GCash number to refund to</label>
                              <input class="rr-control" id="rr-gcash" type="tel" inputmode="numeric"
                                     autocomplete="tel" value="<?php echo bh_e($customerContact['phone']); ?>"
                                     placeholder="09XX XXX XXXX" />
                              <p class="rr-hint">This must be the GCash account you <strong>paid from</strong>. We have filled in your registered number &mdash; change it only if you paid from a different account.</p>
                            </div>
                          <?php endif; ?>
                          </div>
                          <div class="rr-col">
                          <div class="rr-section">
                            <span class="rr-label">Documents</span>
                            <div class="rr-docs">
                              <?php foreach ($requiredDocs as $doc): ?>
                                <div class="rr-doc" data-filled="0" data-pending="0" data-optional="<?php echo $doc['optional'] ? '1' : '0'; ?>" data-label="<?php echo bh_e($doc['label']); ?>">
                                  <i class="bi bi-paperclip" aria-hidden="true"></i>
                                  <div class="rr-doc-txt">
                                    <b><?php echo bh_e($doc['label']); ?><?php echo $doc['optional'] ? '' : ' *'; ?></b>
                                    <small><?php echo bh_e($doc['hint']); ?></small>
                                    <?php if ($doc['optional']): ?>
                                      <label class="rr-later">
                                        <input type="checkbox" class="rr-nohave" id="rr-nohave-<?php echo bh_e($doc['key']); ?>" />
                                        <span>I do not have this yet &mdash; I understand my refund cannot be paid until I provide it.</span>
                                      </label>
                                    <?php endif; ?>
                                  </div>
                                  <input type="file" class="rr-file" accept="image/*,application/pdf"
                                         data-key="<?php echo bh_e($doc['key']); ?>"
                                         aria-label="<?php echo bh_e($doc['label']); ?>" />
                                </div>
                              <?php endforeach; ?>
                            </div>
                            <p class="rr-hint">Items marked * are needed before your request can be filed. The Official Receipt is needed before the refund can be <strong>paid</strong> &mdash; send it later if you do not have it yet.</p>
                          </div>
  
                          <div class="rr-section">
                            <div class="rr-warn rr-warn-calm">
                              <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                              <p><strong>Your booking is not cancelled by this request.</strong> It stays yours the whole time staff are reviewing, and you can <strong>withdraw the request at any point</strong> before they decide. If the refund is <strong>denied</strong>, your booking is untouched. If it is <strong>approved</strong>, the booking closes and the date is released only once your refund has actually been paid.</p>
                            </div>
                          </div>
  
                          <div class="rr-section">
                            <label class="rr-check">
                              <input type="checkbox" id="rr-confirm" />
                              <span>I understand that USeP staff make the final decision, that a denied request is final and I would need to book again, and that my refund cannot be paid until the Official Receipt has been provided.</span>
                            </label>
                          </div>

                          </div>
                        </div>
                      </div>
                      <div class="rr-actions">
                        <a class="rr-btn" href="booking-history.php">Never mind</a>
                        <button type="submit" class="rr-btn rr-btn-primary">Submit refund request</button>
                      </div>
                    </div>

                    <!-- [4c] BOOKING SUMMARY — read-only. The amount is shown, never
                         typed: a customer-entered figure would be a partial refund
                         request, which is a decision nobody has made yet. -->
                    <aside class="rr-card rr-aside">
                      <div class="rr-card-head"><h2>Booking being refunded</h2></div>
                      <div class="rr-card-body">
                        <div class="rr-kv"><span>Reference</span><strong><?php echo bh_e($booking['bookingId']); ?></strong></div>
                        <div class="rr-kv"><span>Venue</span><strong><?php echo bh_e($booking['venue']); ?></strong></div>
                        <div class="rr-kv"><span>Event</span><strong><?php echo bh_e($booking['eventName']); ?></strong></div>
                        <div class="rr-kv"><span>Event date</span><strong><?php echo bh_e($booking['eventDate']); ?></strong></div>
                        <div class="rr-kv"><span>Booked on</span><strong><?php echo bh_e($booking['bookingDate']); ?></strong></div>
                        <div class="rr-kv"><span>Paid by</span><strong><?php echo bh_e($booking['method']); ?></strong></div>
                        <div class="rr-total">
                          <span>Amount to be refunded</span>
                          <b><?php echo bh_e($booking['amount']); ?></b>
                          <small>The full amount paid. Staff confirm the final figure when they review your request.</small>
                        </div>
                      </div>
                    </aside>
                  </div>
                </form>

                <!-- [4d] CONFIRMATION — swapped in on submit. -->
                <div class="rr-panel rr-panel-good" id="rr-done" hidden>
                  <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                  <h2>Refund request submitted</h2>
                  <p>Your request for booking <?php echo bh_e($booking['bookingId']); ?> is now with USeP staff. <strong>Your booking has not been cancelled</strong> &mdash; it stays yours while the request is reviewed, and you can withdraw it from Booking History at any point before a decision is made.</p>
                  <p id="rr-done-or" hidden>You still owe the <strong>Official Receipt</strong>. Staff cannot pay the refund until they have it, so send it as soon as you can.</p>
                  <a class="rr-btn rr-btn-primary" href="booking-history.php">Back to Booking History</a>
                </div>

                <!-- [4e] ALREADY OPEN — a customer who has filed can still reach
                     this URL by typing it or using the back button. Filing twice
                     would create a duplicate request. The schema now refuses a
                     second OPEN request per booking (refunds.open_booking_id,
                     2026-09-16), but this page still has to say so politely.
                     Withdrawing from Booking History clears this. -->
                <div class="rr-panel rr-panel-info" id="rr-already" hidden>
                  <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                  <h2>You already have a refund request open</h2>
                  <p>A refund request for booking <?php echo bh_e($booking['bookingId']); ?> is already with USeP staff, so you cannot file a second one. If you want to change or cancel it, withdraw the existing request from your Booking History.</p>
                  <a class="rr-btn rr-btn-primary" href="booking-history.php">Go to Booking History</a>
                </div>
              <?php endif; ?>

                <!-- [4f] CLOSED — staff already decided. A denial is final and a
                     completed refund is done, so neither can be filed against
                     again. Shown instead of the form. -->
                <div class="rr-panel rr-panel-info" id="rr-closed" hidden>
                  <i class="bi bi-archive-fill" aria-hidden="true"></i>
                  <h2 id="rr-closed-title">This refund request is closed</h2>
                  <p id="rr-closed-body"></p>
                  <!-- Proof of payout, shown only when the refund actually completed.
                       The receipt IMAGE is not carried in browser storage — that is
                       the database's job; this tile stands in for it, the same way
                       the ID image is a placeholder on the admin side. -->
                  <div id="rr-proof" hidden>
                    <div style="text-align:left;max-width:420px;margin:0 auto 16px;padding:13px 14px;background:#faf9f7;border:1px solid #f0ece4;border-radius:10px">
                      <div class="rr-kv"><span>GCash reference</span><strong id="rr-proof-ref">&mdash;</strong></div>
                      <div class="rr-kv"><span>Amount refunded</span><strong id="rr-proof-amt">&mdash;</strong></div>
                      <div class="rr-kv"><span>Sent to</span><strong id="rr-proof-to">&mdash;</strong></div>
                    </div>
                    <div id="rr-proof-tile" style="max-width:420px;margin:0 auto 18px;padding:30px 14px;border:1px dashed #d7d2c8;border-radius:10px;background:#fbfaf8;color:#a3a09a;font-size:12px">GCash refund receipt &mdash; image</div>
                  </div>
                  <a class="rr-btn rr-btn-primary" href="booking-history.php">Back to Booking History</a>
                </div>
            </div>
          </div>
        </div>
      </main>
    </div>

    <!-- Shared refund state — the ONE source, also included by booking-history
         and both admin pages. See includes/refund-store.php. -->
    <?php include __DIR__ . '/../includes/refund-store.php'; ?>

    <!-- [5] SCRIPT [SIM] — client-side validation and the confirmation swap.
         No POST, no upload is stored. The reference is pushed into
         sessionStorage so booking-history.php can repaint the row; at database
         time that marker goes away and the booking itself carries the status. -->
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('rr-form');
        if (!form) return;                       // refusal page — nothing to wire
        const errorBox = document.getElementById('rr-error');
        const reason = document.getElementById('rr-reason');
        const details = document.getElementById('rr-details');
        const counter = document.getElementById('rr-count');
        const confirmBox = document.getElementById('rr-confirm');
        const done = document.getElementById('rr-done');
        const MIN_DETAIL = 20;
        const gcashField = document.getElementById('rr-gcash');   /* GCash bookings only */

        /* Same normalisation the server uses (cb_normalise_mobile). Both sides
           compare NUMBERS, never strings, so "0917 555 0123" and "09175550123"
           are never reported as different. */
        function normMobile(raw) {
          let d = String(raw || "").replace(/\D+/g, "");
          if (d.length === 12 && d.slice(0, 2) === '63') d = '0' + d.slice(2);
          if (d.length === 10 && d.charAt(0) === '9')    d = '0' + d;
          return (d.length === 11 && d.slice(0, 2) === '09') ? d : null;
        }

        /* The reference is read back from the query string the server already
           validated, so the form never has to carry it a second time. */
        const bookingRef = new URLSearchParams(window.location.search).get('booking');

        /* What has already happened to this booking's refund, if anything.
           open     -> a second request must not be filed; offer withdrawal instead
           fix      -> staff sent it back; show what to correct and let them resubmit
           denied   -> final, nothing more to file
           refunded -> done, nothing more to file */
        const existing = window.RefundStore ? RefundStore.get(bookingRef) : null;
        if (existing && existing.status === 'open') {
          form.hidden = true;
          const lede = document.querySelector('.rr-lede'); if (lede) lede.hidden = true;
          const already = document.getElementById('rr-already');
          if (already) already.hidden = false;
          return;
        }
        if (existing && (existing.status === 'denied' || existing.status === 'refunded')) {
          form.hidden = true;
          const lede = document.querySelector('.rr-lede'); if (lede) lede.hidden = true;
          const closed = document.getElementById('rr-closed');
          if (closed) {
            document.getElementById('rr-closed-title').textContent =
              existing.status === 'denied' ? 'This refund request was denied' : 'This booking has already been refunded';
            document.getElementById('rr-closed-body').textContent =
              existing.status === 'denied'
                ? 'Staff denied the request' + (existing.staffNote ? ': ' + existing.staffNote : '.') + ' A denial is final — to use the venue you would need to make a new booking. Your original booking was not affected by the request.'
                : 'The refund was paid out' + (existing.decidedAt ? ' on ' + existing.decidedAt : "") + ', so the booking is now closed and its date has been released. Your proof of payment is below — keep the reference number.';
            /* proof of payout, refunded requests only */
            const proof = existing.status === 'refunded' ? (existing.proof || null) : null;
            if (proof) {
              const box = document.getElementById('rr-proof');
              document.getElementById('rr-proof-ref').textContent = proof.reference || 'not recorded';
              document.getElementById('rr-proof-amt').textContent = proof.amount || '—';
              document.getElementById('rr-proof-to').textContent  = proof.to || '—';
              if (box) box.hidden = false;
            }
            closed.hidden = false;
          }
          return;
        }
        if (existing && existing.status === 'fix') {
          const notice = document.getElementById('rr-fix');
          if (notice) {
            document.getElementById('rr-fix-note').textContent = existing.staffNote || "";
            notice.hidden = false;
          }
          /* pre-fill what they told us last time so they only fix the document */
          if (existing.reason) { reason.value = existing.reason; }
          if (existing.details) { details.value = existing.details; }
          /* keep the destination they gave last time — they are fixing a document,
             not re-choosing where the money goes */
          if (existing.refundTo && gcashField) { gcashField.value = existing.refundTo; }
        }

        /* A document row is satisfied EITHER by attaching the file, or — for the
           Official Receipt only — by ticking "I do not have this yet". Required
           rows need the file; the optional one needs a decision either way, so
           nobody submits having simply skipped past it. */
        const docRows = Array.from(document.querySelectorAll('.rr-doc'));
        const markRow = function (row) {
          const icon = row.querySelector('i');
          if (row.dataset.filled === '1') icon.className = 'bi bi-check-circle-fill';
          else if (row.dataset.pending === '1') icon.className = 'bi bi-clock-history';
          else icon.className = 'bi bi-paperclip';
        };
        docRows.forEach(function (row) {
          const file = row.querySelector('input[type="file"]');
          const nohave = row.querySelector('.rr-nohave');
          file.addEventListener('change', function () {
            row.dataset.filled = file.files && file.files.length ? '1' : '0';
            if (row.dataset.filled === '1' && nohave) { nohave.checked = false; row.dataset.pending = '0'; }
            markRow(row);
          });
          if (nohave) {
            nohave.addEventListener('change', function () {
              row.dataset.pending = nohave.checked ? '1' : '0';
              if (nohave.checked) { file.value = null; row.dataset.filled = '0'; }
              markRow(row);
            });
          }
        });

        details.addEventListener('input', function () {
          const left = MIN_DETAIL - details.value.trim().length;
          counter.textContent = left > 0 ? left + ' more characters needed.' : 'Looks good.';
        });

        const fail = function (message) {
          errorBox.textContent = message;
          errorBox.setAttribute('open', 'open');
          window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        form.addEventListener('submit', function (event) {
          event.preventDefault();
          errorBox.removeAttribute('open');
          if (!reason.value) return fail('Choose a reason for the refund.');
          if (details.value.trim().length < MIN_DETAIL) return fail('Please describe what happened in at least ' + MIN_DETAIL + ' characters — staff read this before deciding.');
          const missingRequired = docRows.filter(function (row) { return row.dataset.optional === '0' && row.dataset.filled !== '1'; });
          if (missingRequired.length) return fail('Attach the documents needed to file this request. Still missing: ' + missingRequired.map(function (row) { return row.dataset.label; }).join(', ') + '.');
          const undecided = docRows.filter(function (row) { return row.dataset.optional === '1' && row.dataset.filled !== '1' && row.dataset.pending !== '1'; });
          if (undecided.length) return fail('For the ' + undecided[0].dataset.label + ', either attach it or tick "I do not have this yet".');
          if (!confirmBox.checked) return fail('Tick the box to confirm you understand how your request will be handled.');
          let refundTo = null;
          if (gcashField) {
            refundTo = normMobile(gcashField.value);
            if (!refundTo) { gcashField.focus(); return fail('Enter a valid GCash number in the form 09XX XXX XXXX — this is where your refund will be sent.'); }
          }

          /* File it. The booking is NOT cancelled — this only records that a
             request exists, which is what the history page and the admin queue
             both read. See includes/refund-store.php. */
          const orStillOwed = docRows.some(function (row) {
            return row.dataset.optional === '1' && row.dataset.pending === '1';
          });
          if (window.RefundStore && bookingRef) {
            RefundStore.open({
              ref: bookingRef,
              refundTo: refundTo,
              reason: reason.value,
              details: details.value.trim(),
              orPending: orStillOwed
            });
          }
          if (orStillOwed) { const note = document.getElementById('rr-done-or'); if (note) note.hidden = false; }
          form.hidden = true;
          document.querySelector('.rr-lede').hidden = true;
          done.hidden = false;
          window.scrollTo({ top: 0, behavior: 'smooth' });
        });
      });
    </script>
  </body>
</html>
