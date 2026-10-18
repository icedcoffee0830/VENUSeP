<?php
/* =====================================================================
   BACKFILL — the missing uploaded GCash receipt images.

   Run:  php tests/backfill_gcash_receipts.php

   WHY THIS IS NEEDED. Same shape of gap as tests/backfill_system_receipts.php,
   a different table: venusep_demo_seed.sql's sp_seed_payment() inserts
   `payments` rows directly, never through customer/payment-submit.php — the
   ONLY real path that also writes a `gcash_receipts` row (the uploaded
   screenshot + its OCR verdict). So almost every demo booking that shows as
   paid by GCash has no screenshot behind it at all, and the "View" button in
   Transaction History (admin/receipt & customer sides — document-view.php
   ?receipt=) stays disabled for all of them.

   This writes a real placeholder JPEG per missing payment (via doc_store_raw,
   the exact function a real upload uses — so the path convention, directory
   layout and permissions all match a genuine upload) and a matching
   gcash_receipts row, so "View" has something to open for every booking.

   PERMISSIONS, READ THIS IF A PLACEHOLDER STILL 404s. doc_store_raw() chmods
   the file 0600 — fine for a REAL upload, because Apache is the one writing
   it and therefore already owns it. This script runs over the CLI as
   whichever user invokes `php`, not as Apache, so the file it creates is
   owned by THAT user instead — and 0600 then means Apache can no longer
   read its own document root. This script chmods its own output
   world-readable (0644 on the file, 0755 on the booking folder) to correct
   for that CLI/webserver ownership mismatch. A real upload needs no such
   fix — it is already owned by the process that has to read it back.

   SAFE TO RE-RUN. Only processes GCash payments with no gcash_receipts row
   yet (a LEFT JOIN, not "every payment"), so running it twice, or after new
   real receipts exist, only fills in what is actually missing.
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

/* Every GCash payment with no gcash_receipts row yet, plus what the
   placeholder needs: the booking's venue (for a believable receiver name/
   number) and room name (for the label). */
$rows = $pdo->query(
    "SELECT p.id AS payment_id, p.booking_id, p.amount, p.payment_record_status,
            COALESCE(p.confirmed_at, p.paid_at, p.created_at) AS receipt_when,
            r.name AS room_name, v.id AS venue_id, v.name AS venue_name
       FROM payments p
       JOIN bookings b ON b.id = p.booking_id
       JOIN rooms r    ON r.id = b.room_id
       JOIN venues v   ON v.id = r.venue_id
       LEFT JOIN gcash_receipts gr ON gr.payment_id = p.id
      WHERE p.payment_method = 'gcash' AND gr.id IS NULL
      ORDER BY p.id"
)->fetchAll();

if (!$rows) {
    echo "Nothing to backfill — every GCash payment already has a receipt image.\n";
    exit(0);
}

/* See the file header's PERMISSIONS note: this CLI run owns whatever it
   creates under doc_root(), including the shared 'bookings' parent the
   first booking's folder sits in — widen it too, once, or Apache can never
   even traverse INTO the per-booking folders below, regardless of their
   own permissions. 0777, not 0711: 0711 shuts Apache (daemon, neither the
   owner nor in this CLI user's group) out of WRITING a new per-booking
   folder of its own, so the next real upload into a booking this script
   hasn't touched yet would silently fail to save. */
@mkdir(doc_root() . '/bookings', 0777, true);
@chmod(doc_root() . '/bookings', 0777);
@chmod(doc_root(), 0777);

/* One GCash account per venue (admin Payment Settings) — the receiver a real
   screenshot would show. Falls back to a generic label if a venue somehow
   has none active. */
$accounts = [];
foreach ($pdo->query('SELECT venue_id, account_name, mobile_number FROM gcash_accounts WHERE is_active = 1 AND valid_until IS NULL') as $a) {
    $accounts[(int) $a['venue_id']] = $a;
}

function bf_placeholder_jpeg($tmpPath, $lines, $bg) {
    $w = 720; $h = 960;
    $im = imagecreatetruecolor($w, $h);
    imagefill($im, 0, 0, imagecolorallocate($im, $bg[0], $bg[1], $bg[2]));
    $white = imagecolorallocate($im, 255, 255, 255);
    $y = 300;
    foreach ($lines as $line) {
        $fw = imagefontwidth(5) * strlen($line);
        imagestring($im, 5, (int) (($w - $fw) / 2), $y, $line, $white);
        $y += 26;
    }
    imagejpeg($im, $tmpPath, 85);
    imagedestroy($im);
}

/* A 13-digit numeric reference, the shape a real GCash one has, retried
   until it clears the table's UNIQUE constraint. */
function bf_unique_reference($pdo) {
    $stmt = $pdo->prepare('SELECT 1 FROM gcash_receipts WHERE reference_number = :r');
    for ($i = 0; $i < 50; $i++) {
        $ref = (string) random_int(1000000000000, 9999999999999);
        $stmt->execute([':r' => $ref]);
        if ($stmt->fetchColumn() === false) {
            return $ref;
        }
    }
    throw new RuntimeException('Could not mint a unique reference number.');
}

$verdictFor = function ($status) {
    if ($status === 'confirmed' || $status === 'refunded') return 'accepted';
    return 'manual_review';   // submitted, under_review
};

$made = 0;
$failed = 0;
foreach ($rows as $row) {
    try {
        $acct = $accounts[(int) $row['venue_id']] ?? ['account_name' => 'VENUSeP ' . $row['venue_name'], 'mobile_number' => '09000000000'];
        $amount = (float) $row['amount'];
        $ref = bf_unique_reference($pdo);
        $verdict = $verdictFor($row['payment_record_status']);

        $tmp = tempnam(sys_get_temp_dir(), 'bfgc');
        bf_placeholder_jpeg($tmp, [
            'GCash', 'Receipt Sent',
            '', 'SEED / DEMO DATA',
            'No real receipt was', 'uploaded for this', 'payment - generated so',
            'the View button has', 'something to show.',
            '', 'Ref ' . $ref,
            'PHP ' . number_format($amount, 2),
            (string) $row['room_name'],
        ], $verdict === 'accepted' ? [28, 122, 79] : [138, 100, 18]);

        $error = '';
        $path = doc_store_raw('bookings/' . (int) $row['booking_id'], 'gcash_receipt', $tmp, 'receipt.jpg', $error);
        @unlink($tmp);
        if ($path === null) {
            throw new RuntimeException($error ?: 'doc_store_raw failed');
        }
        $full = doc_path($path);
        /* See the file header: a CLI-created file is owned by this CLI
           user, not Apache — widen read access so the webserver can still
           open what it will later need to serve. */
        @chmod(dirname($full), 0755);
        @chmod($full, 0644);
        $sha = hash_file('sha256', $full);

        $pdo->prepare(
            'INSERT INTO gcash_receipts
                (payment_id, booking_id, attempt_no, file_path, original_filename, reference_number,
                 receiver_name, receiver_number, amount_centavos, receipt_datetime,
                 sha256_hash, ocr_confidence, verdict, flags_json, ocr_text, created_at)
             VALUES (:p, :b, 1, :f, :o, :r, :rn, :rnum, :amt, :when, :sha, :conf, :v, NULL, :txt, :created)'
        )->execute([
            ':p' => $row['payment_id'], ':b' => $row['booking_id'], ':f' => $path, ':o' => 'receipt.jpg',
            ':r' => $ref, ':rn' => $acct['account_name'], ':rnum' => $acct['mobile_number'],
            ':amt' => (int) round($amount * 100), ':when' => $row['receipt_when'], ':created' => $row['receipt_when'],
            ':sha' => $sha, ':conf' => $verdict === 'accepted' ? 96.00 : 71.50, ':v' => $verdict,
            ':txt' => 'GCash you sent PHP ' . number_format($amount, 2) . ' to ' . $acct['account_name'] . ' Ref No. ' . $ref,
        ]);
        $made++;
        echo "booking {$row['booking_id']} (payment {$row['payment_id']}): {$path}\n";
    } catch (\Throwable $e) {
        $failed++;
        fwrite(STDERR, "FAILED payment {$row['payment_id']}: " . $e->getMessage() . "\n");
    }
}

echo "\n{$made} receipt image(s) created, {$failed} failed, " . count($rows) . " payment(s) checked.\n";
exit($failed > 0 ? 1 : 0);
