<?php
/* =====================================================================
   VENUE PHOTOS — one cover photo per venue (venues.cover_photo), unlike
   a room's gallery + 360°. The "Edit Venue" form only ever showed a
   single image + "Change photo" button, so this matches that shape
   instead of reusing room_media (which is keyed to a room, not a venue,
   and supports a gallery this UI never asked for).

   Reuses rp_validate_image() from includes/room-photos.php — same image
   rules (real image, allowed extension, 8MB cap), one file at a time.
   ===================================================================== */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/room-photos.php';

define('VENUE_PHOTO_DIR', __DIR__ . '/../assets/img/venues/covers');
define('VENUE_PHOTO_WEB_BASE', '../assets/img/venues/covers');   // relative from admin/ and customer/ (both one level deep)

function vp_ensure_dir() {
  if (is_dir(VENUE_PHOTO_DIR)) return true;
  if (!@mkdir(VENUE_PHOTO_DIR, 0775, true) && !is_dir(VENUE_PHOTO_DIR)) return false;
  @chmod(VENUE_PHOTO_DIR, 0775);
  return true;
}

function vp_venue_row($venueId) {
  $pdo = venusep_db();
  if ($pdo === null) return null;
  $stmt = $pdo->prepare('SELECT id, name, description, cover_photo FROM venues WHERE id = :id');
  $stmt->execute([':id' => (int) $venueId]);
  $row = $stmt->fetch();
  return $row ?: null;
}

/* The venue's cover photo, or null (draw the placeholder instead). */
function vp_cover_url($venueId) {
  $row = vp_venue_row($venueId);
  return ($row && $row['cover_photo']) ? $row['cover_photo'] : null;
}

/* Same lookup, keyed by venue NAME — the customer landing page groups
   rooms by venue name, not id, so this saves it a round trip. */
function vp_cover_url_by_name($name) {
  $pdo = venusep_db();
  if ($pdo === null) return null;
  $stmt = $pdo->prepare('SELECT cover_photo FROM venues WHERE name = :name');
  $stmt->execute([':name' => $name]);
  $path = $stmt->fetchColumn();
  return ($path !== false && $path) ? $path : null;
}

function vp_delete_cover_file($venueId) {
  foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
    $p = VENUE_PHOTO_DIR . '/' . (int) $venueId . '.' . $ext;
    if (is_file($p)) @unlink($p);
  }
}

function vp_save_cover($venueId, $tmpPath, $originalName, &$error) {
  $venueId = (int) $venueId;
  if (vp_venue_row($venueId) === null) { $error = 'Venue not found.'; return null; }
  $pdo = venusep_db();
  if ($pdo === null) { $error = 'The database is unreachable, so the photo was not saved.'; return null; }

  $ext = rp_validate_image($tmpPath, $originalName, $error);
  if ($ext === null) return null;

  if (!vp_ensure_dir()) { $error = 'Could not create the upload folder.'; return null; }

  vp_delete_cover_file($venueId);   // one photo per venue; old extension may differ from the new one
  $dest = VENUE_PHOTO_DIR . '/' . $venueId . '.' . $ext;
  $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
  if (!$moved) { $error = 'Could not save the uploaded file.'; return null; }
  @chmod($dest, 0644);

  $url = VENUE_PHOTO_WEB_BASE . '/' . $venueId . '.' . $ext;
  $pdo->prepare('UPDATE venues SET cover_photo = :p WHERE id = :id')->execute([':p' => $url, ':id' => $venueId]);
  return $url;
}

function vp_delete_cover($venueId) {
  $venueId = (int) $venueId;
  $pdo = venusep_db();
  if ($pdo === null) return false;
  vp_delete_cover_file($venueId);
  $pdo->prepare('UPDATE venues SET cover_photo = NULL WHERE id = :id')->execute([':id' => $venueId]);
  return true;
}
