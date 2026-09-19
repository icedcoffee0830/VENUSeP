<?php
/* =====================================================================
   DOCUMENT VIEW — the ONLY way a stored ID, receipt or OR is ever read.

   GET  ?doc=<booking_documents.id>      an ID / POS / OR / refund support
   GET  ?receipt=<gcash_receipts.id>     a GCash receipt screenshot
   Optional: &download=1 to save rather than display.

   WHY THIS EXISTS
   ---------------
   These files live OUTSIDE the web root (includes/documents.php), so no
   URL reaches them. That is deliberate: a university ID carries a real
   name, an ID number and a face, and a GCash receipt carries a name and
   an amount. Room photos are served straight out of assets/ because
   everyone is meant to see them; these are the opposite case, and
   serving them the same way would mean anyone who learned or guessed a
   path could read a stranger's ID with no login and no trace.

   So the bytes only ever leave through here, and only after this script
   has decided the person asking is allowed to see them.

   WHO MAY SEE WHAT
     staff / admin   any booking's documents — verifying an ID and
                     matching a receipt IS the job
     customer        only documents on their OWN bookings
     anyone else     404

   404, NOT 403, for a document that exists but is not yours. A 403 would
   confirm the document is real, which is itself a small leak: it turns
   "guess an id" into "enumerate which ids exist". A customer probing
   other people's ids learns nothing either way.

   Lives at the project ROOT rather than under admin/ because both
   portals need it — a customer viewing the ID they uploaded goes through
   exactly the same permission check as a staff member viewing it.
   ===================================================================== */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/documents.php';

venusep_session_start();

/* Refuse plainly, and identically, whatever the reason. */
function dv_deny() {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not found.';
    exit;
}

$isStaff    = isset($_SESSION['user_id'], $_SESSION['account_type'])
                && in_array($_SESSION['account_type'], ['admin', 'staff'], true);
$isCustomer = customer_logged_in();
if (!$isStaff && !$isCustomer) {
    dv_deny();
}

$pdo = venusep_db();
if ($pdo === null) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Temporarily unavailable.';
    exit;
}

$docId     = isset($_GET['doc']) && ctype_digit((string) $_GET['doc']) ? (int) $_GET['doc'] : 0;
$receiptId = isset($_GET['receipt']) && ctype_digit((string) $_GET['receipt']) ? (int) $_GET['receipt'] : 0;
if (!$docId && !$receiptId) {
    dv_deny();
}

/* Fetch the row AND the booking's owner in one query, so the permission check
   cannot be accidentally skipped by a later edit that forgets to join. */
if ($docId) {
    $stmt = $pdo->prepare(
        'SELECT d.file_path, d.original_filename, d.mime_type, d.document_type AS kind, b.customer_id
           FROM booking_documents d JOIN bookings b ON b.id = d.booking_id
          WHERE d.id = :i'
    );
    $stmt->execute([':i' => $docId]);
} else {
    $stmt = $pdo->prepare(
        "SELECT g.file_path, g.original_filename, NULL AS mime_type, 'gcash_receipt' AS kind, b.customer_id
           FROM gcash_receipts g JOIN bookings b ON b.id = g.booking_id
          WHERE g.id = :i"
    );
    $stmt->execute([':i' => $receiptId]);
}
$row = $stmt->fetch();
if (!$row) {
    dv_deny();
}

/* THE PERMISSION CHECK. Staff see anything; a customer sees only their own.
   Compared against the SESSION's customer id, never against anything the
   request supplied. */
if (!$isStaff && (int) $row['customer_id'] !== (int) $_SESSION['customer_id']) {
    dv_deny();
}

/* doc_path() realpath-checks the stored path against doc_root(), so a
   tampered file_path cannot walk out of the private area into the rest of
   the disk. A missing file is a 404 like any other. */
$full = doc_path($row['file_path']);
if ($full === null || !is_file($full)) {
    dv_deny();
}

/* Trust the EXTENSION we assigned at upload, not the stored mime_type and not
   anything the browser sends. doc_validate() already proved the file is a real
   image or a PDF, and only ever wrote one of these extensions. */
$ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
$types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
          'webp' => 'image/webp', 'pdf' => 'application/pdf'];
if (!isset($types[$ext])) {
    dv_deny();
}

/* A filename for the save dialog, built from the document KIND rather than the
   customer's original name — "juan-dela-cruz-drivers-licence.jpg" would leak in
   a downloads folder, a screen share or a log line. */
$niceName = preg_replace('/[^a-z0-9_-]+/i', '-', (string) $row['kind']) . '-' . ($docId ?: $receiptId) . '.' . $ext;
$inline   = empty($_GET['download']);

header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $niceName . '"');
/* nosniff: without it a browser may decide a file is HTML and run it in this
   origin, which would turn "view an uploaded image" into stored XSS. */
header('X-Content-Type-Options: nosniff');
header('Content-Security-Policy: default-src \'none\'; img-src \'self\'; object-src \'none\'; sandbox');
header('Referrer-Policy: no-referrer');
/* private, not public: a shared cache must never hold someone's ID. */
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

readfile($full);
