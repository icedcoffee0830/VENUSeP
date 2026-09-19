<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* THE REQUEST DETAIL — built from the same `bookings` rows the queue and the
   customer's own history read (includes/bookings.php). This was a hand-written
   map of eight invented requests keyed 'BRQ-2432'; a refund the customer filed
   could only reach it through browser storage, and only in the same browser.
   Every request is now simply a row, keyed by its real reference. */
require_once __DIR__ . '/../includes/bookings.php';

$brqAll = bookings_all();

/* Which ID document was attached, and whether staff have accepted it. The
   discount depends on this: a USeP ID that staff VERIFIED earns it, a claim
   alone never does (DB-DECISIONS #3). */
function brqIdLabel(array $b) {
    if ($b['isWalkIn'])  return 'Presented at the counter';
    if (!$b['isUsep'])   return 'Government-issued ID';
    return $b['usepVerified'] ? 'USeP ID (verified)' : 'USeP ID (submitted)';
}
function brqIdStatus(array $b) {
    if ($b['reservationCode'] === 'pending')  return 'pending';
    if ($b['reservationCode'] === 'rejected') return 'rejected';
    return 'approved';
}

$brqData = [];
foreach ($brqAll as $b) {
    $isHostel = $b['type'] === 'hostel';

    /* The per-day schedule. Days live in venue_booking_slots, so a booking that
       BOOKED AROUND a closed day simply has a gap here — which is the honest
       thing to show staff, rather than a range implying it holds the blocked
       day too. */
    $sched = [];
    foreach (booking_slots($b['id']) as $s) {
        $sched[] = [
            'd' => date('D, M j', strtotime($s['slot_date'])),
            't' => date('g:i A', strtotime($s['start_time'])) . ' – ' . date('g:i A', strtotime($s['end_time'])),
        ];
    }

    $occ = [];
    if ($isHostel) {
        foreach (booking_occupants($b['id']) as $o) {
            $occ[] = ['n' => $o['full_name'], 'g' => $o['gender'] === 'female' ? 'F' : ($o['gender'] === 'male' ? 'M' : 'O')];
        }
    }

    /* The refund claim, when there is one. It used to reach this page only
       through browser storage, so staff on any other machine saw nothing. */
    $refund = null;
    $rfStmt = venusep_db()->prepare(
        'SELECT refund_status, reason_category, reason, amount_requested, refund_to_number,
                official_receipt_pending, correction_attempts, notes, requested_at, resubmitted_at
           FROM refunds WHERE booking_id = :b ORDER BY id DESC LIMIT 1'
    );
    $rfStmt->execute([':b' => $b['id']]);
    if ($rf = $rfStmt->fetch()) {
        $stageMap = [
            'requested'               => 'Under verification',
            'under_review'            => 'Under verification',
            'returned_for_correction' => 'Returned for correction',
            'approved'                => $rf['official_receipt_pending'] ? 'Awaiting Official Receipt' : 'Approved · payout pending',
            'rejected'                => 'Denied',
            'completed'               => 'Refunded',
            'withdrawn'               => 'Withdrawn by the customer',
        ];
        $refund = [
            'stage'     => $stageMap[$rf['refund_status']] ?? $rf['refund_status'],
            'orPending' => (bool) $rf['official_receipt_pending'],
            'reason'    => str_replace('_', ' ', $rf['reason_category']),
            'details'   => (string) $rf['reason'],
            'refundTo'  => (string) $rf['refund_to_number'],
            'filed'     => date('M j, Y', strtotime($rf['requested_at'])),
            'docs'      => $rf['refund_status'] === 'returned_for_correction'
                            ? 'Returned to the customer: ' . ($rf['notes'] ?: 'correction requested')
                            : 'Transaction receipt + proof of payment submitted · '
                              . ($rf['official_receipt_pending'] ? 'Official Receipt NOT yet provided' : 'Official Receipt provided'),
        ];
    }

    $rec = booking_receipt($b['id']);
    $receipt = $rec ? [
        'ref'    => $rec['reference_number'],
        'amount' => '₱' . number_format(((int) $rec['amount_centavos']) / 100, 2),
        'date'   => $rec['receipt_datetime'] ? date('M j, Y · g:i A', strtotime($rec['receipt_datetime'])) : '—',
        'to'     => (string) $rec['receiver_number'],
        'conf'   => $rec['ocr_confidence'] !== null ? round((float) $rec['ocr_confidence']) . '%' : '—',
        'flags'  => $rec['flags'],
    ] : null;

    $row = [
        'id'        => $b['bookingId'],
        'kind'      => $isHostel ? 'hostel' : 'venue',
        'name'      => $b['customerName'],
        'type'      => $b['isWalkIn'] ? 'Walk-in' : ($b['isUsep'] ? 'USeP affiliated' : 'Non-USeP'),
        'email'     => $b['customerEmail'] !== '' ? $b['customerEmail'] : '—',
        'phone'     => $b['customerPhone'],
        'event'     => $b['eventName'],
        'room'      => $b['roomName'],
        'venue'     => $b['venueName'],
        'capacity'  => $b['capacity'],
        'attendees' => $b['attendees'],
        'total'     => $b['amount'],
        'payBy'     => $b['payment']['payByLabel'],
        'method'    => strtolower($b['method']),
        'submitted' => date('M j, Y · g:i A', strtotime($b['bookingDateIso'])),
        'res'       => $b['reservationCode'],
        'pay'       => $b['paymentCode'],
        'idStatus'  => brqIdStatus($b),
        'idLabel'   => brqIdLabel($b),
        'discountPercent' => $b['discountPercent'],
        'receipt'   => $receipt,
        /* Ids only — the bytes come from document-view.php, which runs its own
           permission check rather than trusting this list to have been built
           for the right person. */
        'docs'      => booking_document_ids($b['id']),
        'receiptId' => $rec ? (int) $rec['id'] : null,
        'refund'    => $refund,
        'refundTo'  => $refund ? $refund['refundTo'] : null,
        'tl'        => booking_history_events($b),
    ];

    if ($isHostel) {
        $row += [
            'crType'    => $b['crType'] === 'private' ? 'Private CR' : 'Communal CR',
            'checkIn'   => date('M j, Y', strtotime($b['eventDateIso'])),
            'checkOut'  => date('M j, Y', strtotime($b['endDateIso'])),
            'nights'    => $b['nights'],
            'rate'      => '₱' . number_format($b['roomPrice'] / max(1, $b['beds'] * max(1, $b['nights']))),
            'occupants' => $occ,
            'pos'       => $b['pos'] !== '' ? $b['pos'] : null,
            'or'        => $b['officialReceipt'] !== '' ? $b['officialReceipt'] : null,
            'checkedIn' => $b['checkedIn'],
        ];
    } else {
        $row += [
            'dates'   => $b['eventDateIso'] === $b['endDateIso']
                            ? date('M j, Y', strtotime($b['eventDateIso']))
                            : date('M j', strtotime($b['eventDateIso'])) . ' – ' . date('M j, Y', strtotime($b['endDateIso'])),
            'startIso'=> $b['eventDateIso'],
            'endIso'  => $b['endDateIso'],
            'feeDay'  => '₱' . number_format($b['days'] > 0 ? $b['roomPrice'] / $b['days'] : $b['roomPrice']),
            'days'    => $b['days'],
            'sched'   => $sched,
        ];
    }
    $brqData[$b['bookingId']] = $row;
}
?>
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
      .br-ovr select { background: #fff; border: 1px solid var(--vm-border); border-radius: 8px; font-family: inherit; font-size: 0.82rem; padding: 0.5rem 0.62rem; width: 100%; }
      .br-ovr select:focus { border-color: #b9b5ad; outline: none; }
      /* stacked label-over-control, so a staff decision reads top to bottom */
      .br-fld { margin-bottom: 0.65rem; }
      .br-fld label { color: var(--vm-label); display: block; font-size: 0.64rem; font-weight: 600; letter-spacing: 0.05em; margin-bottom: 0.25rem; text-transform: uppercase; }
      .br-preview { background: #faf9f7; border: 1px solid var(--vm-hairline); border-radius: 8px; color: var(--vm-muted); font-size: 0.76rem; line-height: 1.5; margin-bottom: 0.65rem; padding: 0.5rem 0.62rem; }
      .br-preview b { color: var(--vm-text); font-weight: 600; }
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
         · DATA              every real booking, keyed by its reference —
                             the real page will load ONE request from
                             the database using the ?id= in the URL
         · header + actions  contextual buttons per current status
         · cards             Customer & ID / Reservation / Payment
                             (receipt evidence, flags, override box)
         · timeline          the audit trail of status changes
         · staff actions     post to admin/booking-action.php (refresh
                             resets) — the real app will POST to the
                             server and save to the database
         ============================================================ -->
    <!-- Shared refund state — the ONE source, also included by both customer
         pages. Must load BEFORE this page's script, which reads and writes it. -->
    <?php include __DIR__ . '/../includes/pricing.php'; ?>
    <?php /* refund-store.php is gone: refunds are `refunds` rows now. */ ?>
    <!-- Tesseract + the shared GCash engine. The SAME checker the customer uses
         to prove they paid in; here it proves staff paid out. It needs no changes
         for that — it asks the page whose account the money must land in, and
         this page answers "the customer's" (see gcAccount below). -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <?php include __DIR__ . '/../includes/gcash-checker.php'; ?>
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
      const RES = <?php echo json_encode(status_badge_maps()['res'], JSON_UNESCAPED_UNICODE); ?>;
      const PAY = <?php echo json_encode(status_badge_maps()['pay'], JSON_UNESCAPED_UNICODE); ?>;

      /* Last line of defence: a code with no badge must still open the page. */
      function resBadge(code) { return RES[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }
      function payBadge(code) { return PAY[code] || { t: String(code || 'Unknown status'), c: 'b-gray' }; }

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
      <?php echo payment_policy_js(); ?>
      /* EVERY request, from the database, keyed by its real reference. */
      const DATA = <?php echo json_encode($brqData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
      const CSRF = <?php echo json_encode(csrf_token()); ?>;

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
        const body = new URLSearchParams(Object.assign({ csrf: CSRF, booking: cur.id, action: action }, extra || {}));
        try {
          const res = await fetch('booking-action.php', { method: 'POST', body: body, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) { cur.actionError = out.message || 'Nothing was changed.'; acting = false; render(); return false; }
          cur.actionError = null;
          if (out.booking) {
            cur.res = out.booking.res;
            cur.pay = out.booking.pay;
            cur.total = out.booking.total;
            cur.discountPct = out.booking.discount;
            cur.payBy = out.booking.payBy;
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
        cur.tl.push({ w: now(), x: 'Refund completed', m: 'Sent to ' + to + (refNo ? ' (GCash ref ' + refNo + ')' : "")
          + (payoutVerified() ? ', receipt verified.' : ', recorded without a verified receipt: ' + note)
          + ' The booking is now closed and its date released.' });
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
        const docs = ['System Transaction Receipt'];
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

      /* which buttons make sense right now (reservation-level, in the header) */
      function hdrActions() {
        /* A finished booking is a RECORD, not a task. Most bookings in the
           system are `completed`, and offering them the live queue's buttons
           would invite staff to act on something that is already over. An open
           refund is the exception: the booking is cancelled, but the claim it
           was cancelled for can still be decided. */
        if (CLOSED_RES.includes(cur.res) && !OPEN_REFUND.includes(cur.pay)) {
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
        if (cur.pay === 'overdue') return `<button class="br-btn br-btn-primary" onclick="releaseSlot()">Release the slot</button><button class="br-btn" onclick="extendDeadline()">Extend 48 h</button>`;
        if (OPEN_REFUND.includes(cur.pay)) return `<button class="br-btn br-btn-primary" onclick="openPayout()">Mark refunded</button><button class="br-btn" onclick="openAction('fix')">Return for correction</button><button class="br-btn" onclick="openAction('deny')">Deny refund</button>`;
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
            ${cur.receiptId
              /* The real screenshot, so staff can read the reference off it
                 rather than trusting what the scanner claims it says. */
              ? `<a href="../document-view.php?receipt=${cur.receiptId}" target="_blank" rel="noopener" title="Open the full-size receipt in a new tab">
                   <img src="../document-view.php?receipt=${cur.receiptId}" alt="GCash receipt"
                        style="display:block;width:120px;height:150px;object-fit:cover;background:#f4f4f4;border:1px solid var(--vm-border);border-radius:8px">
                 </a>`
              : `<div class="br-receipt">receipt<br>screenshot</div>`}
            <div class="br-kvgrid" style="flex:1;align-content:start">
              ${kv('Ref no.', esc(r.ref))}${kv('Amount read', esc(r.amount))}${kv('Amount required', esc(cur.total) + amountVerdict(r.amount, cur.total))}
              ${kv('Receipt date', esc(r.date))}${kv('Sent to', esc(r.to))}
            </div>
          </div>
          <div style="color:var(--vm-muted);font-size:0.74rem;margin-bottom:0.5rem">OCR confidence ${r.conf} &middot; the reference and the file were not seen on any earlier booking</div>`;
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
            `<div class="br-kvgrid">${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}${PAY_POLICY.prepay
              ? kv('Pay-by once approved', esc(cur.payBy) + ' <span class="soft">(1 day before the event)</span>')
              : kv('Payment timing', 'Post-pay <span class="soft">(opens after the event, due ' + PAY_POLICY.graceDays + ' days later)</span>')}</div>`;
          case 'await_event': return banner('bn-gray', 'Payment opens after the event', 'Post-pay booking: nothing is owed until the event is over. The customer cannot pay early. The system opens payment the day after the last booked day.') +
            `<div class="br-kvgrid">${kv('Payment opens', 'the day after the event')}${kv('Due by', esc(cur.payBy))}${kv('Amount due', esc(cur.total))}${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}</div>`;
          case 'await_gcash': return banner('bn-amber', 'Awaiting GCash payment', 'The customer was notified. The receipt will be auto-checked the moment it is uploaded.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${kv('Amount due', esc(cur.total))}</div>`;
          case 'await_cash': return banner('bn-amber', 'Awaiting cash payment at the cashier', 'Confirm here once the cashier records the payment.') +
            `<div class="br-kvgrid">${kv('Pay by', esc(cur.payBy))}${kv('Amount due', esc(cur.total))}${kv('Where', 'USeP Cashier — Venue Reservations Window', true)}</div>
             <div style="margin-top:0.8rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Record cash payment</button></div>`;
          /* NEITHER of the next two states exists in the database: an uploaded
             receipt always lands in `under_review` (customer/payment-submit.php,
             DB-DECISIONS #6) and rejecting one reopens `await_gcash`/`await_cash`
             so the customer can resubmit. They are kept because they are the only
             place the override-and-confirm box lives; reaching it again needs a
             decision about how a rejected receipt should be recorded, which is a
             separate question from this page rendering. */
          case 'auto_pass': return banner('bn-green', 'All automatic checks passed', 'Exact amount · correct receiver · fresh reference · genuine receipt markers.') + receiptBlock() +
            `<div style="display:flex;gap:0.5rem;margin-top:0.6rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="rejectPay()">Reject after GCash check</button></div>
             <div style="color:var(--vm-muted);font-size:0.74rem;margin-top:0.55rem">Find the reference number in the business GCash Transaction History before confirming.</div>`;
          /* Manual review is now the COMMON path, not a rarity — so it has to say
             out loud that the customer is waiting on US. The pay-by deadline must
             not quietly expire while a receipt sits here: the customer did their
             part, and letting the slot lapse would punish them for our queue. */
          case 'under_review': return banner('bn-amber', 'Needs manual review — the customer is waiting on you',
              'The auto-check could not verify everything. Compare the fields against the screenshot; the amount required is shown beside the amount read.') + receiptBlock() +
            flags(cur.receipt.flags, '#c99a3c') +
            `<div class="br-banner bn-gray" style="margin-top:0.7rem"><div>Slot is held while this is with you
              <span class="s">Pay-by deadline: <strong>${esc(cur.payBy)}</strong>. The customer has already paid and cannot act further — clear this before the deadline, or extend it. Do not let it lapse to overdue.</span></div></div>` +
            `<div style="display:flex;gap:0.5rem;margin-top:0.7rem"><button class="br-btn br-btn-primary" onclick="confirmPay()">Confirm payment</button><button class="br-btn" onclick="rejectPay()">Reject receipt</button></div>`;
          /* WHO rejected it matters, and matters more now the scanner rejects
             less: after DB-DECISIONS #6 most rejections are a staff decision, and
             labelling those "auto-rejected" told staff the machine did something
             they did themselves — while the reason line underneath said otherwise. */
          case 'rejected': return banner('bn-red',
              cur.rejectedByStaff ? 'Receipt rejected by staff' : 'Receipt auto-rejected',
              (cur.rejectedByStaff ? 'Checked against GCash and refused. ' : 'The automatic check refused it. ')
              + 'Customer was told to fix and resubmit. Slot held for the 48-hour window (until ' + esc(cur.resubmitBy || cur.payBy) + '), bounded by the pay-by deadline.') +
            flags(cur.rejects || [], '#b23a3a') +
            `<div class="br-ovr">
              <div style="font-size:0.8rem;font-weight:600;margin-bottom:0.4rem">Override &amp; confirm payment (staff)</div>
              <div style="color:var(--vm-muted);font-size:0.74rem;line-height:1.5;margin-bottom:0.6rem">Use only after finding the payment in the business GCash Transaction History. <strong>Duplicate-receipt rejections can only be overridden by an admin.</strong> The auto-check verdict stays on record either way.</div>
              <input id="ovrRef" placeholder="Verified reference number (from GCash)" style="margin-bottom:0.5rem" value="${esc(cur.ovrRef || '')}">
              <textarea id="ovrNote" rows="2" placeholder="Required note — why is this override correct?">${esc(cur.ovrNote || '')}</textarea>
              ${cur.ovrErr ? `<div style="color:#b23a3a;font-size:0.74rem;margin-top:0.35rem">${esc(cur.ovrErr)}</div>` : ''}
              <div style="display:flex;gap:0.5rem;margin-top:0.6rem">
                <button class="br-btn br-btn-primary" onclick="overrideConfirm()">Override &amp; confirm</button>
                ${cur.rejectedByStaff ? `<button class="br-btn" onclick="undoRejection()">&larr; Undo rejection</button>` : ""}
              </div>
            </div>`;
          case 'confirmed': return banner('bn-green', 'Payment confirmed', 'Confirmed by ' + esc(cur.confirmedBy || 'staff') + '. The reference number is permanently locked.') + (cur.receipt ? receiptBlock() : '');
          case 'paid_cash': return banner('bn-green', 'Paid at the cashier', esc(cur.confirmedBy || 'Recorded by staff') + ' · official receipt issued at the counter.');
          case 'overdue': return banner('bn-red', 'Payment overdue', 'The pay-by deadline (' + esc(cur.payBy) + ') passed with no valid payment. The slot can be released or the deadline extended.') +
            `<div class="br-kvgrid">${kv('Amount due', esc(cur.total))}${kv('Method chosen', cur.method === 'cash' ? 'Cash · walk-in' : 'GCash')}</div>`;
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
          case 'expired': return banner('bn-gray', 'Payment window expired — slot released',
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
              ? `<a href="../document-view.php?doc=${cur.docs.customer_id}" target="_blank" rel="noopener" title="Open the full-size ID in a new tab">
                   <img src="../document-view.php?doc=${cur.docs.customer_id}" alt="${esc(cur.idLabel)}"
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
                Decided: <strong>${cur.discountPct > 0 ? DISCOUNT_PERCENT + '% applied' : 'no discount — full price'}</strong></div>` : ''}
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
                <span class="br-badge ${resBadge(cur.res).c}">${esc(resBadge(cur.res).t)}</span>
                <span class="br-badge ${payBadge(cur.pay).c}">${esc(payBadge(cur.pay).t)}</span>
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
                    ${kv('Fee', esc(cur.total) + ' <span class="soft">(' + esc(cur.feeDay) + '/day × ' + cur.days + ')</span>')}${cur.pay === 'await_event' || (!PAY_POLICY.prepay && ['pending','locked'].includes(cur.pay))
                      ? kv('Pay-by rule', esc(cur.payBy) + ' <span class="soft">(post-pay · ' + PAY_POLICY.graceDays + ' days after the event)</span>')
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
          cur.tl.push({ w: now(), x: 'Affiliation ' + (withDiscount ? 'confirmed' : 'not confirmed'),
            m: withDiscount
              ? cur.discountPct + '% USeP discount applied from ' + cur.idLabel + '. Price locked at ' + cur.total + '.'
              : 'ID accepted but it does not prove USeP affiliation. Full price ' + cur.total + '.' });
          cur.tl.push({ w: now(), x: 'ID + reservation approved',
            m: cur.pay === 'await_pos'  ? 'Payment stays locked until the POS comes back from CEDU.'
             : cur.pay === 'await_event' ? 'Post-pay booking: payment opens after the last day and is due by ' + cur.payBy + '.'
             : 'Payment unlocked. Pay-by deadline ' + cur.payBy + '.' });
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
          cur.tl.push({ w: now(), x: 'POS received from CEDU', m: val + ' recorded.' + (cur.pay === 'await_event' ? ' This booking post-pays, so payment still opens after check-out.' : ' Payment unlocked for the guest.') });
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
          cur.tl.push({ w: now(), x: 'Official Receipt recorded', m: val + ' issued by the University Cashier. Needed at check-in.' });
        });
      }

      /* The guest arrived and showed their POS + OR. Hostel-only, manual. */
      function checkIn() {
        staffAction('check_in', {}, function () {
          cur.checkedIn = true;
          cur.tl.push({ w: now(), x: 'Guest checked in', m: 'POS ' + (cur.pos || '—') + ' and OR ' + (cur.or || '—') + ' presented and matched against the valid ID.' });
        });
      }

      function rejectRequest() {
        staffAction('reject', { note: 'Reservation released back to availability.' }, function () {
          cur.tl.push({ w: now(), x: 'Request rejected', m: 'Held dates released back to availability. Customer notified.' });
        });
      }

      function confirmPay() {
        const cash = cur.method === 'cash';
        staffAction('confirm_payment', {}, function () {
          cur.confirmedBy = 'You · just now';
          cur.tl.push({ w: now(), x: cash ? 'Cash payment recorded' : 'Payment confirmed',
            m: cash ? 'Recorded at the cashier.' : 'Reference matched in GCash Transaction History. Reference locked.' });
        });
      }

      function rejectPay() {
        const why = 'Rejected by staff after checking GCash — the reference was not found in the Transaction History.';
        staffAction('reject_payment', { note: why }, function () {
          cur.rejectedByStaff = true;      /* a person decided this, not the scanner */
          cur.rejects = [why + ' (reference freed, record kept for audit)'];
          cur.tl.push({ w: now(), x: 'Receipt rejected', m: 'Payment reopened so the customer can resubmit before the deadline.' });
        });
      }
      /* Take back a rejection STAFF made and return the receipt to where it was —
         the confirm / reject choice. Only offered for staff rejections: an
         auto-rejection is a duplicate or a total mismatch, and the considered way
         back from those is Override & confirm with a written reason.
         The timeline keeps both entries; undoing a decision is itself a decision. */
      function undoRejection() {
        cur.pay = cur.prevPay || 'under_review';
        cur.rejectedByStaff = false;
        cur.rejects = []; cur.resubmitBy = null;   /* the 48-hour window dies with the rejection */
        cur.tl.push({ w: now(), x: 'Rejection withdrawn', m: 'Staff took the rejection back; the receipt is under review again.' });
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
          cur.tl.push({ w: now(), x: 'Refund denied', m: msg + '. Final — the customer would need to book again. The booking itself is unaffected.' });
        } else {
          cur.pay = 'refund_correction';
          cur.refund = cur.refund || {};
          cur.refund.stage = 'Returned for correction';
          cur.refund.docs = 'Returned to the customer: ' + msg;
          cur.tl.push({ w: now(), x: 'Returned for correction', m: msg + ' · 48 hours to resubmit. Not a denial — the booking is unaffected.' });
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
        const body = new URLSearchParams(Object.assign({ csrf: CSRF, booking: cur.id, action: action }, extra));
        try {
          const res = await fetch('booking-action.php', { method: 'POST', body: body, credentials: 'same-origin' });
          const out = await res.json().catch(function () { return { ok: false, message: 'The server sent an unreadable reply.' }; });
          if (!out.ok) { cur.outcomeError = out.message || 'Nothing was recorded.'; return false; }
          if (out.booking) { cur.res = out.booking.res; cur.pay = out.booking.pay; }
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
          `<div class="br-page"><a class="br-back" href="booking-requests.php">&larr; Back to Booking Requests</a>
            <h1 class="br-title">Request not found</h1>
            <p class="br-subtitle">There is no refund request for <strong>${esc(qid)}</strong>. It may have been withdrawn by the customer, or already decided.</p>
          </div>`;
      }
      if (!cur) { showMissing(); } else { render(); }
    </script>
  </body>
</html>
