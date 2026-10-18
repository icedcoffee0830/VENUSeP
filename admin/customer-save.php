<?php
/* =====================================================================
   CUSTOMER SAVE — admin OR staff disabling/re-enabling a CUSTOMER account.

   POST  csrf, action=set_active, user_id, active=0|1
   Replies with JSON.

   WHY STAFF TOO (unlike admin/staff-save.php, which is admin-only): that
   page manages STAFF-SIDE accounts, where letting staff touch their own
   side would be a conflict of interest. A customer account is the other
   side of the counter — the same staff who verify a customer's ID
   (admin/customer-verify.php) are the ones who need to shut off an account
   that is abusing the system, so this does not restrict to admin.

   THE TARGET MUST BE A CUSTOMER ACCOUNT. Neither admin nor staff may use
   this endpoint to touch a staff-side row — that is staff-save.php's job,
   and it has its own, stricter rules (self-lockout, last-admin guards)
   that do not apply here and must not be bypassed through this door.

   NEVER DELETES. Disabling only, like every other account in this system
   (rooms, venues, staff). A customer's booking history has to keep saying
   their name forever, and the foreign keys would refuse a delete anyway
   once a booking exists.

   DISABLING SIGNS THEM OUT. users.is_active = 0 is checked at login AND on
   every subsequent request by customer_session_heal() (includes/auth.php),
   the same way staff_session_heal() already does for the staff side — so
   an already-open session is ended on its very next request, not just
   refused at the next login.
   ===================================================================== */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cvs_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    cvs_reply(405, ['ok' => false, 'message' => 'Use POST.']);
}
venusep_session_start();
$actorType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($actorType, ['admin', 'staff'], true)) {
    cvs_reply(403, ['ok' => false, 'message' => 'Only admin or staff can manage customer accounts.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    cvs_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
}

$action = (string) ($_POST['action'] ?? '');
$target = isset($_POST['user_id']) && ctype_digit((string) $_POST['user_id']) ? (int) $_POST['user_id'] : 0;
if (!$target) {
    cvs_reply(400, ['ok' => false, 'message' => 'Invalid account.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    cvs_reply(503, ['ok' => false, 'message' => 'The database is unreachable, so nothing was saved.']);
}

$row = $pdo->prepare('SELECT id, account_type, is_active FROM users WHERE id = :u');
$row->execute([':u' => $target]);
$user = $row->fetch();
if (!$user) {
    cvs_reply(404, ['ok' => false, 'message' => 'That account no longer exists.']);
}
/* Customer-side only — staff-save.php owns the staff side. */
if ($user['account_type'] !== 'customer') {
    cvs_reply(403, ['ok' => false, 'message' => 'That account is not a customer account.']);
}

if ($action === 'set_active') {
    $active = !empty($_POST['active']) ? 1 : 0;

    try {
        $pdo->prepare('UPDATE users SET is_active = :a WHERE id = :u')
            ->execute([':a' => $active, ':u' => $target]);
    } catch (PDOException $e) {
        error_log('VENUSeP customer-save (set_active): ' . $e->getMessage());
        cvs_reply(500, ['ok' => false, 'message' => 'Something went wrong, so nothing was changed.']);
    }
    cvs_reply(200, ['ok' => true, 'active' => $active,
        'message' => $active ? 'Account re-enabled.' : 'Account disabled. They have been signed out and can no longer sign in.']);
}

cvs_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
