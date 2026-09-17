<?php
/* =====================================================================
   ROOM PHOTOS — room_media-backed gallery + 360° panorama storage,
   keyed by the room's existing string id / room_code (r1, h1, ...).

   The image BYTES still live on disk (the `room_media.file_path` column
   is a path, not a blob — same as the schema's own comment intends):
     assets/img/venues/rooms/<id>/photos/<file>   gallery images
     assets/img/venues/rooms/<id>/pano.<ext>      the one 360° photo (if any)
   Everything about WHICH photos exist, their order, and the cover is now
   the `room_media` table (room_id FK -> rooms.id, looked up by room_code)
   — not a JSON sidecar file. Writes fail loud if the DB is unreachable
   (mirrors admin/refund-switch.php); reads fail safe (empty/null), so a
   DB hiccup degrades a customer's room photos to placeholders instead of
   a fatal error.

   Every function here validates the room id against ROOM_ID_RE so a path
   like "../../etc" can never be built from user input.
   ===================================================================== */

require_once __DIR__ . '/db.php';

define('ROOM_PHOTO_BASE', __DIR__ . '/../assets/img/venues/rooms');
define('ROOM_PHOTO_WEB_BASE', '../assets/img/venues/rooms');   // relative from admin/ and customer/ (both one level deep)
define('ROOM_ID_RE', '/^[a-zA-Z0-9_-]{1,40}$/');
define('ROOM_PHOTO_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp']);
define('ROOM_PHOTO_MAX_BYTES', 8 * 1024 * 1024);   // 8MB per file
define('ROOM_PHOTO_MAX_COUNT', 5);                 // gallery photos per room (the 360° is separate, one only)

function rp_room_id_valid($id) {
  return is_string($id) && preg_match(ROOM_ID_RE, $id) === 1;
}

function rp_photos_dir($id) {
  return ROOM_PHOTO_BASE . '/' . $id . '/photos';
}

function rp_room_dir($id) {
  return ROOM_PHOTO_BASE . '/' . $id;
}

/* rooms.id (bigint PK) for a room_code like "r1", or null if the room
   doesn't exist in the DB (or the DB is unreachable). Cached per request
   — this gets called from render loops (room cards, listing pages). */
function rp_db_room_id($code) {
  static $cache = [];
  if (array_key_exists($code, $cache)) return $cache[$code];
  if (!rp_room_id_valid($code)) return $cache[$code] = null;
  $pdo = venusep_db();
  if ($pdo === null) return $cache[$code] = null;
  $stmt = $pdo->prepare('SELECT id FROM rooms WHERE room_code = :code');
  $stmt->execute([':code' => $code]);
  $id = $stmt->fetchColumn();
  return $cache[$code] = ($id !== false ? (int) $id : null);
}

/* Recursively create $dir under ROOM_PHOTO_BASE and force 0775 on every
   segment created. mkdir()'s own mode argument is masked by the running
   process's umask (typically 022 on Apache/PHP, which strips the group
   write bit) — that leaves a directory writable only by whichever OS
   user happened to create it first, silently breaking every later
   upload made by a different user (e.g. the web server vs. a CLI/test
   run). chmod()ing after the fact bypasses the umask entirely. */
function rp_mkdir($dir) {
  if (is_dir($dir)) return true;
  // $dir is always built from the ROOM_PHOTO_BASE constant (rp_photos_dir()
  // / rp_room_dir()), so it shares that exact literal prefix — comparing
  // against a realpath()'d (symlink-resolved, normalized) version of the
  // same constant would silently mismatch and skip every chmod below.
  if (!is_dir(ROOM_PHOTO_BASE)) { @mkdir(ROOM_PHOTO_BASE, 0775, true); @chmod(ROOM_PHOTO_BASE, 0775); }
  if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return false;
  $rel = trim(substr($dir, strlen(ROOM_PHOTO_BASE)), '/');
  $path = ROOM_PHOTO_BASE;
  foreach (explode('/', $rel) as $seg) {
    if ($seg === '') continue;
    $path .= '/' . $seg;
    @chmod($path, 0775);
  }
  return true;
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

/* Move a validated temp upload into place under $dest and return
   [mime, size, sha256] metadata for the room_media row, or null on
   failure (with $error set). */
function rp_store_file($tmpPath, $dest, &$error) {
  $moved = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
  if (!$moved) { $error = 'Could not save the uploaded file.'; return null; }
  @chmod($dest, 0644);
  $info = @getimagesize($dest);
  return [
    'mime' => $info !== false ? $info['mime'] : null,
    'size' => @filesize($dest) ?: null,
    'hash' => @hash_file('sha256', $dest) ?: null,
  ];
}

/* ---------------------------------------------------------------------
   Gallery photos — room_media rows with media_type = 'photo'
   --------------------------------------------------------------------- */

/* Ordered list of gallery photos for a room. Index 0 = cover. Each entry:
   ['file' => 'p_xxx.jpg', 'url' => '../assets/img/venues/rooms/r1/photos/p_xxx.jpg'] */
function rp_list_photos($code) {
  $roomId = rp_db_room_id($code);
  if ($roomId === null) return [];
  $pdo = venusep_db();
  if ($pdo === null) return [];
  $stmt = $pdo->prepare("SELECT file_path FROM room_media WHERE room_id = :r AND media_type = 'photo' ORDER BY display_order ASC, id ASC");
  $stmt->execute([':r' => $roomId]);
  $out = [];
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $url) {
    $out[] = ['file' => basename($url), 'url' => $url];
  }
  return $out;
}

/* Add one photo from a tmp upload path. Returns the new filename, or
   null + $error set. New photos are appended (not made cover) unless
   the gallery was empty. */
function rp_add_photo($code, $tmpPath, $originalName, $uploadedByUserId, &$error) {
  if (!rp_room_id_valid($code)) { $error = 'Invalid room.'; return null; }
  $roomId = rp_db_room_id($code);
  if ($roomId === null) { $error = 'This room was not found in the database.'; return null; }
  $pdo = venusep_db();
  if ($pdo === null) { $error = 'The database is unreachable, so the photo was not saved.'; return null; }
  if (count(rp_list_photos($code)) >= ROOM_PHOTO_MAX_COUNT) {
    $error = 'This room already has the maximum of ' . ROOM_PHOTO_MAX_COUNT . ' photos. Remove one before adding another.';
    return null;
  }
  $ext = rp_validate_image($tmpPath, $originalName, $error);
  if ($ext === null) return null;

  $dir = rp_photos_dir($code);
  if (!rp_mkdir($dir)) { $error = 'Could not create the upload folder.'; return null; }

  $filename = 'p_' . bin2hex(random_bytes(8)) . '.' . $ext;
  $dest = $dir . '/' . $filename;
  $meta = rp_store_file($tmpPath, $dest, $error);
  if ($meta === null) return null;

  $url = ROOM_PHOTO_WEB_BASE . '/' . $code . '/photos/' . $filename;
  try {
    $next = $pdo->prepare("SELECT COALESCE(MAX(display_order), 0) + 1 FROM room_media WHERE room_id = :r AND media_type = 'photo'");
    $next->execute([':r' => $roomId]);
    $order = (int) $next->fetchColumn();

    $ins = $pdo->prepare(
      "INSERT INTO room_media (room_id, media_type, file_path, original_filename, display_order, mime_type, file_size_bytes, sha256_hash, uploaded_by_user_id)
       VALUES (:room_id, 'photo', :path, :orig, :order, :mime, :size, :hash, :uid)"
    );
    $ins->execute([
      ':room_id' => $roomId, ':path' => $url, ':orig' => $originalName, ':order' => $order,
      ':mime' => $meta['mime'], ':size' => $meta['size'], ':hash' => $meta['hash'], ':uid' => $uploadedByUserId,
    ]);
  } catch (Throwable $e) {
    @unlink($dest);
    $error = 'Could not save the photo record.';
    return null;
  }

  return $filename;
}

function rp_delete_photo($code, $filename) {
  $roomId = rp_db_room_id($code);
  if ($roomId === null) return false;
  $pdo = venusep_db();
  if ($pdo === null) return false;

  $filename = basename($filename);   // no path traversal
  $url = ROOM_PHOTO_WEB_BASE . '/' . $code . '/photos/' . $filename;

  $pdo->prepare("DELETE FROM room_media WHERE room_id = :r AND media_type = 'photo' AND file_path = :p")
      ->execute([':r' => $roomId, ':p' => $url]);

  $path = rp_photos_dir($code) . '/' . $filename;
  if (is_file($path)) @unlink($path);
  return true;
}

/* Reorder the gallery to match $files (filenames in the new order).
   Index 0 becomes the cover. Unknown filenames are ignored. */
function rp_reorder_photos($code, array $files) {
  $roomId = rp_db_room_id($code);
  if ($roomId === null) return false;
  $pdo = venusep_db();
  if ($pdo === null) return false;

  $existing = array_column(rp_list_photos($code), 'file');
  $existingSet = array_flip($existing);
  $clean = [];
  foreach ($files as $f) {
    $f = basename($f);
    if (isset($existingSet[$f]) && !in_array($f, $clean, true)) $clean[] = $f;
  }
  // Anything missing from $files (shouldn't happen) stays, appended, so
  // nothing silently disappears from a partial reorder call.
  foreach ($existing as $f) if (!in_array($f, $clean, true)) $clean[] = $f;

  $upd = $pdo->prepare("UPDATE room_media SET display_order = :o WHERE room_id = :r AND media_type = 'photo' AND file_path = :p");
  $order = 1;
  foreach ($clean as $f) {
    $upd->execute([':o' => $order, ':r' => $roomId, ':p' => ROOM_PHOTO_WEB_BASE . '/' . $code . '/photos/' . $f]);
    $order++;
  }
  return true;
}

/* ---------------------------------------------------------------------
   360° panorama — one room_media row with media_type = 'panorama_360'
   --------------------------------------------------------------------- */

function rp_pano_url($code) {
  $roomId = rp_db_room_id($code);
  if ($roomId === null) return null;
  $pdo = venusep_db();
  if ($pdo === null) return null;
  $stmt = $pdo->prepare("SELECT file_path FROM room_media WHERE room_id = :r AND media_type = 'panorama_360' ORDER BY id DESC LIMIT 1");
  $stmt->execute([':r' => $roomId]);
  $path = $stmt->fetchColumn();
  return $path !== false ? $path : null;
}

function rp_save_pano($code, $tmpPath, $originalName, $uploadedByUserId, &$error) {
  if (!rp_room_id_valid($code)) { $error = 'Invalid room.'; return null; }
  $roomId = rp_db_room_id($code);
  if ($roomId === null) { $error = 'This room was not found in the database.'; return null; }
  $pdo = venusep_db();
  if ($pdo === null) { $error = 'The database is unreachable, so the photo was not saved.'; return null; }

  $ext = rp_validate_image($tmpPath, $originalName, $error);
  if ($ext === null) return null;

  $dir = rp_room_dir($code);
  if (!rp_mkdir($dir)) { $error = 'Could not create the upload folder.'; return null; }

  rp_delete_pano($code);   // only one panorama per room
  $dest = $dir . '/pano.' . $ext;
  $meta = rp_store_file($tmpPath, $dest, $error);
  if ($meta === null) return null;

  $url = ROOM_PHOTO_WEB_BASE . '/' . $code . '/pano.' . $ext;
  try {
    $ins = $pdo->prepare(
      "INSERT INTO room_media (room_id, media_type, file_path, original_filename, display_order, mime_type, file_size_bytes, sha256_hash, uploaded_by_user_id)
       VALUES (:room_id, 'panorama_360', :path, :orig, 1, :mime, :size, :hash, :uid)"
    );
    $ins->execute([
      ':room_id' => $roomId, ':path' => $url, ':orig' => $originalName,
      ':mime' => $meta['mime'], ':size' => $meta['size'], ':hash' => $meta['hash'], ':uid' => $uploadedByUserId,
    ]);
  } catch (Throwable $e) {
    @unlink($dest);
    $error = 'Could not save the 360° photo record.';
    return null;
  }

  return $url;
}

function rp_delete_pano($code) {
  $roomId = rp_db_room_id($code);
  if ($roomId === null) return false;
  $pdo = venusep_db();
  if ($pdo === null) return false;

  $stmt = $pdo->prepare("SELECT file_path FROM room_media WHERE room_id = :r AND media_type = 'panorama_360'");
  $stmt->execute([':r' => $roomId]);
  $prefix = ROOM_PHOTO_WEB_BASE . '/' . $code . '/';
  foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $url) {
    if (strpos($url, $prefix) === 0) {
      $local = rp_room_dir($code) . '/' . substr($url, strlen($prefix));
      if (is_file($local)) @unlink($local);
    }
  }
  $pdo->prepare("DELETE FROM room_media WHERE room_id = :r AND media_type = 'panorama_360'")->execute([':r' => $roomId]);
  return true;
}

/* ---------------------------------------------------------------------
   Customer-facing reads
   --------------------------------------------------------------------- */

/* All gallery photo URLs, in cover-first order. */
function rp_gallery_urls($code) {
  return array_column(rp_list_photos($code), 'url');
}

/* The single cover image for a room: the first uploaded gallery photo,
   falling back to the old assets/img/venues/<id>.<ext> convention (so
   any photo already dropped there manually keeps working), else null. */
function rp_cover_url($code) {
  $photos = rp_gallery_urls($code);
  if ($photos) return $photos[0];
  if (!rp_room_id_valid($code)) return null;
  foreach (ROOM_PHOTO_ALLOWED_EXT as $ext) {
    if (file_exists(__DIR__ . '/../assets/img/venues/' . $code . '.' . $ext)) {
      return '../assets/img/venues/' . $code . '.' . $ext;
    }
  }
  return null;
}
