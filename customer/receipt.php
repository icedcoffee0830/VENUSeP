<?php require_once __DIR__ . '/../includes/auth.php'; customer_require_login(); /* customers only — guests go to the login page */ ?>
<?php
/* ==================================================================
   RECEIPT — one booking's VENUSeP System Receipt (DB-DECISIONS #22).

   GET   ?booking=<VB-ref>[&confirm=1|&sent=1|&recent=1|&failed=1]
   POST  csrf, action=email            "Email me this receipt"

   Reached from the "Receipt" button in Booking History. Shows the receipt
   drawn from its frozen snapshot (the same lines as the PDF — includes/
   system-receipt.php), with Download PDF and Email me this receipt.

   EMAIL ME goes to the account's OWN address, read from the database, never
   from the request — so the button cannot be pointed at someone else. It asks
   first (the confirm strip shows the address), and sends at most one copy per
   receipt every 5 minutes. No JavaScript: each state is a URL, and the POST
   redirects back to one (Post/Redirect/Get), so a refresh never re-sends.

   Someone else's booking, or a booking with no receipt yet: 404.
   ================================================================== */
require_once __DIR__ . '/../includes/receipt-emails.php';

function rc_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$rcRef = (string) ($_GET['booking'] ?? '');
$rcBookingId = booking_id_from_reference($rcRef);
$rcPdo = venusep_db();
if ($rcBookingId === null || $rcPdo === null) {
    rc_not_found();
}

/* Own booking only — checked against the SESSION's customer id. */
$rcStmt = $rcPdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
$rcStmt->execute([':b' => $rcBookingId]);
if ((int) $rcStmt->fetchColumn() !== (int) $_SESSION['customer_id']) {
    rc_not_found();
}
$rcReceipt = receipt_for_booking($rcPdo, $rcBookingId);
if ($rcReceipt === null) {
    rc_not_found();
}

/* The account's own address and name — the only place "Email me" can send. */
$rcStmt = $rcPdo->prepare('SELECT u.email, c.full_name FROM users u JOIN customers c ON c.user_id = u.id WHERE u.id = :u');
$rcStmt->execute([':u' => (int) $_SESSION['user_id']]);
$rcMe = $rcStmt->fetch() ?: ['email' => '', 'full_name' => ''];

$rcSelf = 'receipt.php?booking=' . urlencode($rcRef);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        header('Location: ' . $rcSelf . '&failed=1', true, 303);
        exit;
    }
    $rcState = 'failed';
    if (receipt_copy_sent_recently($rcPdo, (int) $rcReceipt['id'])) {
        $rcState = 'recent';
    } else {
        try {
            $rcOutbox = queue_receipt_copy($rcPdo, (int) $rcReceipt['id'], (int) $_SESSION['user_id'], (string) $rcMe['email'], (string) $rcMe['full_name']);
            if ($rcOutbox !== null && mail_send($rcPdo, $rcOutbox)) {
                $rcState = 'sent';
            }
        } catch (\Throwable $e) {
            error_log('VENUSeP receipt copy for booking ' . $rcBookingId . ': ' . $e->getMessage());
        }
    }
    header('Location: ' . $rcSelf . '&' . $rcState . '=1', true, 303);
    exit;
}

$rcConfirm = !empty($_GET['confirm']);
$rcFlash   = !empty($_GET['sent']) ? 'sent' : (!empty($_GET['recent']) ? 'recent' : (!empty($_GET['failed']) ? 'failed' : ''));
$rcView    = receipt_view($rcReceipt);
$rcCsrf    = csrf_token();
header('Cache-Control: private, no-store');
?>
<!DOCTYPE html>
<!-- ==================================================================
  MAP: [0] SHELL CSS · [1] PAGE CSS · [2] HEADER · [3] SIDEBAR ·
       [4] CONTENT (top bar, email strip, the receipt)
  Receipt lines come from receipt_view() — the same ones the PDF draws.
  ================================================================== -->
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | System Receipt <?php echo bh_e($rcView['number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS — shared layout (same block as booking-history.php) -->
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

    <!-- [1] PAGE CSS — the approved "Paper slip" receipt (_preview/email/receipt-view.html) -->
    <style>
      :root { --crimson: #a11626; --crimson-deep: #7d0f1e; --ink: #1d1214; --soft: #6e6a64; --line: #efe0db; --head: #faf9f7; --ease: cubic-bezier(.16,1,.3,1); }
      /* white page, like Booking History (this page is its sub-page) — not the crimson account backdrop */
      body { background: #fff !important; }
      .app-main { background: #fff !important; }
      ::selection { background: rgba(161,22,38,.16); }
      .rc-wrap { max-width: 860px; margin: 0 auto; padding-top: 22px; }
      .rc-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
      .rc-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: #4a4440; text-decoration: none; }
      .rc-back:hover { color: var(--crimson); }
      .rc-tools { display: inline-flex; gap: 8px; flex-wrap: wrap; }
      .rc-btn { display: inline-flex; align-items: center; gap: 7px; height: 40px; padding: 0 16px; border-radius: 999px; border: 1px solid #d7d7d7; background: #fff; color: var(--black); font: 600 13px Inter, system-ui, sans-serif; text-decoration: none; cursor: pointer; transition: background 220ms var(--ease), border-color 220ms var(--ease); }
      .rc-btn:hover { background: #f4f2ee; border-color: #c9c2b6; }
      .rc-btn.is-primary { background: var(--crimson); border-color: var(--crimson); color: #fff; }
      .rc-btn.is-primary:hover { background: var(--crimson-deep); }
      .rc-btn:focus-visible, .rc-back:focus-visible { outline: 2px solid var(--crimson); outline-offset: 2px; }

      .rc-strip { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 13.5px; }
      .rc-strip p { margin: 0; flex: 1 1 260px; }
      .rc-strip form { margin: 0; display: inline-flex; gap: 8px; }
      .rc-strip.is-ask { background: #fff; border: 1px solid var(--border); color: #4a4440; }
      .rc-strip.is-ask strong { color: var(--ink); }
      .rc-strip.is-ok { background: #eaf6ef; border: 1px solid #cfe7d9; color: #17573a; }
      .rc-strip.is-ok i { color: #1c7a4f; font-size: 16px; }
      .rc-strip.is-warn { background: #fdf3e6; border: 1px solid #f0dcc0; color: #5b3c0c; }
      .rc-strip.is-warn i { color: #8a5a12; font-size: 16px; }

      .rc-paper { background: #fff; border: 1px solid var(--border); border-radius: 4px; overflow: hidden; color: var(--ink); }
      .rc-paper .rc-rule { height: 5px; background: var(--crimson); }
      .rc-paper .rc-in { padding: 28px clamp(18px, 5vw, 48px) 32px; }
      .rc-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
      .rc-top img { width: 130px; height: auto; display: block; }
      .rc-doc { text-align: right; }
      .rc-doc h1 { margin: 0; font-size: 20px; font-weight: 700; letter-spacing: -.01em; }
      .rc-doc div { margin-top: 4px; font-size: 13px; color: var(--soft); }
      .rc-doc b { color: var(--ink); }
      .rc-cols { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 18px 32px; margin-top: 26px; padding-top: 16px; border-top: 1px solid var(--line); }
      .rc-cols h2 { margin: 0 0 6px; font-size: 12px; font-weight: 600; color: var(--soft); }
      .rc-cols p { margin: 0; font-size: 13.5px; line-height: 1.65; overflow-wrap: anywhere; }
      .rc-table { width: 100%; border-collapse: collapse; margin-top: 24px; font-size: 13.5px; }
      .rc-table th { text-align: left; background: var(--head); border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); padding: 9px 12px; font-size: 12px; font-weight: 600; color: var(--soft); }
      .rc-table th.r, .rc-table td.r { text-align: right; white-space: nowrap; }
      .rc-table td { padding: 11px 12px; border-bottom: 1px solid var(--line); vertical-align: top; font-variant-numeric: tabular-nums; }
      .rc-table td small { display: block; color: var(--soft); font-size: 12px; margin-top: 2px; }
      .rc-totals { max-width: 300px; margin: 14px 0 0 auto; font-size: 13.5px; }
      .rc-totals div { display: flex; justify-content: space-between; gap: 16px; padding: 5px 12px; font-variant-numeric: tabular-nums; }
      .rc-totals .rc-paid { border-top: 2px solid var(--ink); margin-top: 6px; padding-top: 10px; font-weight: 700; font-size: 15px; }
      .rc-pay { margin-top: 24px; font-size: 13.5px; line-height: 1.75; padding: 12px 16px; border: 1px solid var(--line); }
      .rc-pay span { display: inline-block; min-width: 130px; color: var(--soft); }
      .rc-foot { margin: 26px 0 0; padding-top: 12px; border-top: 1px solid var(--line); font-size: 12px; color: var(--soft); line-height: 1.6; }
      @media (max-width: 520px) { .rc-doc { text-align: left; } }
    </style>
  </head>
  <body class="receipt-page">
    <div class="app-wrapper">
      <!-- [2] HEADER + [3] SIDEBAR — shared includes, customer portal variant -->
      <?php $portal = 'customer'; include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Booking History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <!-- [4] PAGE CONTENT -->
      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rc-wrap">
              <div class="rc-bar">
                <a class="rc-back" href="booking-history.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>Booking History</a>
                <div class="rc-tools">
                  <a class="rc-btn" href="../receipt-pdf.php?booking=<?php echo urlencode($rcRef); ?>"><i class="bi bi-download" aria-hidden="true"></i>Download PDF</a>
                  <?php if ($rcFlash !== 'sent'): ?>
                    <a class="rc-btn is-primary" href="<?php echo bh_e($rcSelf); ?>&amp;confirm=1"><i class="bi bi-envelope" aria-hidden="true"></i>Email me this receipt</a>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($rcConfirm && $rcMe['email'] !== ''): ?>
                <div class="rc-strip is-ask" role="group" aria-label="Email the receipt">
                  <p>Send this receipt as a PDF to <strong><?php echo bh_e($rcMe['email']); ?></strong>? Wrong address? <a href="customer-profile.php">Change it in Profile</a> first.</p>
                  <form method="post" action="<?php echo bh_e($rcSelf); ?>">
                    <input type="hidden" name="csrf" value="<?php echo bh_e($rcCsrf); ?>">
                    <input type="hidden" name="action" value="email">
                    <button class="rc-btn is-primary" type="submit">Send</button>
                    <a class="rc-btn" href="<?php echo bh_e($rcSelf); ?>">Cancel</a>
                  </form>
                </div>
              <?php elseif ($rcFlash === 'sent'): ?>
                <div class="rc-strip is-ok" role="status"><i class="bi bi-envelope-check" aria-hidden="true"></i>Sent to <?php echo bh_e($rcMe['email']); ?>. Check your inbox, and your spam folder if it isn&rsquo;t there in a minute.</div>
              <?php elseif ($rcFlash === 'recent'): ?>
                <div class="rc-strip is-ok" role="status"><i class="bi bi-envelope-check" aria-hidden="true"></i>Already sent a few minutes ago. Check your inbox, and your spam folder.</div>
              <?php elseif ($rcFlash === 'failed'): ?>
                <div class="rc-strip is-warn" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i>We couldn&rsquo;t send it right now. Try again in a few minutes, or use Download PDF.</div>
              <?php endif; ?>

              <article class="rc-paper" aria-label="System Receipt <?php echo bh_e($rcView['number']); ?>">
                <div class="rc-rule"></div>
                <div class="rc-in">
                  <div class="rc-top">
                    <img src="../assets/img/receipt-logo.png" alt="VENUSeP" width="130" height="37">
                    <div class="rc-doc">
                      <h1>System Receipt</h1>
                      <div>No. <b><?php echo bh_e($rcView['number']); ?></b></div>
                      <div>Issued <?php echo bh_e($rcView['issued']); ?></div>
                    </div>
                  </div>
                  <div class="rc-cols">
                    <div><h2>Received from</h2><p><?php echo implode('<br>', array_map('bh_e', $rcView['from'])); ?></p></div>
                    <div><h2>Booking</h2><p><?php echo implode('<br>', array_map('bh_e', $rcView['booking'])); ?></p></div>
                  </div>
                  <table class="rc-table">
                    <thead><tr><th scope="col">Description</th><th scope="col" class="r">Amount</th></tr></thead>
                    <tbody>
                      <?php foreach ($rcView['items'] as $rcItem): ?>
                        <tr><td><?php echo bh_e($rcItem['label']); ?><small><?php echo bh_e($rcItem['sub']); ?></small></td><td class="r"><?php echo bh_e($rcItem['amount']); ?></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div class="rc-totals">
                    <div><span>Total due</span><span><?php echo bh_e($rcView['total']); ?></span></div>
                    <div class="rc-paid"><span>Amount paid</span><span><?php echo bh_e($rcView['paid']); ?></span></div>
                  </div>
                  <div class="rc-pay">
                    <?php foreach ($rcView['paid_by'] as [$rcK, $rcV]): ?>
                      <div><span><?php echo bh_e($rcK); ?></span><?php echo bh_e($rcV); ?></div>
                    <?php endforeach; ?>
                  </div>
                  <p class="rc-foot"><?php echo bh_e($rcView['footer']); ?> A refund request needs this receipt.</p>
                </div>
              </article>
            </div>
          </div>
        </div>
      </main>
    </div>
  </body>
</html>
