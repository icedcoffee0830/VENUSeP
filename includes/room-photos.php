<?php
/* =====================================================================
   ROOM PHOTOS — filesystem-backed storage for room galleries + 360°
   panoramas, keyed by the room's existing string id (r1, h1, ...).

   WHY FILESYSTEM, NOT THE DB: rooms themselves are not database-backed
   anywhere in this app yet (see includes/venue-rooms.php / hostel-rooms.php
   — [SIM] hard-coded arrays). Bolting room_media onto a DB row that does
   not exist would be a fiction; this instead extends the ONE thing that
   already works today — assets/img/venues/<id>.<ext> — into a real
   multi-photo gallery + panorama, still keyed by the same room id.

   Layout, per room id:
     assets/img/venues/rooms/<id>/photos/<file>   gallery images
     assets/img/venues/rooms/<id>/photos/order.json   ["file1.jpg", ...] — index 0 = cover
     assets/img/venues/rooms/<id>/pano.<ext>          the one 360° photo (if any)

   Every function here validates the room id against ROOM_ID_RE so a path
   like "../../etc" can never be built from user input.
   ===================================================================== */

define('ROOM_PHOTO_BASE', __DIR__ . '/../assets/img/venues/rooms');
define('ROOM_PHOTO_WEB_BASE', '../assets/img/venues/rooms');   // relative from admin/ and customer/ (both one level deep)
define('ROOM_ID_RE', '/^[a-zA-Z0-9_-]{1,40}$/');
define('ROOM_PHOTO_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp']);
define('ROOM_PHOTO_MAX_BYTES', 8 * 1024 * 1024);   // 8MB per file

function rp_room_id_valid($id) {
  return is_string($id) && preg_match(ROOM_ID_RE, $id) === 1;
}

function rp_photos_dir($id) {
  return ROOM_PHOTO_BASE . '/' . $id . '/photos';
}

function rp_room_dir($id) {
  return ROOM_PHOTO_BASE . '/' . $id;
}

function rp_order_file($id) {
  return rp_photos_dir($id) . '/order.json';
}

/* Validate an uploaded (or any local) image file: real image, allowed
   extension, under the size cap. Returns the lowercase extension on
   success, or null + $error set on failure. */
function rp_validate_image($tmpPath, $originalName, &$error) {
  if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
    $error = 'Upload failed.';
    return null;
  }
  $size = filesize($tmpPath);
  if ($size === false || $size <= 0) {
    $error = 'The file is empty.';
    return null;
  }
  if ($size > ROOM_PHOTO_MAX_BYTES) {
    $error = 'That file is larger than 8MB.';
    return null;
  }
  $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
  if (!in_array($ext, ROOM_PHOTO_ALLOWED_EXT, true)) {
    $error = 'Only JPG, PNG, and WEBP photos are allowed.';
    return null;
  }
  $info = @getimagesize($tmpPath);
  if ($info === false) {
    $error = 'That file is not a valid image.';
    return null;
  }
  $okMime = ['image/jpeg', 'image/png', 'image/webp'];
  if (!in_array($info['mime'], $okMime, true)) {
    $error = 'That file is not a valid image.';
    return null;
  }
  return $ext === 'jpeg' ? 'jpg' : $ext;
}

/* ---------------------------------------------------------------------
   Gallery photos
   --------------------------------------------------------------------- */

/* Ordered list of gallery photos for a room. Index 0 = cover. Each entry:
   ['file' => 'p_xxx.jpg', 'url' => '../assets/img/venues/rooms/r1/photos/p_xxx.jpg'] */
function rp_list_photos($id) {
  if (!rp_room_id_valid($id)) return [];
  $dir = rp_photos_dir($id);
  if (!is_dir($dir)) return [];

  $orderFile = rp_order_file($id);
  $order = [];
  if (is_file($orderFile)) {
    $decoded = json_decode(file_get_contents($orderFile), true);
    if (is_array($decoded)) $order = $decoded;
  }

  // Reconcile the stored order against what's actually on disk: drop
  // filenames that no longer exist, then append any real files the
  // order list doesn't know about yet (belt-and-suspenders).
  $onDisk = [];
  foreach (scandir($dir) as $f) {
    if ($f === '.' || $f === '..' || $f === 'order.json') continue;
    $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
    if (in_array($ext, ROOM_PHOTO_ALLOWED_EXT, true)) $onDisk[$f] = true;
  }
  $result = [];
  foreach ($order as $f) {
    if (isset($onDisk[$f])) { $result[] = $f; unset($onDisk[$f]); }
  }
  foreach (array_keys($onDisk) as $f) $result[] = $f;

  return array_map(function ($f) use ($id) {
    return ['file' => $f, 'url' => ROOM_PHOTO_WEB_BASE . '/' . $id . '/photos/' . $f];
  }, $result);
}

function rp_save_order($id, array $files) {
  if (!rp_room_id_valid($id)) return false;
  $dir = rp_photos_dir($id);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  return file_put_contents(rp_order_file($id), json_encode(array_values($files))) !== false;
}

/* Add one photo from a tmp upload path. Returns the new filename, or
   null + $error set. New photos are appended (not made cover) unless
   the gallery was empty. */
function rp_add_photo($id, $tmpPath, $originalName, &$error) {
  if (!rp_room_id_valid($id)) { $error = 'Invalid room.'; return null; }
  $ext = rp_validate_image($tmpPath, $originalName, $error);
  if ($ext === null) return null;

  $dir = rp_photos_dir($id);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $error = 'Could not create the upload folder.'; return null; }

  $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
  $dest = $dir . '/' . $filename;

  $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
  if (!$moved) { $error = 'Could not save the uploaded file.'; return null; }
  @chmod($dest, 0644);

  $order = array_column(rp_list_photos($id), 'file');
  // rp_list_photos() above already reconciles disk state, but the file we
  // just wrote may already be picked up by the disk scan; only append if
  // it somehow isn't there (paranoia, not the expected path).
  if (!in_array($filename, $order, true)) $order[] = $filename;
  rp_save_order($id, $order);

  return $filename;
}

function rp_delete_photo($id, $filename) {
  if (!rp_room_id_valid($id)) return false;
  $filename = basename($filename);   // no path traversal
  $path = rp_photos_dir($id) . '/' . $filename;
  if (is_file($path)) @unlink($path);
  $order = array_values(array_filter(array_column(rp_list_photos($id), 'file'), function ($f) use ($filename) {
    return $f !== $filename;
  }));
  return rp_save_order($id, $order);
}

/* Reorder the gallery to match $files (filenames in the new order).
   Index 0 becomes the cover. Unknown filenames are ignored. */
function rp_reorder_photos($id, array $files) {
  if (!rp_room_id_valid($id)) return false;
  $existing = array_column(rp_list_photos($id), 'file');
  $existingSet = array_flip($existing);
  $clean = [];
  foreach ($files as $f) {
    $f = basename($f);
    if (isset($existingSet[$f]) && !in_array($f, $clean, true)) $clean[] = $f;
  }
  // Anything missing from $files (shouldn't happen) stays, appended, so
  // nothing silently disappears from a partial reorder call.
  foreach ($existing as $f) if (!in_array($f, $clean, true)) $clean[] = $f;
  return rp_save_order($id, $clean);
}

/* ---------------------------------------------------------------------
   360° panorama — one per room
   --------------------------------------------------------------------- */

function rp_pano_url($id) {
  if (!rp_room_id_valid($id)) return null;
  $dir = rp_room_dir($id);
  if (!is_dir($dir)) return null;
  foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
    if (is_file($dir . '/pano.' . $ext)) return ROOM_PHOTO_WEB_BASE . '/' . $id . '/pano.' . $ext;
  }
  return null;
}

function rp_save_pano($id, $tmpPath, $originalName, &$error) {
  if (!rp_room_id_valid($id)) { $error = 'Invalid room.'; return null; }
  $ext = rp_validate_image($tmpPath, $originalName, $error);
  if ($ext === null) return null;

  $dir = rp_room_dir($id);
  if (!is_dir($dir) && !@mkdir($dir, 0775, true)) { $error = 'Could not create the upload folder.'; return null; }

  rp_delete_pano($id);   // only one panorama per room
  $dest = $dir . '/pano.' . $ext;
  $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
  if (!$moved) { $error = 'Could not save the uploaded file.'; return null; }
  @chmod($dest, 0644);

  return ROOM_PHOTO_WEB_BASE . '/' . $id . '/pano.' . $ext;
}

function rp_delete_pano($id) {
  if (!rp_room_id_valid($id)) return false;
  $dir = rp_room_dir($id);
  foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
    $p = $dir . '/pano.' . $ext;
    if (is_file($p)) @unlink($p);
  }
  return true;
}

/* ---------------------------------------------------------------------
   Customer-facing reads
   --------------------------------------------------------------------- */

/* All gallery photo URLs, in cover-first order. */
function rp_gallery_urls($id) {
  return array_column(rp_list_photos($id), 'url');
}

/* The single cover image for a room: the first uploaded gallery photo,
   falling back to the old assets/img/venues/<id>.<ext> convention (so
   any photo already dropped there manually keeps working), else null. */
function rp_cover_url($id) {
  $photos = rp_gallery_urls($id);
  if ($photos) return $photos[0];
  if (!rp_room_id_valid($id)) return null;
  foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
    if (file_exists(__DIR__ . '/../assets/img/venues/' . $id . '.' . $ext)) {
      return '../assets/img/venues/' . $id . '.' . $ext;
    }
  }
  return null;
}
