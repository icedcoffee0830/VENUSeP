<?php
/* =====================================================================
   ROOM SAVE — updates rooms.name + rooms.venue_id, called by "Save
   Changes" on room-form.php and hostel-room-form.php.

   POST  csrf, room_id (room_code, e.g. "r1"), name, venue (venue name),
         room_type ('event' | 'hostel')
   Replies with JSON.

   room_type is checked against the room's actual rooms.room_type so the
   event-room form can't be pointed at a hostel room (or vice versa) by
   tampering with the URL — same defensive shape as admin/refund-switch.php
   re-checking the account server-side rather than trusting the page.

   Only name + venue are wired here — capacity, rate, maintenance and
   amenities on these forms are still [SIM] mockup, same as everywhere
   else in this app; scope is what was asked for.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rsv_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rsv_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    rsv_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    rsv_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$roomCode = isset($_POST['room_id']) ? $_POST['room_id'] : '';
$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
$venueName = trim(isset($_POST['venue']) ? $_POST['venue'] : '');
$roomType = isset($_POST['room_type']) ? $_POST['room_type'] : '';

if (!is_string($roomCode) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode)) {
    rsv_reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
}
if ($name === '' || mb_strlen($name) > 150) {
    rsv_reply(400, ['ok' => false, 'error' => 'bad_name', 'message' => 'Enter a room name (up to 150 characters).']);
}
if ($venueName === '') {
    rsv_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Choose a location.']);
}
if (!in_array($roomType, ['event', 'hostel'], true)) {
    rsv_reply(400, ['ok' => false, 'error' => 'bad_type', 'message' => 'Invalid request.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    rsv_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the room was not saved.']);
}

try {
    $room = $pdo->prepare('SELECT id, room_type FROM rooms WHERE room_code = :code');
    $room->execute([':code' => $roomCode]);
    $roomRow = $room->fetch();
    if (!$roomRow) {
        rsv_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
    }
    if ($roomRow['room_type'] !== $roomType) {
        rsv_reply(409, ['ok' => false, 'error' => 'wrong_type', 'message' => 'This room does not match the form that submitted it.']);
    }

    $venue = $pdo->prepare('SELECT id FROM venues WHERE name = :name');
    $venue->execute([':name' => $venueName]);
    $venueId = $venue->fetchColumn();
    if ($venueId === false) {
        rsv_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Unknown location.']);
    }

    $pdo->prepare('UPDATE rooms SET name = :name, venue_id = :venue_id WHERE id = :id')
        ->execute([':name' => $name, ':venue_id' => $venueId, ':id' => $roomRow['id']]);

    rsv_reply(200, ['ok' => true, 'name' => $name, 'venue' => $venueName]);
} catch (Throwable $e) {
    rsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
}
