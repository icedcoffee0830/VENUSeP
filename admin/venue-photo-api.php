<?php
/* =====================================================================
   VENUE PHOTO API — backs the "Change photo" control on venue-form.php.

   GET   action=list                     venue_id
   POST  action=upload   csrf, venue_id  file
   POST  action=delete   csrf, venue_id

   Every reply is JSON: { ok: true, cover: url|null } or
   { ok: false, error, message }. Same auth + CSRF shape as
   admin/room-photos-api.php / admin/refund-switch.php.
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/venue-photos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function vpa_reply($status, array $data) {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
  vpa_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');
$venueId = $method === 'GET' ? ($_GET['venue_id'] ?? '') : ($_POST['venue_id'] ?? '');

if (!ctype_digit((string) $venueId)) {
  vpa_reply(400, ['ok' => false, 'error' => 'bad_venue', 'message' => 'Invalid or missing venue id.']);
}
$venueId = (int) $venueId;

if ($method === 'GET') {
  if ($action !== 'list') {
    vpa_reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
  }
  vpa_reply(200, ['ok' => true, 'cover' => vp_cover_url($venueId)]);
}

if ($method !== 'POST') {
  header('Allow: GET, POST');
  vpa_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use GET or POST.']);
}

if (!csrf_valid($_POST['csrf'] ?? null)) {
  vpa_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

switch ($action) {
  case 'upload': {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      vpa_reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose a photo.']);
    }
    $err = null;
    $url = vp_save_cover($venueId, $_FILES['file']['tmp_name'], $_FILES['file']['name'], $err);
    if ($url === null) {
      vpa_reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $err ?: 'Upload failed.']);
    }
    vpa_reply(200, ['ok' => true, 'cover' => $url]);
  }

  case 'delete': {
    vp_delete_cover($venueId);
    vpa_reply(200, ['ok' => true, 'cover' => null]);
  }

  default:
    vpa_reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
}
