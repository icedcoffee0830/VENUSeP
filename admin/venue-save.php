<?php
/* =====================================================================
   VENUE SAVE — updates venues.name + venues.description, called by
   "Save Changes" on venue-form.php. Same shape as admin/room-save.php.

   POST  csrf, venue_id, name, description
   Replies with JSON.

   Assigned Staff on this form is still [SIM] — no staff-to-venue table
   exists in the schema yet, so wiring it would mean inventing one, out
   of scope for "make the photo upload work".
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

if (!ctype_digit((string) $venueId)) {
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
    $venue = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
    $venue->execute([':id' => $venueId]);
    if (!$venue->fetch()) {
        vsv_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
    }

    $pdo->prepare('UPDATE venues SET name = :name, description = :description WHERE id = :id')
        ->execute([':name' => $name, ':description' => $description, ':id' => $venueId]);

    vsv_reply(200, ['ok' => true, 'name' => $name, 'description' => $description]);
} catch (Throwable $e) {
    vsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
}
