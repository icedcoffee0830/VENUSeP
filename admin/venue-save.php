<?php
/* =====================================================================
   VENUE SAVE — updates venues.name + venues.description, called by
   "Save Changes" on venue-form.php. Same shape as admin/room-save.php.

   POST  csrf, venue_id, name, description[, move_staff = JSON [user ids]]
   Replies with JSON.

   Assigned Staff (2026-10-27): move_staff moves those staff accounts to
   this venue (staff.venue_id), admin only. Staff Management sets the same
   column one person at a time.
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

/* ASSIGNED STAFF (2026-10-27): staff to MOVE to this venue. Every staff member
   handles exactly one venue, so this only ever moves people HERE. Nobody can
   be taken off into "no venue" (trg_staff_venue_required_upd refuses that
   too). Which venue someone handles decides whose bookings they may act on
   (staff_may_handle_venue()), so only an admin may change it. */
$moveRaw = json_decode((string) ($_POST['move_staff'] ?? '[]'), true);
if (!is_array($moveRaw)) {
    vsv_reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'Invalid staff selection.']);
}
$moveIds = [];
foreach ($moveRaw as $m) {
    if (!is_scalar($m) || !ctype_digit((string) $m)) {
        vsv_reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'Invalid staff selection.']);
    }
    $moveIds[] = (int) $m;
}
$moveIds = array_values(array_unique($moveIds));
if ($moveIds && !admin_is_admin()) {
    vsv_reply(403, ['ok' => false, 'error' => 'forbidden', 'message' => 'Only an administrator can change which venue a staff member handles.']);
}

/* Move the chosen staff accounts here; returns their names. Every id must be
   a STAFF account, never an admin's or a customer's row. */
function vsv_move_staff(PDO $pdo, $venueId, array $ids, array &$movedEmails = []) {
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $rows = $pdo->query(
        "SELECT s.user_id, s.full_name, s.venue_id, u.email FROM staff s JOIN users u ON u.id = s.user_id
          WHERE u.account_type = 'staff' AND s.user_id IN ($in) ORDER BY s.full_name"
    )->fetchAll();
    if (count($rows) !== count($ids)) {
        throw new InvalidArgumentException('not_staff');
    }
    $pdo->prepare("UPDATE staff SET venue_id = :v WHERE user_id IN ($in)")->execute([':v' => (int) $venueId]);
    /* who actually changed venue — for the admins' notifications */
    foreach ($rows as $r) {
        if ((int) $r['venue_id'] !== (int) $venueId) {
            $movedEmails[] = $r['email'];
        }
    }
    return array_column($rows, 'full_name');
}

/* Tell the other admins who was moved (2026-10-28). A failure costs a
   notification, never the save. */
function vsv_notify_moves(PDO $pdo, array $emails, $venueName) {
    if (!$emails) {
        return;
    }
    require_once __DIR__ . '/../includes/notifications.php';
    try {
        foreach ($emails as $email) {
            notify_admins($pdo, 'staff_moved', (int) $_SESSION['user_id'], ['email' => $email, 'venue' => $venueName]);
        }
    } catch (PDOException $e) {
        error_log('VENUSeP venue-save (notify): ' . $e->getMessage());
    }
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

        $pdo->beginTransaction();
        $pdo->prepare(
            'INSERT INTO venues (venue_code, name, venue_type, description) VALUES (:c, :n, :t, :d)'
        )->execute([':c' => $code, ':n' => $name, ':t' => $venueType, ':d' => $description]);
        $newId = (int) $pdo->lastInsertId();
        $movedEmails = [];
        $moved = vsv_move_staff($pdo, $newId, $moveIds, $movedEmails);
        $pdo->commit();
        vsv_notify_moves($pdo, $movedEmails, $name);

        vsv_reply(200, ['ok' => true, 'created' => true, 'venue_id' => $newId,
            'code' => $code, 'name' => $name, 'description' => $description, 'moved' => $moved,
            'message' => 'Venue created. You can add its photo and rooms now.']);
    }

    $venue = $pdo->prepare('SELECT id FROM venues WHERE id = :id');
    $venue->execute([':id' => $venueId]);
    if (!$venue->fetch()) {
        vsv_reply(404, ['ok' => false, 'error' => 'not_found', 'message' => 'Venue not found.']);
    }

    $pdo->beginTransaction();
    $pdo->prepare('UPDATE venues SET name = :name, description = :description WHERE id = :id')
        ->execute([':name' => $name, ':description' => $description, ':id' => $venueId]);
    $movedEmails = [];
    $moved = vsv_move_staff($pdo, $venueId, $moveIds, $movedEmails);
    $pdo->commit();
    vsv_notify_moves($pdo, $movedEmails, $name);

    vsv_reply(200, ['ok' => true, 'created' => false, 'name' => $name, 'description' => $description, 'moved' => $moved]);
} catch (InvalidArgumentException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    vsv_reply(400, ['ok' => false, 'error' => 'bad_staff', 'message' => 'One of the selected people is not a staff account, so nothing was saved.']);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    /* uq_venues_name — two venues sharing a name would make every "Location"
       dropdown ambiguous, which is exactly what the constraint is for. */
    if ($e->getCode() === '23000') {
        vsv_reply(409, ['ok' => false, 'error' => 'duplicate',
            'message' => 'A venue with that name already exists.']);
    }
    error_log('VENUSeP venue-save: ' . $e->getMessage());
    vsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    error_log('VENUSeP venue-save: ' . $e->getMessage());
    vsv_reply(500, ['ok' => false, 'error' => 'server', 'message' => 'Something went wrong, so the venue was not saved.']);
}
