<?php
/* =====================================================================
   ROOM SAVE — creates or updates a room, called by "Save Changes" on
   room-form.php and hostel-room-form.php.

   POST  csrf, room_id (room_code, e.g. "r1"; EMPTY = create), name,
         venue (venue name), room_type ('event' | 'hostel')
   creating also: description, and per type —
         event:  capacity, fee
         hostel: beds, rate, cr_type
   Replies with JSON.

   ROOM CODES ARE GENERATED, NEVER TYPED. room_code is the photo folder
   name (assets/img/venues/rooms/<code>/), the ?room= URL parameter and
   the key every page joins on, so an admin inventing one could collide
   with a directory already on disk. Creation continues the existing
   series: r9, h6.

   A NEW HOSTEL ROOM GETS ITS BEDS. Availability is counted from
   bed_reservation_nights joined to hostel_beds, so a hostel room with no
   bed rows can never be booked — it reads as permanently full. Creating
   the beds is not optional garnish; it is what makes the room exist.

   room_type is checked against the room's actual rooms.room_type on an
   update, and against the VENUE's type on a create, so neither form can
   be pointed at the wrong thing by tampering with the request — same
   defensive shape as admin/refund-switch.php re-checking the account.

   Still not wired here: maintenance windows and amenities. Those are
   items 8 and the maintenance editor, respectively.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/amenities.php';   /* the vocabulary — amenity_keys_clean() */

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

/* An empty room_id means CREATE. The Add Room screen has existed all along;
   until now it answered with an alert saying it could not actually create one. */
$isNewRoom = !isset($_POST['room_id']) || trim((string) $_POST['room_id']) === '';
$roomCode = isset($_POST['room_id']) ? $_POST['room_id'] : '';
$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
$venueName = trim(isset($_POST['venue']) ? $_POST['venue'] : '');
$roomType = isset($_POST['room_type']) ? $_POST['room_type'] : '';

if (!$isNewRoom && (!is_string($roomCode) || !preg_match('/^[a-zA-Z0-9_-]{1,40}$/', $roomCode))) {
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

/* WHICH AMENITIES THIS ROOM HAS, as vocabulary KEYS.
   amenity_keys_clean() drops anything the vocabulary does not know, so a
   tampered post cannot put junk in the column — and a key retired from the
   vocabulary later simply stops being offered rather than breaking a room
   that still references it.

   `amenities` absent entirely = "this form did not ask", leave the column
   alone. An EMPTY list is a real answer: the admin unticked everything.
   Those two must not collapse into each other, or saving a form that has no
   amenity picker would silently wipe the room's amenities. */
$amenitiesGiven = array_key_exists('amenities', $_POST);
$amenityKeys = [];
if ($amenitiesGiven) {
    $decoded = json_decode((string) $_POST['amenities'], true);
    $amenityKeys = amenity_keys_clean(is_array($decoded) ? $decoded : []);

    /* Keep only the groups this ROOM TYPE can use. The picker already filters —
       an event room is never offered "6 bunk beds" — but the picker is a
       convenience, not a boundary, and a tampered post otherwise put hostel
       amenities on a ballroom. Cosmetic rather than dangerous, but the whole
       point of re-checking server-side is not having to judge which is which. */
    $allowedGroups = $roomType === 'hostel' ? $AMENITY_GROUPS_HOSTEL : $AMENITY_GROUPS_EVENT;
    $allowedKeys = [];
    foreach ($allowedGroups as $g) {
        foreach ($AMENITY_GROUPS[$g] as $k) { $allowedKeys[$k] = true; }
    }
    $amenityKeys = array_values(array_filter($amenityKeys, function ($k) use ($allowedKeys) {
        return isset($allowedKeys[$k]);
    }));
}

$pdo = venusep_db();
if ($pdo === null) {
    rsv_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the room was not saved.']);
}

try {
    $venue = $pdo->prepare('SELECT id, venue_type FROM venues WHERE name = :name');
    $venue->execute([':name' => $venueName]);
    $venueRow = $venue->fetch();
    if (!$venueRow) {
        rsv_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Unknown location.']);
    }

    if ($isNewRoom) {
        /* The room must suit the venue: an 'event' venue holds event rooms and a
           'hostel' venue hostel rooms. trg_rooms_type_ins enforces the same rule
           at the database, but catching it here gives the admin a sentence
           instead of a constraint violation. */
        $wantVenueType = $roomType === 'hostel' ? 'hostel' : 'event';
        if ($venueRow['venue_type'] !== $wantVenueType) {
            rsv_reply(409, ['ok' => false, 'error' => 'type_mismatch',
                'message' => $roomType === 'hostel'
                    ? 'Hostel rooms can only be added to a hostel venue.'
                    : 'Event rooms can only be added to an event venue.']);
        }

        /* room_code is load-bearing: it is the photo folder name
           (assets/img/venues/rooms/<code>/), the ?room= URL parameter, and the
           key every page joins on. So it is GENERATED, never typed — "r9",
           "h6" — continuing the existing series rather than letting an admin
           invent something that collides with a folder on disk.
           The prefix follows the TYPE, not the venue, because that is what the
           series has always meant. */
        $prefix = $roomType === 'hostel' ? 'h' : 'r';
        $next = $pdo->prepare(
            "SELECT COALESCE(MAX(CAST(SUBSTRING(room_code, 2) AS UNSIGNED)), 0) + 1
               FROM rooms
              WHERE room_code REGEXP CONCAT('^', :p, '[0-9]+$')"
        );
        $next->execute([':p' => $prefix]);
        $newCode = $prefix . (int) $next->fetchColumn();

        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO rooms (venue_id, room_code, name, room_type, description, amenities)
             VALUES (:v, :c, :n, :t, :d, :a)'
        )->execute([
            ':v' => $venueRow['id'], ':c' => $newCode, ':n' => $name, ':t' => $roomType,
            ':d' => mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 2000) ?: null,
            ':a' => json_encode($amenityKeys, JSON_UNESCAPED_SLASHES),
        ]);
        $newId = (int) $pdo->lastInsertId();

        if ($roomType === 'hostel') {
            /* A hostel room is booked PER BED, and availability is counted from
               bed_reservation_nights joined to hostel_beds. A hostel room with
               no bed rows can therefore never be booked at all — it would show
               as permanently full. The beds ARE the room's capacity. */
            $beds = (int) ($_POST['beds'] ?? 6);
            $beds = max(1, min(20, $beds));
            $rate = (float) ($_POST['rate'] ?? 0);
            $crType = ($_POST['cr_type'] ?? '') === 'private' ? 'private' : 'communal';

            $pdo->prepare(
                'INSERT INTO hostel_room_details (room_id, cr_type, rate_per_head_per_night)
                 VALUES (:r, :c, :p)'
            )->execute([':r' => $newId, ':c' => $crType, ':p' => $rate]);

            $bed = $pdo->prepare(
                'INSERT INTO hostel_beds (room_id, bed_code, bed_label, display_order)
                 VALUES (:r, :c, :l, :o)'
            );
            for ($i = 1; $i <= $beds; $i++) {
                $bed->execute([':r' => $newId, ':c' => $newCode . '-b' . $i, ':l' => 'Bed ' . $i, ':o' => $i]);
            }
        } else {
            $pdo->prepare(
                'INSERT INTO event_room_details (room_id, attendee_capacity, fee_per_day)
                 VALUES (:r, :c, :f)'
            )->execute([
                ':r' => $newId,
                ':c' => max(1, (int) ($_POST['capacity'] ?? 1)),
                ':f' => max(0, (float) ($_POST['fee'] ?? 0)),
            ]);
        }
        $pdo->commit();

        rsv_reply(200, ['ok' => true, 'created' => true, 'room_id' => $newCode,
            'name' => $name, 'venue' => $venueName,
            'message' => 'Room created as ' . $newCode . '. You can add its photos now.']);
    }

    $room = $pdo->prepare('SELECT id, room_type FROM rooms WHERE room_code = :code');
    $room->execute([':code' => $roomCode]);
    $roomRow = $room->fetch();
    if (!$roomRow) {
        rsv_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Room not found.']);
    }
    if ($roomRow['room_type'] !== $roomType) {
        rsv_reply(409, ['ok' => false, 'error' => 'wrong_type', 'message' => 'This room does not match the form that submitted it.']);
    }

    /* The venue was already resolved above — one lookup serves both paths. */
    $venueId = $venueRow['id'];

    /* amenities only when the form actually sent them — see $amenitiesGiven. */
    if ($amenitiesGiven) {
        $pdo->prepare('UPDATE rooms SET name = :name, venue_id = :venue_id, amenities = :a WHERE id = :id')
            ->execute([':name' => $name, ':venue_id' => $venueId,
                       ':a' => json_encode($amenityKeys, JSON_UNESCAPED_SLASHES), ':id' => $roomRow['id']]);
    } else {
        $pdo->prepare('UPDATE rooms SET name = :name, venue_id = :venue_id WHERE id = :id')
            ->execute([':name' => $name, ':venue_id' => $venueId, ':id' => $roomRow['id']]);
    }

    rsv_reply(200, ['ok' => true, 'name' => $name, 'venue' => $venueName]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    /* uq_rooms_venue_name: two rooms with the same name in one venue would be
       indistinguishable in every dropdown and every booking reference. */
    if ($e->getCode() === '23000') {
        rsv_reply(409, ['ok' => false, 'error' => 'duplicate',
            'message' => 'That location already has a room with this name.']);
    }
    error_log('VENUSeP room-save: ' . $e->getMessage());
    rsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP room-save: ' . $e->getMessage());
    rsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the room was not saved.']);
}
