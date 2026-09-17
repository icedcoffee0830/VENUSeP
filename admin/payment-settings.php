<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); ?>
<?php
/* The ONE source of the GCash accounts. This page is the screen that edits it. */
include __DIR__ . '/../includes/payment-settings.php';

/* THE REFUND SWITCH (2026-09-16) — real, database-backed. The card only shows
   the state and asks for confirmation; admin/refund-switch.php does every check.
   customer-bookings.php brings in includes/refund-policy.php and the [SIM]
   bookings, used for the "stay refundable" count in the OFF warning. */
require_once __DIR__ . '/../includes/customer-bookings.php';
$rsDetails  = refund_setting_details();          // null = database unreachable
$rsDbOk     = $rsDetails !== null;
$rsEnabled  = $rsDbOk && $rsDetails['enabled'];
$rsIsAdmin  = admin_is_admin();                  // staff see the card read-only
$rsCsrf     = csrf_token();
$rsStayRefundable = count(array_filter($customerBookings, function ($b) { return $b['refundable']; }));

/* A lock still running from earlier (the countdown resumes after a reload). */
$rsLockSeconds = 0;
if ($rsDbOk && $rsIsAdmin) {
  try {
    $rsStmt = venusep_db()->prepare(
      'SELECT CASE WHEN reauth_locked_until > NOW() THEN TIMESTAMPDIFF(SECOND, NOW(), reauth_locked_until) ELSE 0 END
         FROM users WHERE id = :id'
    );
    $rsStmt->execute([':id' => (int) $_SESSION['user_id']]);
    $rsLockSeconds = (int) $rsStmt->fetchColumn();
  } catch (PDOException $e) { $rsLockSeconds = 0; }
}
$rsJustSaved = isset($_GET['refunds']) && $_GET['refunds'] === 'saved';
function rs_e($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<!-- ==================================================================
  PAYMENT SETTINGS — VENUSeP merged system
  ==================================================================
  MAP OF THIS FILE — Ctrl+F the [n] tag to jump to a section:

    [0] SHELL CSS     team header + sidebar styles (same on every page)
    [1] PAGE CSS      this page's own styles
    [2] HEADER BAR    team top bar (same on every page)
    [3] SIDEBAR       team dark menu w/ logo (same on every page)
    [4] PAGE CONTENT  refund switch card + one card per venue: GCash name + number
    [5] REFUND MODAL  the ON / OFF warning + password re-entry
    [6] PAGE SCRIPT   edit / save / cancel, live preview of what changes
    [7] REFUND SCRIPT open the warning, send the password, lockout countdown

  WHY THIS PAGE EXISTS
  --------------------
  The GCash number is used in TWO places that must never disagree:
    1. the payment screen TELLS the customer where to send the money
    2. the receipt checker VERIFIES the money landed there
  Both read includes/payment-settings.php. Change the number here and both
  move together. If they ever diverged, the customer would pay exactly what
  they were shown and the checker would auto-reject it as "receiver
  mismatch" — a bug that looks like OCR failing and is nearly unfindable.

  THREE ACCOUNTS, ONE PER VENUE. Bahay Alumni and USeP Venues used to share
  one; they are separate now, so a receipt paid to the wrong venue's account
  is correctly rejected.

  [SIM] nothing persists — Save updates the page only. Wire to a
  `payment_settings` table keyed by venue when the database exists.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | Payment Settings</title>

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- ============================================================
         [0] SHELL CSS — the TEAM header bar + sidebar + layout.
         Same block on every page.
         ============================================================ -->
    <style>
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
      .app-main {
        margin-left: var(--venusep-sidebar-width);
        padding-top: var(--venusep-header-height);
        min-height: 100vh;
        background: #fff;
      }
      .container-fluid { width: 100%; padding-inline: 30px; } /* uniform content inset — 30px sides on every admin page */
      .app-content-header { padding: 26px 0 0; }              /* 26px top, same as other pages */
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
         [1] PAGE CSS — same tokens and the same neutral-surface look
         as Venue Management (hairlines, no filled tint boxes).
         ============================================================ -->
    <style>
      :root {
        --ps-border: #e5e5e5;
        --ps-muted: #6b675f;
        --ps-text: #1c1b19;
        --ps-dark: #1f1e1e;
        --ps-warn: #ff6b12;
        --ps-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
      }
      .ps-header { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 1rem; justify-content: space-between; margin-bottom: 1.4rem; }
      .ps-heading h1 { font-size: 1.6rem; font-weight: 700; letter-spacing: -0.02em; margin: 0 0 0.25rem; }
      .ps-heading p { color: var(--ps-muted); font-size: 0.88rem; margin: 0; }

      /* the "why this matters" note — a plain hairline panel, not a tint box */
      .ps-why {
        border: 1px solid var(--ps-border);
        border-radius: 12px;
        color: var(--ps-muted);
        font-size: 0.82rem;
        line-height: 1.6;
        margin-bottom: 1.4rem;
        max-width: 780px;
        padding: 0.85rem 1rem;
      }
      .ps-why strong { color: var(--ps-text); font-weight: 650; }

      .ps-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(330px, 1fr)); }

      .ps-card {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 14px;
        box-shadow: var(--ps-shadow);
        display: flex;
        flex-direction: column;
        padding: 1.1rem 1.15rem 1rem;
      }
      .ps-venue { align-items: center; display: flex; gap: 0.55rem; margin-bottom: 0.15rem; }
      .ps-venue h2 { font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin: 0; }
      .ps-kind {
        border: 1px solid var(--ps-border);
        border-radius: 999px;
        color: var(--ps-muted);
        font-size: 0.68rem;
        font-weight: 600;
        padding: 0.12rem 0.5rem;
      }
      .ps-note { color: var(--ps-muted); font-size: 0.76rem; line-height: 1.55; margin: 0.35rem 0 0.9rem; }

      /* label-over-value, open — no grey filled boxes */
      .ps-kv { border-top: 1px solid #f0efec; padding: 0.6rem 0; }
      .ps-kv .k { color: #a5a19a; font-size: 0.66rem; font-weight: 600; letter-spacing: 0.06em; text-transform: uppercase; }
      .ps-kv .v { color: var(--ps-text); font-size: 0.95rem; font-weight: 640; margin-top: 0.15rem; }
      .ps-kv .v.mono { font-variant-numeric: tabular-nums; letter-spacing: 0.02em; }

      .ps-control {
        background: #fff;
        border: 1px solid rgba(0, 0, 0, 0.14);
        border-radius: 8px;
        font: inherit;
        font-size: 0.9rem;
        height: 38px;
        margin-top: 0.2rem;
        padding: 0 0.6rem;
        width: 100%;
      }
      .ps-control:focus { outline: 2px solid rgba(31, 42, 68, 0.35); outline-offset: 0; }
      .ps-err { color: #b23a3a; font-size: 0.72rem; margin-top: 0.3rem; }

      .ps-foot { align-items: center; display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 0.9rem; padding-top: 0.8rem; border-top: 1px solid #f0efec; }
      .ps-btn {
        background: #fff;
        border: 1px solid var(--ps-border);
        border-radius: 9px;
        color: var(--ps-text);
        cursor: pointer;
        font: inherit;
        font-size: 0.8rem;
        font-weight: 600;
        padding: 0.45rem 0.85rem;
        text-decoration: none;
      }
      .ps-btn:hover { background: #fbfbfa; }
      .ps-btn-primary { background: var(--ps-dark); border-color: var(--ps-dark); color: #fff; }
      .ps-btn-primary:hover { background: #343333; }

      /* what changing this number actually does — asked, not decorated */
      .ps-effect { align-items: flex-start; display: flex; gap: 0.5rem; font-size: 0.74rem; color: var(--ps-muted); line-height: 1.5; margin-top: 0.55rem; }
      .ps-effect::before { background: var(--ps-warn); border-radius: 50%; content: ''; flex: none; height: 6px; margin-top: 0.35rem; width: 6px; }
      .ps-saved { color: #1c7a4f; font-size: 0.74rem; font-weight: 600; margin-right: auto; }

      /* ---- refund switch card — same hairline look as the GCash cards ---- */
      .rs-card { background: #fff; border: 1px solid var(--ps-border); border-radius: 14px; box-shadow: var(--ps-shadow); margin-bottom: 1.4rem; max-width: 780px; padding: 1.1rem 1.15rem 1rem; }
      .rs-top { align-items: flex-start; display: flex; flex-wrap: wrap; gap: 0.9rem; justify-content: space-between; }
      .rs-title { align-items: center; display: flex; flex-wrap: wrap; gap: 0.55rem; }
      .rs-title h2 { font-size: 1rem; font-weight: 680; letter-spacing: -0.01em; margin: 0; }
      .rs-pill { border-radius: 999px; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.04em; padding: 0.16rem 0.55rem; }
      .rs-pill-on { background: #e7f5ee; color: #1c7a4f; }
      .rs-pill-off { background: #efeeec; color: #4a463f; }
      .rs-state { color: var(--ps-text); font-size: 0.86rem; line-height: 1.55; margin: 0.35rem 0 0; max-width: 58ch; }
      .rs-lock { align-items: center; color: var(--ps-muted); display: inline-flex; font-size: 0.78rem; font-weight: 600; gap: 0.4rem; }
      .rs-btn-on { background: #1c7a4f; border-color: #1c7a4f; color: #fff; }
      .rs-btn-on:hover { background: #166340; }
      .rs-rules { color: var(--ps-muted); font-size: 0.76rem; line-height: 1.55; margin: 0.8rem 0 0; padding-left: 1.05rem; }
      .rs-meta { border-top: 1px solid #f0efec; color: #a5a19a; font-size: 0.74rem; margin-top: 0.8rem; padding-top: 0.6rem; }
      .rs-meta strong { color: var(--ps-muted); font-weight: 600; }
      .rs-flash { align-items: center; color: #1c7a4f; display: flex; font-size: 0.8rem; font-weight: 600; gap: 0.4rem; margin-bottom: 0.6rem; }
      .rs-error { color: #b23a3a; }

      /* ---- the warning + password window ---- */
      .rs-backdrop { align-items: center; background: rgba(15, 12, 10, 0.5); display: flex; inset: 0; justify-content: center; padding: 16px; position: fixed; z-index: 2000; }
      .rs-backdrop[hidden], .rs-msg[hidden] { display: none; }   /* display:flex would otherwise beat the hidden attribute */
      .rs-modal { background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, 0.3); max-height: calc(100vh - 32px); max-width: 480px; overflow-y: auto; width: 100%; }
      .rs-modal-head { align-items: flex-start; border-bottom: 1px solid #f0efec; display: flex; gap: 0.7rem; padding: 1.1rem 1.2rem 0.9rem; }
      .rs-modal-head i { color: #c2540a; flex: none; font-size: 1.3rem; line-height: 1.2; }
      .rs-modal-head h3 { font-size: 1.02rem; font-weight: 700; margin: 0; }
      .rs-modal-head p { color: var(--ps-muted); font-size: 0.8rem; margin: 0.2rem 0 0; }
      .rs-modal-body { padding: 0.9rem 1.2rem 0; }
      .rs-list { font-size: 0.84rem; line-height: 1.55; margin: 0 0 1rem; padding-left: 1.1rem; }
      .rs-list li { margin-bottom: 0.45rem; }
      .rs-pw-label { display: block; font-size: 0.78rem; font-weight: 650; margin-bottom: 0.1rem; }
      .rs-msg { border-radius: 8px; font-size: 0.78rem; line-height: 1.45; margin-top: 0.55rem; padding: 0.5rem 0.65rem; }
      .rs-msg-bad { background: #fcecec; color: #b23a3a; }
      .rs-msg-lock { background: #fdf3e6; color: #8a5a12; }
      .rs-modal-foot { border-top: 1px solid #f0efec; display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1rem; padding: 0.8rem 1.2rem; }
      .rs-modal-foot .ps-btn[disabled] { cursor: not-allowed; opacity: 0.5; }
    </style>
  </head>

  <body>
    <div class="app-wrapper">
<!-- ==========================================================
           [2] HEADER BAR — the ONE shared top bar.
           ========================================================== -->
      <?php include __DIR__ . '/../includes/header.php'; ?>

<!-- ==========================================================
           [3] SIDEBAR — the ONE shared sidebar.
           ========================================================== -->
      <?php $active = 'Payment Settings'; include __DIR__ . '/../includes/sidebar.php'; ?>

<!-- ==========================================================
           [4] PAGE CONTENT — one card per venue. Rendered from PHP so
           the cards and the booking pages read the same array.
           ========================================================== -->
      <main class="app-main">
        <div class="app-content-header">
          <div class="container-fluid">
            <div class="ps-header">
              <div class="ps-heading">
                <h1>Payment Settings</h1>
                <p>Whether customers can request refunds, and the GCash account each venue's payments go to.</p>
              </div>
            </div>

            <!-- REFUND REQUESTS — the admin-only switch (includes/refund-policy.php).
                 Everything shown here is re-checked by admin/refund-switch.php. -->
            <section class="rs-card" id="rsCard" aria-labelledby="rsTitle">
              <?php if ($rsJustSaved && $rsDbOk): ?>
                <div class="rs-flash" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Saved. Refund requests are now <?php echo $rsEnabled ? 'ON' : 'OFF'; ?>.</div>
              <?php endif; ?>
              <div class="rs-top">
                <div>
                  <div class="rs-title">
                    <h2 id="rsTitle">Refund Requests</h2>
                    <?php if ($rsDbOk): ?>
                      <span class="rs-pill <?php echo $rsEnabled ? 'rs-pill-on' : 'rs-pill-off'; ?>"><?php echo $rsEnabled ? 'ON' : 'OFF'; ?></span>
                    <?php endif; ?>
                  </div>
                  <?php if (!$rsDbOk): ?>
                    <p class="rs-state rs-error">The database cannot be reached, so this setting cannot be read or changed right now. Until it is back, customers are shown the no-refund policy.</p>
                  <?php elseif ($rsEnabled): ?>
                    <p class="rs-state"><strong>Customers may request refunds</strong> on bookings they make while this is ON. Those bookings are <strong>paid before the event</strong> (after approval, by the day before).</p>
                  <?php else: ?>
                    <p class="rs-state"><strong>All new bookings are non-refundable</strong> and are <strong>paid after the event</strong> &mdash; payment opens the day after the last booked day and is due within <?php echo (int) $POSTPAY_GRACE_DAYS; ?> days; unpaid bookings go overdue, nothing is released. Customers must confirm they understand this before they book. This is USeP's normal policy.</p>
                  <?php endif; ?>
                </div>
                <div>
                  <?php if (!$rsDbOk): ?>
                    <span class="rs-lock"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Unavailable</span>
                  <?php elseif ($rsIsAdmin): ?>
                    <button type="button" class="ps-btn <?php echo $rsEnabled ? 'ps-btn-primary' : 'rs-btn-on'; ?>" id="rsOpen">
                      <?php echo $rsEnabled ? 'Turn refunds OFF' : 'Turn refunds ON'; ?>
                    </button>
                  <?php else: ?>
                    <span class="rs-lock"><i class="bi bi-lock-fill" aria-hidden="true"></i>Only the administrator can change this</span>
                  <?php endif; ?>
                </div>
              </div>
              <ul class="rs-rules">
                <li>A change applies to <strong>new bookings only</strong>. Every booking keeps the policy it was made under.</li>
                <li>Closures by USeP are not affected. Those customers are always offered a replacement room, a new date, or their money back.</li>
                <li>Changing it needs your password, and every change is recorded.</li>
              </ul>
              <?php if ($rsDbOk): ?>
                <div class="rs-meta">
                  <?php if ($rsDetails['updated_by']): ?>
                    Last changed by <strong><?php echo rs_e($rsDetails['updated_by']); ?></strong> on <?php echo rs_e(date('M j, Y \a\t g:i A', strtotime($rsDetails['updated_at']))); ?>
                  <?php else: ?>
                    Not changed since the system was set up (default: OFF).
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </section>

            <div class="ps-why">
              Each number below is used <strong>twice</strong>: the booking page tells the customer where to send the money, and the receipt checker verifies it landed there. Both read the same value, so they cannot drift apart — change it here and both move together. <strong>A wrong number does not fail loudly:</strong> the customer pays exactly what they were shown and every receipt is auto-rejected as “receiver mismatch”, which looks like the scanner is broken.
            </div>
          </div>
        </div>

        <div class="app-content">
          <div class="container-fluid">
            <div class="ps-grid" id="psGrid">
<?php foreach ($gcAccounts as $venue => $acct):
        $isHostel = $venue === 'USeP Hostel'; ?>
              <div class="ps-card" data-venue="<?php echo htmlspecialchars($venue); ?>">
                <div class="ps-venue">
                  <h2><?php echo htmlspecialchars($venue); ?></h2>
                  <span class="ps-kind"><?php echo $isHostel ? 'Staff account' : 'Business account'; ?></span>
                </div>
                <p class="ps-note"><?php echo htmlspecialchars($acct['note']); ?></p>

                <div class="ps-kv">
                  <div class="k">GCash account name</div>
                  <div class="v" data-f="name"><?php echo htmlspecialchars($acct['name']); ?></div>
                </div>
                <div class="ps-kv">
                  <div class="k">GCash number</div>
                  <div class="v mono" data-f="number"><?php echo htmlspecialchars(gcFormatNumber($acct['number'])); ?></div>
                </div>

                <div class="ps-foot">
                  <button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>
                </div>
              </div>
<?php endforeach; ?>
            </div>

          </div>
        </div>
      </main>
    </div>

<?php if ($rsDbOk && $rsIsAdmin): ?>
<!-- ============================================================
         [5] REFUND MODAL — the warning + password re-entry. Rendered for
         the admin only; staff never get the form.
         ============================================================ -->
    <div class="rs-backdrop" id="rsModal" role="dialog" aria-modal="true" aria-labelledby="rsModalTitle" hidden>
      <div class="rs-modal">
        <div class="rs-modal-head">
          <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
          <div>
            <h3 id="rsModalTitle"></h3>
            <p>Read this before you confirm.</p>
          </div>
        </div>
        <form id="rsForm" novalidate>
          <div class="rs-modal-body">
            <ul class="rs-list" id="rsModalList"></ul>
            <label class="rs-pw-label" for="rsPassword">Enter your admin password to confirm</label>
            <input class="ps-control" type="password" id="rsPassword" autocomplete="current-password" />
            <div class="rs-msg" id="rsMsg" role="alert" hidden></div>
          </div>
          <div class="rs-modal-foot">
            <button type="button" class="ps-btn" id="rsCancel">Cancel</button>
            <button type="submit" class="ps-btn ps-btn-primary" id="rsConfirm"></button>
          </div>
        </form>
      </div>
    </div>

    <!-- Open refund requests live in the [SIM] refund store until bookings are
         in the database; the OFF warning counts them from there. -->
    <?php include __DIR__ . '/../includes/refund-store.php'; ?>

<!-- ============================================================
         [7] REFUND SCRIPT — opens the right warning, posts to
         admin/refund-switch.php, runs the lockout countdown. The server
         decides everything; this only reports what it says.
         ============================================================ -->
    <script>
      (function () {
        const ENABLED = <?php echo $rsEnabled ? 'true' : 'false'; ?>;
        const CSRF = <?php echo json_encode($rsCsrf); ?>;
        const STAY_REFUNDABLE = <?php echo (int) $rsStayRefundable; ?>;
        let lockUntil = Date.now() + <?php echo (int) $rsLockSeconds; ?> * 1000;
        let timer = null, busy = false;

        const modal = document.getElementById('rsModal');
        const title = document.getElementById('rsModalTitle');
        const list = document.getElementById('rsModalList');
        const form = document.getElementById('rsForm');
        const pw = document.getElementById('rsPassword');
        const msg = document.getElementById('rsMsg');
        const confirmBtn = document.getElementById('rsConfirm');
        const openBtn = document.getElementById('rsOpen');

        const plural = function (n, one, many) { return n + ' ' + (n === 1 ? one : many); };
        function openRequests() {
          if (!window.RefundStore) return 0;
          const all = RefundStore.all();
          return Object.keys(all).filter(function (k) { return all[k] && (all[k].status === 'open' || all[k].status === 'fix'); }).length;
        }

        function show(kind, text) { msg.className = 'rs-msg ' + (kind === 'lock' ? 'rs-msg-lock' : 'rs-msg-bad'); msg.textContent = text; msg.hidden = false; }
        function hideMsg() { msg.hidden = true; }

        function tick() {
          const left = Math.ceil((lockUntil - Date.now()) / 1000);
          if (left > 0) {
            pw.disabled = true; confirmBtn.disabled = true;
            show('lock', 'Too many wrong passwords. Try again in ' + (left >= 60 ? Math.floor(left / 60) + 'm ' + (left % 60) + 's' : left + 's') + '.');
            return;
          }
          clearInterval(timer); timer = null;
          pw.disabled = false; confirmBtn.disabled = false; hideMsg();
          if (!modal.hidden) pw.focus();
        }
        function startCountdown(seconds) {
          lockUntil = Date.now() + seconds * 1000;
          if (!timer) timer = setInterval(tick, 250);
          tick();
        }

        function open() {
          const turningOn = !ENABLED;
          title.textContent = turningOn ? 'Enable refund requests?' : 'Disable refund requests?';
          confirmBtn.textContent = turningOn ? 'Enable refunds' : 'Disable refunds';
          const items = turningOn ? [
            'Customers will be able to request refunds on bookings made <strong>from now on</strong>.',
            'Bookings made while refunds were OFF <strong>stay non-refundable</strong>, because their customers agreed to that.',
            'The landing page, FAQ and checkout will stop saying bookings are non-refundable.',
            'New bookings switch to <strong>pre-pay</strong>: paid after approval, by the day before the event; unpaid holds are released.',
            '<strong>Only enable this if the University\'s policy officially allows refunds.</strong>'
          ] : [
            'Customers <strong>can no longer request refunds</strong> on new bookings, and must confirm a booking is non-refundable before making it.',
            '<strong>' + plural(openRequests(), 'open refund request is', 'open refund requests are') + '</strong> still waiting. Staff can still finish them.',
            '<strong>' + plural(STAY_REFUNDABLE, 'booking', 'bookings') + '</strong> made while refunds were ON <strong>' + (STAY_REFUNDABLE === 1 ? 'stays' : 'stay') + ' refundable</strong>, because their customers were promised that.',
            'New bookings switch to <strong>post-pay</strong>: nothing is paid until the event is over, then it is due within ' + <?php echo (int) $POSTPAY_GRACE_DAYS; ?> + ' days. Unpaid bookings go overdue instead of being released.',
            'Closures by USeP are not affected. Those customers are still offered a replacement or their money back.'
          ];
          list.innerHTML = items.map(function (t) { return '<li>' + t + '</li>'; }).join('');
          pw.value = ''; hideMsg();
          modal.hidden = false;
          if (lockUntil > Date.now()) startCountdown(Math.ceil((lockUntil - Date.now()) / 1000));
          else pw.focus();
        }
        function close() {
          if (busy) return;
          modal.hidden = true; pw.value = ''; hideMsg();
          if (openBtn) openBtn.focus();
        }

        if (openBtn) openBtn.addEventListener('click', open);
        document.getElementById('rsCancel').addEventListener('click', close);
        modal.addEventListener('click', function (e) { if (e.target === modal) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) close(); });

        form.addEventListener('submit', function (e) {
          e.preventDefault();
          if (busy || lockUntil > Date.now()) return;
          if (!pw.value) { show('bad', 'Enter your admin password to confirm.'); pw.focus(); return; }

          const body = new FormData();
          body.append('csrf', CSRF);
          body.append('enable', ENABLED ? '0' : '1');
          body.append('password', pw.value);

          busy = true; confirmBtn.disabled = true; pw.disabled = true; hideMsg();
          fetch('refund-switch.php', { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json().catch(function () { return { ok: false, message: 'Unexpected reply from the server.' }; }); })
            .then(function (res) {
              busy = false;
              if (res.ok) { location.replace('payment-settings.php?refunds=saved'); return; }
              pw.value = '';
              if (res.error === 'locked') { startCountdown(res.seconds || 10); return; }
              confirmBtn.disabled = false; pw.disabled = false;
              if (res.error === 'wrong_password') {
                show('bad', 'Wrong password. ' + plural(res.attemptsLeft, 'attempt', 'attempts') + ' left before a temporary lock.');
              } else if (res.error === 'not_logged_in') {
                show('bad', res.message); setTimeout(function () { location.href = 'admin-login.php'; }, 1500);
              } else {
                show('bad', res.message || 'The setting was not changed.');
              }
              pw.focus();
            })
            .catch(function () {
              busy = false; confirmBtn.disabled = false; pw.disabled = false;
              show('bad', 'Could not reach the server. The setting was not changed.');
            });
        });
      })();
    </script>
<?php endif; ?>

<!-- ============================================================
         [6] PAGE SCRIPT — edit in place. [SIM] Save updates the card
         only; nothing persists and no booking page is affected until
         includes/payment-settings.php is backed by a database.
         ============================================================ -->
    <script>
      /* The checker compares DIGITS, never the pretty form — so a number is
         valid on its digits alone. 11 digits starting 09 is the PH mobile
         format GCash uses; anything else would silently fail every receipt. */
      function psDigits(s) { return String(s).replace(/\D+/g, ''); }
      function psPretty(s) { return psDigits(s).replace(/^(\d{4})(\d{3})(\d{4})$/, '$1 $2 $3'); }
      function psValid(s) { const d = psDigits(s); return d.length === 11 && d.startsWith('09'); }
      function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

      function psField(card, f) { return card.querySelector('[data-f="' + f + '"]'); }

      function psEdit(btn) {
        const card = btn.closest('.ps-card');
        if (card.querySelector('.ps-control')) return;          // already editing
        const name = psField(card, 'name'), number = psField(card, 'number');
        card.dataset.oldName = name.textContent;
        card.dataset.oldNumber = number.textContent;

        name.innerHTML = '<input class="ps-control" data-i="name" value="' + esc(card.dataset.oldName) + '" placeholder="Name exactly as it shows on GCash">';
        number.innerHTML = '<input class="ps-control" data-i="number" value="' + esc(card.dataset.oldNumber) + '" placeholder="09xx xxx xxxx" inputmode="numeric">'
          + '<div class="ps-err" data-e hidden></div>';

        card.querySelector('.ps-foot').innerHTML =
          '<span class="ps-saved" data-s hidden>Saved</span>'
          + '<button type="button" class="ps-btn" onclick="psCancel(this)">Cancel</button>'
          + '<button type="button" class="ps-btn ps-btn-primary" onclick="psSave(this)">Save</button>';

        const eff = document.createElement('div');
        eff.className = 'ps-effect';
        eff.dataset.eff = '1';
        eff.textContent = 'Changing this changes what customers are told to pay AND what the checker accepts. Existing unpaid bookings for this venue will expect the new account.';
        card.querySelector('.ps-foot').before(eff);
        card.querySelector('[data-i="name"]').focus();
      }

      function psCancel(btn) {
        const card = btn.closest('.ps-card');
        psRestore(card, card.dataset.oldName, card.dataset.oldNumber, false);
      }

      function psSave(btn) {
        const card = btn.closest('.ps-card');
        const name = card.querySelector('[data-i="name"]').value.trim();
        const raw = card.querySelector('[data-i="number"]').value;
        const err = card.querySelector('[data-e]');

        /* Refuse to save something the checker could never match. Saving a
           malformed number would not error anywhere — it would just reject
           every receipt for this venue, quietly. */
        if (!name) { err.textContent = 'The account name cannot be empty — the checker matches it when the number is masked on a receipt.'; err.hidden = false; return; }
        if (!psValid(raw)) { err.textContent = 'Needs 11 digits starting with 09. The checker compares digits, so a number in any other shape rejects every receipt.'; err.hidden = false; return; }
        err.hidden = true;
        psRestore(card, name, psPretty(raw), true);
      }

      function psRestore(card, name, number, saved) {
        psField(card, 'name').textContent = name;
        psField(card, 'number').textContent = number;
        const eff = card.querySelector('[data-eff]');
        if (eff) eff.remove();
        card.querySelector('.ps-foot').innerHTML =
          (saved ? '<span class="ps-saved">Saved <span style="font-weight:400;color:#a5a19a">· [SIM] not persisted</span></span>' : '')
          + '<button type="button" class="ps-btn" onclick="psEdit(this)">Edit</button>';
      }
    </script>
  </body>
</html>
