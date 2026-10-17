<?php
/* =====================================================================
   VALID ID VIEW — the "View" button in Transaction History (both
   portals). Always clickable, never disabled: a valid ID is required for
   EVERY booking (PROJECT-HANDOFF 4.7, customer/booking-submit.php), so
   unlike the GCash receipt or the System Receipt — which only exist once
   payment happens — there is always something here to show.

   GET  ?booking=<VB-ref>

   WHO MAY SEE WHAT — same rule as document-view.php, which this defers to
   for the actual bytes: staff see any booking, a customer only their own,
   anyone else a 404 (never 403 — see document-view.php's header for why
   that matters here too).

   Lives at the project ROOT, like document-view.php and receipt-pdf.php:
   both portals reach it from their own Transaction History page.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/bookings.php';

function vi_not_found() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}
function vi_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

venusep_session_start();
$viIsStaff = isset($_SESSION['user_id'], $_SESSION['account_type'])
    && in_array($_SESSION['account_type'], ['admin', 'staff'], true);
$viIsCustomer = customer_logged_in();
if (!$viIsStaff && !$viIsCustomer) {
    vi_not_found();
}

$viRef = (string) ($_GET['booking'] ?? '');
$viBookingId = booking_id_from_reference($viRef);
$viPdo = venusep_db();
if ($viBookingId === null || $viPdo === null) {
    vi_not_found();
}

$viOwner = $viPdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
$viOwner->execute([':b' => $viBookingId]);
$viOwnerId = $viOwner->fetchColumn();
if ($viOwnerId === false || (!$viIsStaff && (int) $viOwnerId !== (int) $_SESSION['customer_id'])) {
    vi_not_found();
}

$viDocs = booking_document_ids($viBookingId);
/* Already have one — go straight to the actual image. One extra hop, but
   it keeps the permission + byte-serving logic in exactly one place
   (document-view.php) rather than duplicating it here. */
if (isset($viDocs['customer_id'])) {
    header('Location: document-view.php?doc=' . (int) $viDocs['customer_id']);
    exit;
}

/* No ID on file — should not happen for a real booking (it is required at
   submission), but a row the page already offered a button for must never
   just dead-end on a blank 404. */
header('Cache-Control: private, no-store');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta name="darkreader-lock">
<meta name="color-scheme" content="light">
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>VENUSeP | Valid ID</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fontsource/inter@5/index.css" crossorigin="anonymous" />
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css" crossorigin="anonymous" />
<style>
  * { box-sizing: border-box; }
  html, body { margin: 0; min-height: 100%; }
  body { background: #f4f3f0; color: #1c1b19; font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif; display: flex; align-items: center; justify-content: center; padding: 24px; }
  .vi-card { max-width: 380px; width: 100%; background: #fff; border-radius: 18px; box-shadow: 0 20px 60px rgba(20,10,10,.14); padding: 32px 26px 28px; text-align: center; }
  .vi-icon { width: 50px; height: 50px; margin: 0 auto 16px; border-radius: 999px; background: #f2f0ec; display: flex; align-items: center; justify-content: center; color: #8a857d; font-size: 22px; }
  .vi-card h1 { margin: 0 0 8px; font-size: 17px; font-weight: 720; }
  .vi-card p { margin: 0 0 22px; font-size: 13.5px; line-height: 1.6; color: #5c5752; }
  .vi-back { display: inline-flex; align-items: center; gap: 7px; height: 42px; padding: 0 20px; border-radius: 11px; background: #1f1e1e; color: #fff; text-decoration: none; font-size: 13.5px; font-weight: 650; }
  .vi-back:hover { background: #343333; }
</style>
</head>
<body>
  <div class="vi-card">
    <div class="vi-icon"><i class="bi bi-person-vcard" aria-hidden="true"></i></div>
    <h1>No ID on file</h1>
    <p>No valid ID was recorded for this booking.</p>
    <a class="vi-back" href="javascript:history.back()"><i class="bi bi-arrow-left" aria-hidden="true"></i>Back</a>
  </div>
</body>
</html>
