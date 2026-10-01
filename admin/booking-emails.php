<?php
/* =====================================================================
   RECEIPT & EMAILS — what the booking detail's panel reads, and its
   Resend button (DB-DECISIONS #22). Staff/admin only. Replies with JSON.

   GET   ?booking=<VB-ref>
         -> { ok, isWalkIn, to, receipt: null|{number}, emails: [...] }
   POST  csrf, booking=<VB-ref>, action=resend, id=<email_outbox.id>
         -> { ok, email: {...} }

   Bodies are never returned — staff see what was sent and whether it went,
   not a second copy of the customer's paperwork. Resend only touches an
   email of THIS booking that failed (or has sat unsent for 5 minutes), so
   it cannot be used to re-send arbitrary rows or to spam a customer.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/receipt-emails.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function be_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

venusep_session_start();
$beType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($beType, ['admin', 'staff'], true)) {
    be_reply(401, ['ok' => false, 'message' => 'Your session has ended. Log in again.']);
}
$beIsPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($beIsPost && !csrf_valid($_POST['csrf'] ?? null)) {
    be_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$beBookingId = booking_id_from_reference($beIsPost ? ($_POST['booking'] ?? '') : ($_GET['booking'] ?? ''));
$pdo = venusep_db();
if ($beBookingId === null) {
    be_reply(400, ['ok' => false, 'message' => 'Invalid booking reference.']);
}
if ($pdo === null) {
    be_reply(503, ['ok' => false, 'message' => 'The database is unreachable.']);
}

/* One outbox row as the panel shows it. */
function be_email(array $r) {
    return [
        'id'        => (int) $r['id'],
        'kind'      => $r['kind'],
        'status'    => $r['status'],
        'attempts'  => (int) $r['attempts'],
        'receiptId' => $r['receipt_id'] !== null ? (int) $r['receipt_id'] : null,
        'created'   => date('M j, Y, g:i A', strtotime($r['created_at'])),
        'sent'      => $r['sent_at'] ? date('M j, Y, g:i A', strtotime($r['sent_at'])) : null,
        'error'     => $r['last_error'] !== null ? mb_substr((string) $r['last_error'], 0, 120) : null,
    ];
}

if ($beIsPost) {
    if (($_POST['action'] ?? '') !== 'resend') {
        be_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
    $stmt = $pdo->prepare(
        "SELECT id FROM email_outbox
          WHERE id = :id AND booking_id = :b
            AND (status = 'failed' OR (status = 'pending' AND created_at < NOW() - INTERVAL 5 MINUTE))"
    );
    $stmt->execute([':id' => (int) ($_POST['id'] ?? 0), ':b' => $beBookingId]);
    $beId = $stmt->fetchColumn();
    if ($beId === false) {
        be_reply(409, ['ok' => false, 'message' => 'That email was already sent, or is still on its way.']);
    }
    mail_send($pdo, (int) $beId);
    $stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE id = :id');
    $stmt->execute([':id' => $beId]);
    $row = $stmt->fetch();
    be_reply(200, ['ok' => $row['status'] === 'sent', 'email' => be_email($row),
        'message' => $row['status'] === 'sent' ? 'Sent.' : 'Still couldn&rsquo;t send it. Check the mail settings and try again.']);
}

$stmt = $pdo->prepare(
    'SELECT c.user_id, c.contact_email, u.email AS account_email
       FROM bookings b JOIN customers c ON c.id = b.customer_id LEFT JOIN users u ON u.id = c.user_id
      WHERE b.id = :b'
);
$stmt->execute([':b' => $beBookingId]);
$who = $stmt->fetch();
if (!$who) {
    be_reply(404, ['ok' => false, 'message' => 'That booking no longer exists.']);
}
$isWalkIn = $who['user_id'] === null;

$stmt = $pdo->prepare('SELECT * FROM email_outbox WHERE booking_id = :b ORDER BY id');
$stmt->execute([':b' => $beBookingId]);
$receipt = receipt_for_booking($pdo, $beBookingId);

be_reply(200, [
    'ok'       => true,
    'isWalkIn' => $isWalkIn,
    'to'       => (string) ($isWalkIn ? $who['contact_email'] : $who['account_email']),
    'receipt'  => $receipt ? ['number' => $receipt['number']] : null,
    'emails'   => array_map('be_email', $stmt->fetchAll()),
]);
