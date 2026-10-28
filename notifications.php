<?php
/* =====================================================================
   THE BELL'S ENDPOINT (2026-10-28) — includes/notification-bell.php calls
   this about once a minute, from every signed-in page of both portals.

   GET                         { ok, unread, items[], reminders[] }
   POST csrf, action=read, id  mark one read (the person clicked it)
   POST csrf, action=read_all  "Mark all as read"

   Only ever the signed-in person's OWN notifications. Staff and admins
   also get the live "about to expire" reminders (staff: their venue).
   ===================================================================== */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function nt_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

venusep_session_start();
$type = $_SESSION['account_type'] ?? null;
if (in_array($type, ['admin', 'staff'], true) && isset($_SESSION['user_id'])) {
    staff_session_heal();           /* a suspended account loses its session here too */
    $type = $_SESSION['account_type'] ?? null;
} elseif (!customer_logged_in()) {
    nt_reply(401, ['ok' => false, 'message' => 'Not signed in.']);
}
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0 || !in_array($type, ['admin', 'staff', 'customer'], true)) {
    nt_reply(401, ['ok' => false, 'message' => 'Not signed in.']);
}
$pdo = venusep_db();
if ($pdo === null) {
    nt_reply(503, ['ok' => false, 'message' => 'The database is unreachable.']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        nt_reply(400, ['ok' => false, 'message' => 'This page has expired. Reload it and try again.']);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'read_all') {
        notif_mark_read($pdo, $userId, null);
    } elseif ($action === 'read' && ctype_digit((string) ($_POST['id'] ?? ''))) {
        notif_mark_read($pdo, $userId, (int) $_POST['id']);
    } else {
        nt_reply(400, ['ok' => false, 'message' => 'Unknown action.']);
    }
    nt_reply(200, ['ok' => true, 'unread' => notif_unread_count($pdo, $userId)]);
}

try {
    notif_cleanup($pdo, $userId);
    nt_reply(200, [
        'ok'        => true,
        'unread'    => notif_unread_count($pdo, $userId),
        'items'     => notif_items($pdo, $userId, $type),
        'reminders' => $type === 'customer' ? [] : notif_reminders($pdo, staff_venue_scope()),
    ]);
} catch (PDOException $e) {
    error_log('VENUSeP notifications: ' . $e->getMessage());
    nt_reply(500, ['ok' => false, 'message' => 'Notifications could not be loaded.']);
}
