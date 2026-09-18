<?php
/* =====================================================================
   DISCOUNT SAVE — the ONLY way system_settings.discount_percent changes.
   Called by the "Change rate" card on admin/venue-management.php.

   POST  csrf=<token>  percent=0..100  reason=<why>
   Replies with JSON.

   A SETTINGS CHANGE IS AN EVENT, NOT AN OVERWRITE. Every change writes a
   system_settings_history row (old value, new value, who, when, why), so
   "why is everything 15% off?" always has an answer. The reason is
   required for exactly that purpose.

   This rate is SNAPSHOTTED onto a booking when the booking is made
   (bookings.discount_percent), so changing it here never re-prices a
   booking that was already quoted — DB-DECISIONS #2.

   CONFIGURATION, NOT A TRANSACTION: this writes for real even while demo
   mode is on. Demo mode only intercepts the booking/payment/refund
   lifecycle; setting up the system is not a showcase.

   Every check happens HERE, on the server: the page's buttons are for the
   admin's benefit, none of them is a security boundary.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function ds_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    ds_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    ds_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    ds_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$raw    = isset($_POST['percent']) ? trim((string) $_POST['percent']) : '';
$reason = isset($_POST['reason']) ? trim((string) $_POST['reason']) : '';

if (!preg_match('/^\d{1,3}$/', $raw) || (int) $raw > 100) {
    ds_reply(400, ['ok' => false, 'error' => 'bad_percent', 'message' => 'Enter a whole number from 0 to 100.']);
}
if ($reason === '') {
    ds_reply(400, ['ok' => false, 'error' => 'no_reason', 'message' => 'A reason is required — it goes into the change history.']);
}
if (mb_strlen($reason) > 500) {
    $reason = mb_substr($reason, 0, 500);
}
$percent = (int) $raw;

$pdo = venusep_db();
if ($pdo === null) {
    ds_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the rate was not changed.']);
}

try {
    $pdo->beginTransaction();

    /* FOR UPDATE: two admins saving at once must not interleave, or the
       history would record an "old value" that was never current. */
    $cur = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'discount_percent' FOR UPDATE");
    $cur->execute();
    $old = $cur->fetchColumn();
    if ($old === false) {
        $pdo->rollBack();
        ds_reply(500, ['ok' => false, 'error' => 'missing_setting', 'message' => 'The discount setting is missing from the database.']);
    }
    if ((string) $old === (string) $percent) {
        $pdo->rollBack();
        ds_reply(400, ['ok' => false, 'error' => 'unchanged', 'message' => 'That is already the current rate.']);
    }

    $pdo->prepare(
        "UPDATE system_settings
            SET setting_value = :v, updated_by_user_id = :u
          WHERE setting_key = 'discount_percent'"
    )->execute([':v' => (string) $percent, ':u' => (int) $_SESSION['user_id']]);

    $pdo->prepare(
        "INSERT INTO system_settings_history (setting_key, old_value, new_value, change_note, changed_by_user_id)
         VALUES ('discount_percent', :old, :new, :note, :u)"
    )->execute([
        ':old'  => (string) $old,
        ':new'  => (string) $percent,
        ':note' => $reason,
        ':u'    => (int) $_SESSION['user_id'],
    ]);

    $pdo->commit();
    ds_reply(200, ['ok' => true, 'percent' => $percent, 'previous' => (int) $old]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    ds_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the rate was not changed.']);
}
