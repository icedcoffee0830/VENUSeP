<?php
/* =====================================================================
   CUSTOMER IDENTITY CHECK AT THE COUNTER.

   POST  csrf, customer_id, password
   Replies with JSON: { ok, message }

   WHAT THIS IS FOR
   ----------------
   A walk-in says they already have an account. Staff find it by name,
   and then the CUSTOMER types their own password on the counter-facing
   screen. This checks it and throws it away.

   WHY IT DOES NOT LOG THEM IN
   ---------------------------
   The app keeps one user_id and one account_type per session. Signing
   the customer in here would sign the staff member OUT of the window
   beside them — same browser, same cookie. So nothing about the session
   changes: the booking is made by staff throughout, and all this call
   leaves behind is a note in the STAFF session saying "customer 42
   proved who they were at 10:32".

   WHY IT IS REQUIRED AND NOT OPTIONAL
   -----------------------------------
   Linking a booking to an account hands that account real power over
   it: it appears in their My Bookings, it can be paid through their
   GCash flow, and it can be refunded to them. A name match alone is not
   evidence, and a wrong match gives one customer another's booking.

   The proof is deliberately short-lived. It is spent by the booking it
   was taken for (admin/booking-create.php clears it), and it expires on
   its own, so a confirmation cannot sit in a session all afternoon and
   be reused on a different person.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const COUNTER_PROOF_TTL = 900;      // 15 minutes — long enough to finish a booking, not an afternoon

function cv_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    cv_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
$type = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($type, ['admin', 'staff'], true)) {
    cv_reply(403, ['ok' => false, 'message' => 'Staff only.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    cv_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$customerId = (int) ($_POST['customer_id'] ?? 0);
$password   = (string) ($_POST['password'] ?? '');
if ($customerId <= 0 || $password === '') {
    cv_reply(400, ['ok' => false, 'message' => 'Pick the account and ask the customer to type their password.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    cv_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was confirmed.']);
}

try {
    $stmt = $pdo->prepare(
        'SELECT u.id, u.password_hash, u.is_active
           FROM customers c JOIN users u ON u.id = c.user_id
          WHERE c.id = :c LIMIT 1'
    );
    $stmt->execute([':c' => $customerId]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('VENUSeP customer-verify: ' . $e->getMessage());
    cv_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was confirmed.']);
}

if (!$user) {
    cv_reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
}
if (!(bool) $user['is_active']) {
    cv_reply(403, ['ok' => false, 'message' => 'That account is suspended and cannot be booked for.']);
}

/* A wrong password here is the customer mistyping their own, not an attacker
   working through a list — so it does not feed the staff lockout ladder, which
   guards ADMIN actions. It is still checked with password_verify and the reply
   says nothing about which part was wrong. */
if (!password_verify($password, $user['password_hash'])) {
    cv_reply(401, ['ok' => false, 'message' => 'That password did not match. Try again, or book them as a walk-in with no account.']);
}

if (!isset($_SESSION['counter_proof']) || !is_array($_SESSION['counter_proof'])) {
    $_SESSION['counter_proof'] = [];
}
$_SESSION['counter_proof'][$customerId] = time();

cv_reply(200, ['ok' => true, 'message' => 'Identity confirmed.']);
