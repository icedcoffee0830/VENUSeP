<?php require_once __DIR__ . '/../includes/auth.php'; admin_require_login(); /* admin or staff — any booking, not just one's own */ ?>
<?php
/* =====================================================================
   RECEIPT (STAFF VIEW) — the same VENUSeP System Receipt customer/
   receipt.php shows, reached from admin/transaction-history.php's
   "Receipt" button.

   GET  ?booking=<VB-ref>

   WHY A SEPARATE PAGE FROM customer/receipt.php, rather than letting staff
   through that one: customer/receipt.php is gated by
   customer_require_login() and checks the booking against the SESSION's
   OWN customer_id — exactly right for a customer, and exactly wrong for
   staff, who by definition need to open bookings that are not theirs. It
   also offers "Email me this receipt", which sends to the session's own
   address — meaningless here, since the receipt belongs to a customer,
   not the staff member looking at it. Staff who want a copy to hand over
   use Download PDF, same as everywhere else (admin/booking-request.php).

   Any booking, any staff member — this is the same "staff may see any
   booking's documents" rule receipt-pdf.php and document-view.php already
   follow, so looking here is never a question of whose booking it is.
   ===================================================================== */
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/system-receipt.php';

function ar_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

function ar_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

$arRef = (string) ($_GET['booking'] ?? '');
$arBookingId = booking_id_from_reference($arRef);
$arPdo = venusep_db();
if ($arBookingId === null || $arPdo === null) {
    ar_not_found();
}

$arReceipt = receipt_for_booking($arPdo, $arBookingId);
if ($arReceipt === null) {
    ar_not_found();
}
$arView = receipt_view($arReceipt);
header('Cache-Control: private, no-store');
?>
<!DOCTYPE html>
<html lang="en">
  <head>
<!-- light mode only: stop browser auto-dark + Dark Reader from repainting the page -->
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>VENUSeP | System Receipt <?php echo ar_e($arView['number']); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/600.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/700.css" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />

    <!-- [0] SHELL CSS — same block every admin page using header.php/sidebar.php uses -->
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

    <!-- [1] PAGE CSS — the same "paper slip" receipt as customer/receipt.php, so staff
         and customers are never shown two different drawings of one receipt -->
    <style>
      :root { --crimson: #a11626; --crimson-deep: #7d0f1e; --ink: #1d1214; --soft: #6e6a64; --line: #efe0db; --head: #faf9f7; --ease: cubic-bezier(.16,1,.3,1); }
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
      .rc-btn:focus-visible, .rc-back:focus-visible { outline: 2px solid var(--crimson); outline-offset: 2px; }

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
      <?php include __DIR__ . '/../includes/header.php'; ?>
      <?php $active = 'Transaction History'; include __DIR__ . '/../includes/sidebar.php'; ?>

      <main class="app-main">
        <div class="app-content">
          <div class="container-fluid">
            <div class="rc-wrap">
              <div class="rc-bar">
                <a class="rc-back" href="transaction-history.php"><i class="bi bi-arrow-left" aria-hidden="true"></i>Transaction History</a>
                <div class="rc-tools">
                  <a class="rc-btn" href="../receipt-pdf.php?booking=<?php echo urlencode($arRef); ?>"><i class="bi bi-download" aria-hidden="true"></i>Download PDF</a>
                </div>
              </div>

              <article class="rc-paper" aria-label="System Receipt <?php echo ar_e($arView['number']); ?>">
                <div class="rc-rule"></div>
                <div class="rc-in">
                  <div class="rc-top">
                    <img src="../assets/img/receipt-logo.png" alt="VENUSeP" width="130" height="37">
                    <div class="rc-doc">
                      <h1>System Receipt</h1>
                      <div>No. <b><?php echo ar_e($arView['number']); ?></b></div>
                      <div>Issued <?php echo ar_e($arView['issued']); ?></div>
                    </div>
                  </div>
                  <div class="rc-cols">
                    <div><h2>Received from</h2><p><?php echo implode('<br>', array_map('ar_e', $arView['from'])); ?></p></div>
                    <div><h2>Booking</h2><p><?php echo implode('<br>', array_map('ar_e', $arView['booking'])); ?></p></div>
                  </div>
                  <table class="rc-table">
                    <thead><tr><th scope="col">Description</th><th scope="col" class="r">Amount</th></tr></thead>
                    <tbody>
                      <?php foreach ($arView['items'] as $arItem): ?>
                        <tr><td><?php echo ar_e($arItem['label']); ?><small><?php echo ar_e($arItem['sub']); ?></small></td><td class="r"><?php echo ar_e($arItem['amount']); ?></td></tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                  <div class="rc-totals">
                    <div><span>Total due</span><span><?php echo ar_e($arView['total']); ?></span></div>
                    <div class="rc-paid"><span>Amount paid</span><span><?php echo ar_e($arView['paid']); ?></span></div>
                  </div>
                  <div class="rc-pay">
                    <?php foreach ($arView['paid_by'] as [$arK, $arV]): ?>
                      <div><span><?php echo ar_e($arK); ?></span><?php echo ar_e($arV); ?></div>
                    <?php endforeach; ?>
                  </div>
                  <p class="rc-foot"><?php echo ar_e($arView['footer']); ?></p>
                </div>
              </article>
            </div>
          </div>
        </div>
      </main>
    </div>
  </body>
</html>
