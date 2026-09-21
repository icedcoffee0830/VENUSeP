<?php
/* =====================================================================
   VENUE SAVE — updates venues.name + venues.description, called by
   "Save Changes" on venue-form.php. Same shape as admin/room-save.php.

   POST  csrf, venue_id, name, description
   Replies with JSON.

   Assigned Staff on venue-form.php is still [SIM]. Real venue assignments
   are managed in Staff Management through staff.venue_id; this endpoint
   only saves the venue itself.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vsv_reply($status, array $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    vsv_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use POST.']);
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
    vsv_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}
if (!csrf_valid(isset($_POST['csrf']) ? $_POST['csrf'] : null)) {
    vsv_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

$venueId = isset($_POST['venue_id']) ? $_POST['venue_id'] : '';
$name = trim(isset($_POST['name']) ? $_POST['name'] : '');
$description = trim(isset($_POST['description']) ? $_POST['description'] : '');

/* venue_id 0 (or absent) means CREATE. The Add Venue screen has always
   existed — it even told the admin "photos can be added once the venue is
   saved and has an id" — but nothing behind it ever inserted a row, so the
   screen was a facade. */
$isNew = ($venueId === '' || $venueId === '0');
if (!$isNew && !ctype_digit((string) $venueId)) {
    vsv_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid venue.']);
}
$venueId = (int) $venueId;
if ($name === '' || mb_strlen($name) > 150) {
    vsv_reply(400, ['ok' => false, 'error' => 'bad_name', 'message' => 'Enter a venue name (up to 150 characters).']);
}

$pdo = venusep_db();
if ($pdo === null) {
    vsv_reply(503, ['ok' => false, 'error' => 'db', 'message' => 'The database is unreachable, so the venue was not saved.']);
}

try {
    if ($isNew) {
        /* venue_type decides what rooms this venue can hold: an 'event' venue
           takes event rooms, a 'hostel' venue hostel rooms. It is fixed at
           creation because changing it later would orphan every room already
           filed under it. */
        $venueType = ($_POST['venue_type'] ?? '') === 'hostel' ? 'hostel' : 'event';

        /* venue_code is UNIQUE and is what a human reads in a URL or an export,
           so it is derived from the name rather than being a bare number:
           "Bahay Alumni" -> BAHAY-ALUMNI. A collision gets a numeric suffix
           instead of failing — the admin never chose this value and should not
           have to resolve a clash in it. */
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $name));
        $base = trim(preg_replace('/-+/', '-', $base), '-');
        if ($base === '') { $base = 'VENUE'; }
        $base = substr($base, 0, 26);
        $code = $base;
        $exists = $pdo->prepare('SELECT 1 FROM venues WHERE venue_code = :c LIMIT 1');
        for ($i = 2; $i < 100; $i++) {
            $exists->execute([':c' => $code]);
            if ($exists->fetchColumn() === false) { break; }
            $code = $base . '-' . $i;
        }

        $pdo->prepare(
            'INSERT INTO venues (venue_code, name, venue_type, description) VALUES (:c, :n, :t, :d)'
        )->execute([':c' => $code, ':n' => $name, ':t' => $venueType, ':d' => $description]);
        $newId = (int) $pdo->lastInsertId();

        vsv_reply(200, ['ok' => true, 'created' => true, 'venue_id' => $newId,
            'code' => $code, 'name' => $name, 'description' => $description,
            'message' => 'Venue created. You can add its photo and rooms now.']);
    }

    $venue = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
    $venue->execute([':id' => $venueId]);
    if (!$venue->fetch()) {
        vsv_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
    }

    $pdo->prepare('UPDATE venues SET name = :name, description = :description WHERE id = :id')
        ->execute([':name' => $name, ':description' => $description, ':id' => $venueId]);

    vsv_reply(200, ['ok' => true, 'created' => false, 'name' => $name, 'description' => $description]);
} catch (PDOException $e) {
    /* uq_venues_name — two venues sharing a name would make every "Location"
       dropdown ambiguous, which is exactly what the constraint is for. */
    if ($e->getCode() === '23000') {
        vsv_reply(409, ['ok' => false, 'error' => 'duplicate',
            'message' => 'A venue with that name already exists.']);
    }
    error_log('VENUSeP venue-save: ' . $e->getMessage());
    vsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
} catch (Throwable $e) {
    error_log('VENUSeP venue-save: ' . $e->getMessage());
    vsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
}
