<?php
/* =====================================================================
   ROOM TOGGLE ACTIVE — flips rooms.is_active, called from the Enable/
   Disable button on each room card in Venue Management.

   POST  csrf, room_id (room_code, e.g. "r1")
   Replies with JSON: { ok, active, name, message }

   This is NOT the delete flow (admin/catalog-delete.php): it never removes
   a row, so it works on a room with real booking history, and it is
   reversible with the same click. FALSE just means "not offered right
   now" — rooms.is_active exists for exactly this (see its column comment
   in venusep_schema.sql). Existing bookings on the room are untouched;
   this only controls whether NEW customers can see or book it — the same
   filter every catalog read already applies (includes/venue-rooms.php,
   includes/hostel-rooms.php), and booking-submit.php re-checks it server
   side regardless of what the page showed.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rta_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rta_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    rta_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    rta_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$roomCode = trim(isset($_POST['room_id']) ? (string) $_POST['room_id'] : '');
if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $roomCode)) {
    rta_reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    rta_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so nothing changed.']);
}

try {
    $pdo->beginTransaction();
    $room = $pdo->prepare('SELECT id, name, is_active FROM rooms WHERE room_code = :code FOR UPDATE');
    $room->execute([':code' => $roomCode]);
    $roomRow = $room->fetch();
    if (!$roomRow) {
        $pdo->rollBack();
        rta_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
    }

    $newActive = $roomRow['is_active'] ? 0 : 1;
    $pdo->prepare('UPDATE rooms SET is_active = :a WHERE id = :id')
        ->execute([':a' => $newActive, ':id' => (int) $roomRow['id']]);
    $pdo->commit();

    rta_reply(200, [
        'ok' => true, 'active' => (bool) $newActive, 'name' => $roomRow['name'],
        'message' => $roomRow['name'] . ($newActive ? ' is visible to customers again.' : ' is hidden from customers now.'),
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP room-toggle-active: ' . $e->getMessage());
    rta_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Nothing changed because the request could not be completed safely.']);
}
