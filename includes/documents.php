<?php
/* =====================================================================
   BOOKING DOCUMENTS — uploaded IDs, GCash receipts and Official Receipts.

   ⚠️ THESE ARE NOT ROOM PHOTOS. A room photo is meant to be seen by
   everyone and lives under assets/, where the web server hands it to
   anyone who asks for the URL. A customer's university ID carries their
   name, their ID number and their face; a GCash receipt carries a real
   name and an amount. Serving those the same way would mean anyone who
   learned or guessed a path could read a stranger's ID — no login, no
   trace.

   So these files live OUTSIDE THE WEB ROOT entirely. There is no URL that
   reaches them. The only way to see one is through a PHP script that
   checks the session first (admin/document-view.php), which can decide
   that staff may see any booking's ID while a customer may see only their
   own.

   The DEFAULT location is a sibling of htdocs, so a fresh XAMPP install
   works with no configuration:
       C:\xampp2\htdocs\VENUSeP\   <- the app
       C:\xampp2\venusep-private\  <- the documents
   Override with the VENUSEP_DOC_ROOT environment variable in production.

   Profile pictures are the opposite case and stay under assets/ —
   customers.photo_path / staff.photo_path hold a picture the person chose
   to show, and nothing else. The two must never be confused: an uploaded
   ID must not become an avatar, and an avatar is not identity evidence.
   ===================================================================== */

require_once __DIR__ . '/db.php';

define('DOC_MAX_BYTES', 8 * 1024 * 1024);                  // 8MB, same cap as room photos
define('DOC_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'webp', 'pdf']);

/* Where the private files live. Outside the web root by construction. */
function doc_root() {
    $env = getenv('VENUSEP_DOC_ROOT');
    if (is_string($env) && $env !== '') {
        return rtrim(str_replace('\\', '/', $env), '/');
    }
    /* __DIR__ is <web root>/VENUSeP/includes, so three levels up leaves
       htdocs behind entirely. */
    return dirname(dirname(dirname(__DIR__))) . '/venusep-private';
}

function doc_ensure_dir($sub) {
    $dir = doc_root() . '/' . $sub;
    if (is_dir($dir)) {
        return $dir;
    }
    if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return null;
    }
    @chmod($dir, 0700);
    return $dir;
}

/* Validate an upload: a real file, an allowed extension, under the cap, and
   — for images — actually an image rather than something renamed. Returns the
   lowercase extension, or null with $error set. */
function doc_validate($tmpPath, $originalName, &$error) {
    if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
        $error = 'Upload failed.';
        return null;
    }
    $size = filesize($tmpPath);
    if ($size === false || $size <= 0) {
        $error = 'The file is empty.';
        return null;
    }
    if ($size > DOC_MAX_BYTES) {
        $error = 'That file is larger than 8MB.';
        return null;
    }
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_ALLOWED_EXT, true)) {
        $error = 'Upload a JPG, PNG, WEBP or PDF.';
        return null;
    }
    if ($ext !== 'pdf') {
        $info = @getimagesize($tmpPath);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $error = 'That file is not a valid image.';
            return null;
        }
    }
    return $ext === 'jpeg' ? 'jpg' : $ext;
}

/* Store a file in the private area and return its path RELATIVE to doc_root(),
   without recording a booking_documents row.

   For files that already have a home of their own: a GCash receipt belongs in
   `gcash_receipts`, which carries its own file_path alongside the reference
   number, the parsed amount and the verdict. Forcing it into booking_documents
   as well would mean two rows describing one file, free to disagree about which
   one is current. The BYTES still live in the same private area — nothing
   sensitive is served from assets/ either way. */
function doc_store_raw($sub, $prefix, $tmpPath, $originalName, &$error) {
    $ext = doc_validate($tmpPath, $originalName, $error);
    if ($ext === null) {
        return null;
    }
    $dir = doc_ensure_dir($sub);
    if ($dir === null) {
        $error = 'Could not create the document folder.';
        return null;
    }
    $stored = $prefix . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest   = $dir . '/' . $stored;
    $moved  = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
    if (!$moved) {
        $error = 'Could not save the uploaded file.';
        return null;
    }
    @chmod($dest, 0600);
    return $sub . '/' . $stored;
}

/* Store one document against a booking and record it in booking_documents.
   Returns the row id, or null with $error set.

   The stored filename is random, not the customer's original. Two reasons:
   an original name can collide, and it can itself be sensitive
   ("juan-dela-cruz-drivers-licence.jpg" leaks in any directory listing or
   log line). The original is kept in a column for staff to see. */
function doc_store($bookingId, $type, $tmpPath, $originalName, $uploadedByUserId, &$error) {
    $allowed = ['customer_id', 'pos', 'transaction_receipt', 'official_receipt', 'refund_support', 'other'];
    if (!in_array($type, $allowed, true)) {
        $error = 'Unknown document type.';
        return null;
    }
    $ext = doc_validate($tmpPath, $originalName, $error);
    if ($ext === null) {
        return null;
    }
    $pdo = venusep_db();
    if ($pdo === null) {
        $error = 'The database is unreachable, so the document was not saved.';
        return null;
    }

    $sub = 'bookings/' . (int) $bookingId;
    $dir = doc_ensure_dir($sub);
    if ($dir === null) {
        $error = 'Could not create the document folder.';
        return null;
    }

    $stored = $type . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest   = $dir . '/' . $stored;
    $moved  = is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $dest) : copy($tmpPath, $dest);
    if (!$moved) {
        $error = 'Could not save the uploaded file.';
        return null;
    }
    @chmod($dest, 0600);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO booking_documents
                (booking_id, document_type, file_path, original_filename, stored_filename,
                 mime_type, file_size_bytes, sha256_hash, verification_status, uploaded_by_user_id)
             VALUES (:b, :t, :p, :o, :s, :m, :z, :h, \'pending\', :u)'
        );
        $stmt->execute([
            ':b' => (int) $bookingId,
            ':t' => $type,
            /* A path RELATIVE to doc_root(), never absolute: the column must not
               pin the install to one machine, and it must not be a URL. */
            ':p' => $sub . '/' . $stored,
            ':o' => mb_substr((string) $originalName, 0, 255),
            ':s' => $stored,
            ':m' => $ext === 'pdf' ? 'application/pdf' : (@getimagesize($dest)['mime'] ?: null),
            ':z' => filesize($dest) ?: null,
            ':h' => hash_file('sha256', $dest),
            ':u' => $uploadedByUserId ? (int) $uploadedByUserId : null,
        ]);
        return (int) $pdo->lastInsertId();
    } catch (PDOException $e) {
        @unlink($dest);                    // no row, no orphaned file
        $error = 'The document could not be recorded.';
        return null;
    }
}

/* The absolute path of a stored document, or null when it is missing or the
   recorded path tries to escape doc_root(). The realpath check is the guard
   that stops a tampered file_path from reading anything else on disk. */
function doc_path($filePath) {
    $root = realpath(doc_root());
    $full = realpath(doc_root() . '/' . $filePath);
    if ($root === false || $full === false) {
        return null;
    }
    return strpos($full, $root) === 0 ? $full : null;
}
