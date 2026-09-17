<?php
/* =====================================================================
   ROOM PHOTOS API — backs the "Edit photos" / "Add / replace 360°"
   controls on room-form.php and hostel-room-form.php.

   GET   action=list                          room_id
   POST  action=upload        csrf, room_id   files[] (one or more)
   POST  action=delete        csrf, room_id   file
   POST  action=reorder       csrf, room_id   order[] (filenames, new order)
   POST  action=upload_pano   csrf, room_id   file
   POST  action=delete_pano   csrf, room_id

   Every reply is JSON: { ok: true, photos: [...], pano: url|null } or
   { ok: false, error, message }. Same auth + CSRF shape as
   admin/refund-switch.php: logged-in admin/staff session, CSRF on every
   state-changing call. Listing is read-only, so it skips CSRF (GET
   requests never carry it here anyway).
   ===================================================================== */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/room-photos.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function rpa_reply($status, array $data) {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function rpa_state($roomId) {
  return ['photos' => rp_list_photos($roomId), 'pano' => rp_pano_url($roomId)];
}

venusep_session_start();
$sessionType = isset($_SESSION['account_type']) ? $_SESSION['account_type'] : null;
if (!isset($_SESSION['user_id']) || !in_array($sessionType, ['admin', 'staff'], true)) {
  rpa_reply(401, ['ok' => false, 'error' => 'not_logged_in', 'message' => 'Your session has ended. Log in again.']);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($_POST['action'] ?? '');
$roomId = $method === 'GET' ? ($_GET['room_id'] ?? '') : ($_POST['room_id'] ?? '');

if (!rp_room_id_valid($roomId)) {
  rpa_reply(400, ['ok' => false, 'error' => 'bad_room', 'message' => 'Invalid or missing room id.']);
}

if ($method === 'GET') {
  if ($action !== 'list') {
    rpa_reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
  }
  rpa_reply(200, array_merge(['ok' => true], rpa_state($roomId)));
}

if ($method !== 'POST') {
  header('Allow: GET, POST');
  rpa_reply(405, ['ok' => false, 'error' => 'method', 'message' => 'Use GET or POST.']);
}

if (!csrf_valid($_POST['csrf'] ?? null)) {
  rpa_reply(400, ['ok' => false, 'error' => 'csrf', 'message' => 'This page has expired. Reload it and try again.']);
}

switch ($action) {
  case 'upload': {
    if (empty($_FILES['files'])) {
      rpa_reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose at least one photo.']);
    }
    $names = (array) $_FILES['files']['name'];
    $tmps  = (array) $_FILES['files']['tmp_name'];
    $errs  = (array) $_FILES['files']['error'];
    $firstError = null;
    $added = 0;
    foreach ($names as $i => $originalName) {
      if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { $firstError = $firstError ?: 'One of the files failed to upload.'; continue; }
      $err = null;
      $ok = rp_add_photo($roomId, $tmps[$i], $originalName, $err);
      if ($ok === null) { $firstError = $firstError ?: $err; continue; }
      $added++;
    }
    if ($added === 0) {
      rpa_reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $firstError ?: 'Nothing was uploaded.']);
    }
    rpa_reply(200, array_merge(['ok' => true, 'warning' => $firstError], rpa_state($roomId)));
  }

  case 'delete': {
    $file = $_POST['file'] ?? '';
    if (!is_string($file) || $file === '') {
      rpa_reply(400, ['ok' => false, 'error' => 'bad_file', 'message' => 'Missing file.']);
    }
    rp_delete_photo($roomId, $file);
    rpa_reply(200, array_merge(['ok' => true], rpa_state($roomId)));
  }

  case 'reorder': {
    $order = $_POST['order'] ?? null;
    if (!is_array($order)) {
      rpa_reply(400, ['ok' => false, 'error' => 'bad_order', 'message' => 'Missing order.']);
    }
    rp_reorder_photos($roomId, $order);
    rpa_reply(200, array_merge(['ok' => true], rpa_state($roomId)));
  }

  case 'upload_pano': {
    if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      rpa_reply(400, ['ok' => false, 'error' => 'no_file', 'message' => 'Choose a 360° photo.']);
    }
    $err = null;
    $url = rp_save_pano($roomId, $_FILES['file']['tmp_name'], $_FILES['file']['name'], $err);
    if ($url === null) {
      rpa_reply(400, ['ok' => false, 'error' => 'upload_failed', 'message' => $err ?: 'Upload failed.']);
    }
    rpa_reply(200, array_merge(['ok' => true], rpa_state($roomId)));
  }

  case 'delete_pano': {
    rp_delete_pano($roomId);
    rpa_reply(200, array_merge(['ok' => true], rpa_state($roomId)));
  }

  default:
    rpa_reply(400, ['ok' => false, 'error' => 'bad_action', 'message' => 'Unknown action.']);
}
