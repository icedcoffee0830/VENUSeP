<?php
/* Deletes an eligible room or venue from Venue Management.
   Booking/history rows are never deleted here. */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function cd_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    cd_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
if (!isset($_SESSION['user_id'])) {
    cd_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}

$pdo = venusep_db();
if ($pdo === null) {
    cd_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so nothing was deleted.']);
}

/* Re-read the account instead of trusting a possibly stale session role. */
$account = $pdo->prepare('SELECT account_type, is_active FROM users WHERE id = :id');
$account->execute([':id' => (int) $_SESSION['user_id']]);
$accountRow = $account->fetch();
if (!$accountRow || !(bool) $accountRow['is_active'] || $accountRow['account_type'] !== 'admin') {
    cd_reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only administrators can delete rooms or venues.']);
}
$_SESSION['account_type'] = $accountRow['account_type'];

if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    cd_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}
if (($_POST['confirm'] ?? '') !== 'delete') {
    cd_reply(400, ['ok' => false, 'error' => 'confirmation', 'message' => 'Deletion was not confirmed.']);
}

$entity = isset($_POST['entity']) ? (string) $_POST['entity'] : '';

try {
    if ($entity === 'room') {
        $roomCode = trim(isset($_POST['room_id']) ? (string) $_POST['room_id'] : '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $roomCode)) {
            cd_reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid room.']);
        }

        $pdo->beginTransaction();
        $room = $pdo->prepare('SELECT id, name FROM rooms WHERE room_code = :code FOR UPDATE');
        $room->execute([':code' => $roomCode]);
        $roomRow = $room->fetch();
        if (!$roomRow) {
            $pdo->rollBack();
            cd_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
        }
        $roomId = (int) $roomRow['id'];

        $bookingCount = $pdo->prepare('SELECT COUNT(*) FROM bookings WHERE room_id = :id');
        $bookingCount->execute([':id' => $roomId]);
        if ((int) $bookingCount->fetchColumn() > 0) {
            $pdo->rollBack();
            cd_reply(409, ['ok' => false, 'error' => 'booking_history',
                'message' => 'This room cannot be deleted because it has booking history.']);
        }

        /* These references should accompany a booking, but check them
           independently so malformed legacy data can never be erased. */
        $slotCount = $pdo->prepare('SELECT COUNT(*) FROM venue_booking_slots WHERE room_id = :id');
        $slotCount->execute([':id' => $roomId]);
        $bedHistory = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM bed_reservations br JOIN hostel_beds hb ON hb.id = br.bed_id WHERE hb.room_id = :room1)
                  + (SELECT COUNT(*) FROM bed_reservation_nights bn JOIN hostel_beds hb ON hb.id = bn.bed_id WHERE hb.room_id = :room2)'
        );
        $bedHistory->execute([':room1' => $roomId, ':room2' => $roomId]);
        if ((int) $slotCount->fetchColumn() > 0 || (int) $bedHistory->fetchColumn() > 0) {
            $pdo->rollBack();
            cd_reply(409, ['ok' => false, 'error' => 'booking_history',
                'message' => 'This room cannot be deleted because it has booking history.']);
        }

        /* Only room-owned configuration reaches this point. */
        $pdo->prepare('DELETE FROM maintenance_windows WHERE room_id = :id')->execute([':id' => $roomId]);
        $pdo->prepare('DELETE FROM room_media WHERE room_id = :id')->execute([':id' => $roomId]);
        $pdo->prepare('DELETE FROM event_room_details WHERE room_id = :id')->execute([':id' => $roomId]);
        $pdo->prepare('DELETE FROM hostel_room_details WHERE room_id = :id')->execute([':id' => $roomId]);
        $pdo->prepare('DELETE FROM hostel_beds WHERE room_id = :id')->execute([':id' => $roomId]);
        $deleted = $pdo->prepare('DELETE FROM rooms WHERE id = :id');
        $deleted->execute([':id' => $roomId]);
        $pdo->commit();

        cd_reply(200, ['ok' => true, 'entity' => 'room', 'name' => $roomRow['name'],
            'message' => $roomRow['name'] . ' was deleted.']);
    }

    if ($entity === 'venue') {
        $rawVenueId = isset($_POST['venue_id']) ? (string) $_POST['venue_id'] : '';
        if (!ctype_digit($rawVenueId) || (int) $rawVenueId < 1) {
            cd_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid venue.']);
        }
        $venueId = (int) $rawVenueId;

        $pdo->beginTransaction();
        $venue = $pdo->prepare('SELECT id, name FROM venues WHERE id = :id FOR UPDATE');
        $venue->execute([':id' => $venueId]);
        $venueRow = $venue->fetch();
        if (!$venueRow) {
            $pdo->rollBack();
            cd_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
        }

        $roomCount = $pdo->prepare('SELECT COUNT(*) FROM rooms WHERE venue_id = :id');
        $roomCount->execute([':id' => $venueId]);
        if ((int) $roomCount->fetchColumn() > 0) {
            $pdo->rollBack();
            cd_reply(409, ['ok' => false, 'error' => 'has_rooms',
                'message' => 'This venue cannot be deleted while it still contains rooms.']);
        }

        $businessCount = $pdo->prepare(
            'SELECT (SELECT COUNT(*) FROM gcash_accounts WHERE venue_id = :venue1)
                  + (SELECT COUNT(*) FROM staff WHERE venue_id = :venue2)'
        );
        $businessCount->execute([':venue1' => $venueId, ':venue2' => $venueId]);
        if ((int) $businessCount->fetchColumn() > 0) {
            $pdo->rollBack();
            cd_reply(409, ['ok' => false, 'error' => 'business_dependencies',
                'message' => 'This venue cannot be deleted because it has dependent business records.']);
        }

        $deleted = $pdo->prepare('DELETE FROM venues WHERE id = :id');
        $deleted->execute([':id' => $venueId]);
        $pdo->commit();

        cd_reply(200, ['ok' => true, 'entity' => 'venue', 'name' => $venueRow['name'],
            'message' => $venueRow['name'] . ' was deleted.']);
    }

    cd_reply(400, ['ok' => false, 'error' => 'bad_entity', 'message' => 'Choose a room or venue to delete.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('VENUSeP catalog-delete: ' . $e->getMessage());
    cd_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Nothing was deleted because the request could not be completed safely.']);
}
