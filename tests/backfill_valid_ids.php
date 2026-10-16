<?php
/* =====================================================================
   BACKFILL — the missing uploaded valid-ID images.

   Run:  php tests/backfill_valid_ids.php

   WHY THIS IS NEEDED. Same shape of gap as tests/backfill_gcash_receipts.php
   and tests/backfill_system_receipts.php, a third table: a valid ID is
   required for EVERY booking (PROJECT-HANDOFF 4.7, enforced in
   customer/booking-submit.php), but venusep_demo_seed.sql's seed procedures
   insert `bookings` rows directly and never call doc_store() the way a real
   submission does — so almost every demo booking has no ID image behind it
   at all, and "View" in Transaction History (valid-id-view.php) has nothing
   to show for it.

   This writes a real placeholder image per booking missing one (via
   doc_store(), the exact function a real upload uses, with document_type
   'customer_id' — so the path convention, directory layout, the
   booking_documents row shape and permissions all match a genuine upload)
   so "View" always has something to open.

   PERMISSIONS — see tests/backfill_gcash_receipts.php's header for the full
   explanation: a file this script creates over the CLI is owned by
   whichever user ran `php`, not by Apache, so it needs widening to
   world-readable or Apache can never read its own document root back. A
   real upload needs none of this — Apache already owns what it writes.

   SAFE TO RE-RUN. Only processes bookings with no 'customer_id' document
   row yet, so running it again, or after new real IDs exist, only fills in
   what is actually missing.
   ===================================================================== */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/documents.php';

$pdo = venusep_db();
if ($pdo === null) {
    fwrite(STDERR, "Could not reach the database.\n");
    exit(1);
}
if (!extension_loaded('gd')) {
    fwrite(STDERR, "The gd extension is required to draw the placeholder images.\n");
    exit(1);
}

$rows = $pdo->query(
    "SELECT b.id AS booking_id, b.is_usep_affiliated, c.full_name, c.university_id_no
       FROM bookings b
       JOIN customers c ON c.id = b.customer_id
      WHERE NOT EXISTS (
          SELECT 1 FROM booking_documents d
           WHERE d.booking_id = b.id AND d.document_type = 'customer_id'
      )
      ORDER BY b.id"
)->fetchAll();

if (!$rows) {
    echo "Nothing to backfill — every booking already has an ID image.\n";
    exit(0);
}

/* See the file header: this run owns whatever it creates under doc_root(),
   including the shared 'bookings' parent the first booking's folder sits
   in — widen it too, once, or Apache can never even traverse INTO the
   per-booking folders below, regardless of their own permissions. */
@mkdir(doc_root() . '/bookings', 0711, true);
@chmod(doc_root() . '/bookings', 0711);

function bfid_placeholder_jpeg($tmpPath, $lines, $bg) {
    $w = 760; $h = 480;   // landscape, like a photographed ID card
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $bg[0], $bg[1], $bg[2]));
    $white = imagecolorallocate($im, 255, 255, 255);
    $border = imagecolorallocate($im, 255, 255, 255);
    imagerectangle($im, 14, 14, $w - 15, $h - 15, $border);
    $y = 120;
    foreach ($lines as $line) {
        $fw = imagefontwidth(5) * strlen($line);
        imagestring($im, 5, (int) (($w - $fw) / 2), $y, $line, $white);
        $y += 28;
    }
    imagejpeg($im, $tmpPath, 85);
    imagedestroy($im);
}

$made = 0;
$failed = 0;
foreach ($rows as $row) {
    try {
        $usep = (bool) $row['is_usep_affiliated'];
        $idNo = trim((string) $row['university_id_no']);
        $tmp = tempnam(sys_get_temp_dir(), 'bfid');
        bfid_placeholder_jpeg($tmp, [
            $usep ? 'University of Southeastern Philippines' : 'Government-Issued ID',
            '', 'SEED / DEMO DATA',
            'No real ID photo was uploaded for', 'this booking - generated so the',
            'View button has something to show.',
            '', (string) $row['full_name'],
            $usep && $idNo !== '' ? 'ID No. ' . $idNo : '',
        ], $usep ? [122, 28, 38] : [58, 58, 64]);

        $error = '';
        $docId = doc_store((int) $row['booking_id'], 'customer_id', $tmp, 'valid_id.jpg', null, $error);
        @unlink($tmp);
        if ($docId === null) {
            throw new RuntimeException($error ?: 'doc_store failed');
        }

        /* Widen the per-booking folder the same way the file itself needs
           it (doc_store() already left the FILE at a CLI-owned 0600). */
        $stored = $pdo->prepare('SELECT file_path FROM booking_documents WHERE id = :i');
        $stored->execute([':i' => $docId]);
        $full = doc_path((string) $stored->fetchColumn());
        if ($full !== null) {
            @chmod(dirname($full), 0755);
            @chmod($full, 0644);
        }

        $made++;
        echo "booking {$row['booking_id']}: document {$docId}\n";
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAILED booking {$row['booking_id']}: " . $e->getMessage() . "\n");
    }
}

echo "\n{$made} ID image(s) created, {$failed} failed, " . count($rows) . " booking(s) checked.\n";
exit($failed > 0 ? 1 : 0);
