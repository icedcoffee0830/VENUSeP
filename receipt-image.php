<?php
/* =====================================================================
   RECEIPT IMAGE — the "View" button in Transaction History (both
   portals). Always clickable, never disabled: for a booking with an
   uploaded GCash screenshot it shows the picture; for one without it
   (paid in cash, or no payment submitted yet) it says so plainly instead
   of going nowhere or forcing the page to pre-guess which rows deserve a
   working button.

   GET  ?booking=<VB-ref>

   WHO MAY SEE WHAT — same rule as document-view.php, which this defers
   to for the actual bytes: staff see any booking, a customer only their
   own, anyone else a 404 (never 403 — see document-view.php's header for
   why that matters here too).

   Lives at the project ROOT, like document-view.php and receipt-pdf.php:
   both portals reach it from their own Transaction History page.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bookings.php';

function ri_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
function ri_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

venusep_session_start();
$riIsStaff = isset($_SESSION['user_id'], $_SESSION['account_type'])
    && in_array($_SESSION['account_type'], ['admin', 'staff'], true);
$riIsCustomer = customer_logged_in();
if (!$riIsStaff && !$riIsCustomer) {
    ri_not_found();
}

$riRef = (string) ($_GET['booking'] ?? '');
$riBookingId = booking_id_from_reference($riRef);
$riPdo = venusep_db();
if ($riBookingId === null || $riPdo === null) {
    ri_not_found();
}

$riOwner = $riPdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
$riOwner->execute([':b' => $riBookingId]);
$riOwnerId = $riOwner->fetchColumn();
if ($riOwnerId === false || (!$riIsStaff && (int) $riOwnerId !== (int) $_SESSION['customer_id'])) {
    ri_not_found();
}

$riReceipt = booking_receipt($riBookingId);
/* Already have one — go straight to the actual image. One extra hop, but
   it keeps the permission + byte-serving logic in exactly one place
   (document-view.php) rather than duplicating it here. */
if ($riReceipt !== null) {
    header('Location: document-view.php?receipt=' . (int) $riReceipt['id']);
    exit;
}

/* Nothing uploaded. Say why, from the booking's own payment method and
   status — never a blank 404 for a row the page itself offered a button
   for. */
$riRows = bookings_query(['booking_id' => $riBookingId]);
$riBooking = $riRows[0] ?? null;
if ($riBooking === null) {
    ri_not_found();
}
$riWhy = $riBooking['method'] === 'Cash'
    ? 'This booking was paid in cash at the venue office, so there is no GCash screenshot — the cashier record is what staff verified instead.'
    : 'No GCash screenshot has been uploaded for this booking yet. It shows up here the moment one is submitted.';
header('Cache-Control: private, no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>VENUSeP | Payment screenshot</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { background: #f4f3f0; color: #1c1b19; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .ri-card { max-width: 380px; width: 100%; background: #fff; border-radius: 18px; box-shadow: 0 20px 60px rgba(20,10,10,.14); padding: 32px 26px 28px; text-align: center; }
  .ri-icon { width: 50px; height: 50px; margin: 0 auto 16px; border-radius: 999px; background: #f2f0ec; display: flex; align-items: center; justify-content: center; color: #8a857d; font-size: 22px; }
  .ri-card h1 { margin: 0 0 8px; font-size: 17px; font-weight: 720; }
  .ri-card p { margin: 0 0 22px; font-size: 13.5px; line-height: 1.6; color: #5c5752; }
  .ri-back { display: inline-flex; align-items: center; gap: 7px; height: 42px; padding: 0 20px; border-radius: 11px; background: #1f1e1e; color: #fff; text-decoration: none; font-size: 13.5px; font-weight: 650; }
  .ri-back:hover { background: #343333; }
</style>
</head>
<body>
  <div class="ri-card">
    <div class="ri-icon"><i class="bi bi-receipt-cutoff" aria-hidden="true"></i></div>
    <h1>No payment screenshot</h1>
    <p><?php echo ri_e($riWhy); ?></p>
    <a class="ri-back" href="javascript:history.back()"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
  </div>
</body>
</html>
