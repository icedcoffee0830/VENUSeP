<?php
/* =====================================================================
   RECEIPT PDF — download a booking's VENUSeP System Receipt (#22).

   GET  ?booking=<VB-reference>

   Same rules as document-view.php, for the same reasons:
     staff / admin   any booking's receipt — printing a walk-in's is the job
     customer        only receipts on their OWN bookings
     anyone else     404 (never 403: a 403 would confirm the booking exists)

   Lives at the project ROOT because both portals use it: a customer's
   "Download PDF" on the receipt page and staff's "Download PDF" on the
   booking detail go through exactly the same check.

   The PDF is drawn on request from the receipt's frozen snapshot, so there
   is no stored file to protect and every download is the same receipt.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/system-receipt.php';

venusep_session_start();

function rp_deny() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$isStaff    = isset($_SESSION['user_id'], $_SESSION['account_type'])
                && in_array($_SESSION['account_type'], ['admin', 'staff'], true);
$isCustomer = customer_logged_in();
if (!$isStaff && !$isCustomer) {
    rp_deny();
}

$bookingId = booking_id_from_reference($_GET['booking'] ?? '');
$pdo = venusep_db();
if ($bookingId === null || $pdo === null) {
    rp_deny();
}

/* THE PERMISSION CHECK, against the SESSION's customer id — never anything
   the request supplied. */
$stmt = $pdo->prepare('SELECT customer_id FROM bookings WHERE id = :b');
$stmt->execute([':b' => $bookingId]);
$owner = $stmt->fetchColumn();
if ($owner === false || (!$isStaff && (int) $owner !== (int) $_SESSION['customer_id'])) {
    rp_deny();
}

$receipt = receipt_for_booking($pdo, $bookingId);
if ($receipt === null) {
    rp_deny();
}
$bytes = receipt_pdf_bytes($receipt);

header('Content-Type: application/pdf');
header('Content-Length: ' . strlen($bytes));
header('Content-Disposition: attachment; filename="' . $receipt['number'] . '.pdf"');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');
echo $bytes;
